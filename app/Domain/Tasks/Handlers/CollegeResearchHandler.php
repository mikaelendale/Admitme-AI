<?php

namespace App\Domain\Tasks\Handlers;

use App\Ai\Agents\CollegeResearchAgent;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Tasks\Delegation\Handoff;
use App\Domain\Tasks\TaskContext;
use App\Domain\Tasks\TaskHandler;
use App\Domain\Tasks\TaskOutcome;
use App\Models\AgentTask;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Services\CollegeList\CollegeListAssemblyService;
use Illuminate\Support\Str;

/**
 * One budgeted AI research call for the authorized student; results become reviewable suggestions (the
 * task's artifacts). Colleges already on the list, already suggested or previously dismissed are skipped.
 */
final class CollegeResearchHandler implements TaskHandler
{
    public function __construct(private readonly CollegeListAssemblyService $assembly) {}

    public static function type(): string
    {
        return 'college_research';
    }

    public static function describe(): string
    {
        return 'Suggest new candidate colleges that fit the student (one AI research call). Inputs: criteria (what to look for, e.g. "economics, strong aid for internationals, US or UK"), count (1–8, default 5). Results are suggestions the student reviews, never saved list entries.';
    }

    public static function rules(): array
    {
        return [
            'criteria' => ['nullable', 'string', 'max:500'],
            'count' => ['nullable', 'integer', 'min:1', 'max:8'],
        ];
    }

    public static function budget(): array
    {
        return ['max_model_calls' => 1, 'max_attempts' => 2];
    }

    public static function defaultTitle(array $input): string
    {
        return 'Find candidate colleges';
    }

    public function handle(AgentTask $task, TaskContext $context): TaskOutcome
    {
        $student = $context->student;
        $count = (int) ($task->input['count'] ?? 5);
        $existing = CollegeListSchool::query()->where('user_id', $student->getKey())->get(['school_name', 'school_slug', 'choice_status'])->keyBy('school_slug');

        // The specialist gets a server-built envelope for this student only — not the chat, not other students.
        $brief = $this->brief($student);
        $evidence = array_map(fn (string $line, int $i) => ['ref' => 'P'.($i + 1), 'text' => $line, 'source' => 'student profile'], $brief, array_keys($brief));
        $evidence[] = ['ref' => 'L1', 'text' => 'Already on their list or decided (do not suggest): '.($existing->isEmpty() ? 'none' : $existing->pluck('school_name')->implode('; ')).'.', 'source' => 'college list'];

        $handoff = new Handoff(
            taskId: (string) $task->getKey(),
            studentId: (int) $student->getKey(),
            specialist: class_basename(CollegeResearchAgent::class),
            goal: "Suggest up to {$count} colleges for this student. What they asked for: ".Str::limit((string) ($task->input['criteria'] ?? 'colleges that fit my profile and budget'), 500),
            profileRevision: $context->currentProfileRevision(),
            evidence: $evidence,
            contract: 'college_candidates',
            budget: ['max_model_calls' => (int) ($task->budget['max_model_calls'] ?? 1)],
        );

        $response = $context->modelCall(CollegeResearchAgent::class, fn () => CollegeResearchAgent::make()->prompt($handoff->prompt()), $handoff);
        $context->checkpoint();

        $suggested = [];
        $skipped = [];

        foreach (array_slice((array) ($response['candidates'] ?? []), 0, $count) as $candidate) {
            $name = is_array($candidate) ? trim((string) ($candidate['school_name'] ?? '')) : '';
            $slug = Str::slug($name);

            if ($name === '' || $slug === '') {
                continue;
            }

            if (($row = $existing->get($slug)) !== null) {
                $skipped[$name] = match ($row->choice_status) {
                    CollegeChoiceStatus::Archived => 'you dismissed it before',
                    CollegeChoiceStatus::Suggested => 'already suggested',
                    default => 'already on your list',
                };

                continue;
            }

            $this->assembly->upsertSchoolRow($student, [
                'school_name' => $name,
                'tier' => $candidate['tier'] ?? 'match',
                'fit_score' => $candidate['fit_score'] ?? 50,
                'why_blurb' => (string) ($candidate['why'] ?? ''),
                'notable_programs' => $candidate['notable_programs'] ?? [],
                'location_state' => $candidate['location_state'] ?? null,
                'aid_estimate_band' => $candidate['aid_estimate_band'] ?? null,
                'median_gpa' => $candidate['median_gpa'] ?? null,
            ], now(), null, (string) $task->getKey());

            $existing->put($slug, new CollegeListSchool(['school_name' => $name, 'choice_status' => CollegeChoiceStatus::Suggested]));
            $suggested[] = $name;
        }

        if ($suggested === []) {
            return TaskOutcome::completed('No new colleges to suggest'.($skipped !== [] ? ' (everything found was already on your list or dismissed).' : '.'), ['suggested' => [], 'skipped' => $skipped]);
        }

        $context->event('artifact', 'Saved '.count($suggested).' suggestion(s) for your review: '.implode(', ', $suggested).'.', ['suggested' => $suggested]);

        return TaskOutcome::completed('Suggested '.count($suggested).' college(s) for your review: '.implode(', ', $suggested).'. These are AI research estimates until checked against each college\'s own pages.', [
            'suggested' => $suggested,
            'skipped' => $skipped,
        ]);
    }

    /**
     * @return list<string>
     */
    private function brief(User $student): array
    {
        $p = OnboardingProfile::query()->where('user_id', $student->getKey())->first();

        if ($p === null) {
            return ['No profile on file.'];
        }

        $list = fn ($value) => is_array($value) && $value !== [] ? implode(', ', $value) : 'not given';

        return [
            'Intended majors: '.$list($p->intended_majors_json),
            'Destination countries: '.$list($p->target_countries_json),
            'Lives in: '.($p->location_country ?: 'not given').'; citizenship: '.($p->citizenship_residency ?: 'not given'),
            'Curriculum: '.($p->curriculum ?: 'not given').($p->gpa_value !== null ? "; GPA {$p->gpa_value} of {$p->gpa_scale}" : ''),
            'Tests: '.(implode(', ', array_filter([$p->sat_score ? "SAT {$p->sat_score}" : null, $p->act_score ? "ACT {$p->act_score}" : null])) ?: 'none reported'),
            'Yearly budget: '.($p->budget_amount !== null ? "{$p->budget_currency} ".number_format((int) $p->budget_amount) : 'not given').'; aid need: '.($p->financial_aid_need_percent !== null ? "{$p->financial_aid_need_percent}% of costs" : 'not given'),
            'Target intake: '.($p->target_intake ?: 'not given'),
        ];
    }
}
