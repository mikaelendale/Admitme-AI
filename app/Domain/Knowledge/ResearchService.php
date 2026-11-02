<?php

namespace App\Domain\Knowledge;

use App\Ai\Agents\ResearcherAgent;
use App\Ai\AiModelResolver;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\ResearchAnswer;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Answers a student's question about a college from labelled evidence gathered server-side for that
 * student: the structured requirements and source passages they can see (shared sources plus their own
 * private ones). The answer is stored with its evidence so every claim can be inspected; claims citing
 * nothing real are marked unresolved.
 */
final class ResearchService
{
    public function __construct(
        private readonly InstitutionResolver $institutions,
        private readonly KnowledgeSearch $search,
        private readonly RequirementOverview $overview,
        private readonly RequirementConflicts $conflicts,
    ) {}

    public function answer(User $student, CollegeListSchool $school, string $question): ResearchAnswer
    {
        $institution = $this->institutions->findForSchool($school);
        $cycle = OnboardingProfile::query()->where('user_id', $student->getKey())->value('target_intake') ?: $school->admissions_cycle_label;
        $evidence = [];
        $lines = [];

        if ($institution !== null) {
            $requirements = $this->overview->visibleRequirements($institution, $student);
            $conflictIds = RequirementConflicts::ids($this->conflicts->groups($requirements));

            foreach ($requirements->values() as $index => $requirement) {
                $label = 'R'.($index + 1);
                $conflict = in_array($requirement->id, $conflictIds, true);
                $flags = array_filter([
                    $conflict ? 'CONFLICT' : null,
                    $requirement->source?->isStale() ? 'STALE' : null,
                ]);

                $lines[] = "[{$label}] ".implode(' | ', array_filter([
                    $requirement->label,
                    $this->overview->value($requirement),
                    $requirement->conditions ? "conditions: {$requirement->conditions}" : null,
                    $requirement->applicant_scope !== 'all' ? "applies to: {$requirement->applicant_scope} applicants" : null,
                    'cycle: '.($requirement->cycle_label ?? 'not stated'),
                    'source: '.$this->overview->sourceLabel($requirement->source),
                    $flags !== [] ? implode(', ', $flags) : null,
                ]));

                $evidence[$label] = [
                    'kind' => 'requirement',
                    'id' => $requirement->id,
                    'url' => $requirement->source?->url,
                    'title' => $requirement->source?->title,
                    'sourceType' => $requirement->source?->source_type,
                    'private' => (bool) $requirement->source?->isPrivate(),
                    'quote' => $requirement->quote,
                    'cycle' => $requirement->cycle_label,
                    'conflict' => $conflict,
                    'stale' => (bool) $requirement->source?->isStale(),
                    'checkedAt' => $requirement->source?->checked_at?->toIso8601String(),
                ];
            }

            foreach ($this->search->institutionPassages($institution, $question.' '.$school->school_name, $student)->values() as $index => $hit) {
                $label = 'S'.($index + 1);
                $source = $hit['passage']->version->source;
                $lines[] = "[{$label}] (from {$source->title}, ".$this->overview->sourceLabel($source).($source->isStale() ? ', STALE' : '').")\n".Str::limit($hit['passage']->text, 1200, '…');

                $evidence[$label] = [
                    'kind' => 'source',
                    'id' => $hit['passage']->id,
                    'url' => $source->url,
                    'title' => $source->title,
                    'sourceType' => $source->source_type,
                    'private' => $source->isPrivate(),
                    'quote' => Str::limit($hit['passage']->text, 400, '…'),
                    'stale' => $source->isStale(),
                    'checkedAt' => $source->checked_at?->toIso8601String(),
                ];
            }
        }

        if ($evidence === []) {
            return $this->store($student, $school, $institution?->id, $question, $cycle,
                "There are no sources on file for {$school->school_name} yet, so this can't be answered with evidence. Add the college's official admissions page and ask again.",
                [], [$question], [], null);
        }

        $prompt = "Student's question about {$school->school_name}: {$question}\n"
            .'Student\'s target cycle: '.($cycle ?: 'not stated')."\n\nEvidence:\n".implode("\n\n", $lines);

        $response = ResearcherAgent::make()->prompt($prompt);

        [$claims, $unresolved] = $this->validated((array) ($response['claims'] ?? []), (array) ($response['unresolved'] ?? []), $evidence);

        return $this->store($student, $school, $institution?->id, $question, $cycle, (string) ($response['answer'] ?? ''), $claims, $unresolved, $evidence, app(AiModelResolver::class)->modelFor(ResearcherAgent::class));
    }

    /**
     * @param  list<mixed>  $claims
     * @param  list<mixed>  $unresolved
     * @param  array<string, array<string, mixed>>  $evidence
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function validated(array $claims, array $unresolved, array $evidence): array
    {
        $cleanClaims = [];
        $cleanUnresolved = array_values(array_filter(array_map(fn ($item) => is_string($item) ? trim($item) : null, $unresolved)));

        foreach ($claims as $claim) {
            if (! is_array($claim) || ! is_string($claim['text'] ?? null) || trim($claim['text']) === '') {
                continue;
            }

            $citations = array_values(array_filter((array) ($claim['citations'] ?? []), fn ($label) => is_string($label) && isset($evidence[trim($label, '[] ')])));
            $citations = array_map(fn (string $label) => trim($label, '[] '), $citations);
            $status = in_array($claim['status'] ?? null, ['supported', 'unresolved', 'conflicting'], true) ? $claim['status'] : 'unresolved';

            // A claim citing no real evidence is not supported, whatever the model said...
            if ($citations === []) {
                $status = 'unresolved';
            } elseif (collect($citations)->contains(fn (string $label) => $evidence[$label]['conflict'] ?? false)) {
                $status = 'conflicting';
            }

            $cleanClaims[] = [
                'text' => trim($claim['text']),
                'citations' => $citations,
                'status' => $status,
                'cycle_label' => is_string($claim['cycle_label'] ?? null) ? $claim['cycle_label'] : null,
            ];
        }

        return [$cleanClaims, $cleanUnresolved];
    }

    /**
     * @param  list<array<string, mixed>>  $claims
     * @param  list<string>  $unresolved
     * @param  array<string, array<string, mixed>>  $evidence
     */
    private function store(User $student, CollegeListSchool $school, ?int $institutionId, string $question, ?string $cycle, string $answer, array $claims, array $unresolved, array $evidence, ?string $model): ResearchAnswer
    {
        return ResearchAnswer::query()->create([
            'user_id' => $student->getKey(),
            'institution_id' => $institutionId,
            'college_list_school_id' => $school->id,
            'question' => Str::limit($question, 2000, '…'),
            'answer' => $answer,
            'claims' => $claims,
            'unresolved' => $unresolved,
            'evidence' => $evidence,
            'cycle_label' => $cycle,
            'model' => $model,
        ]);
    }
}
