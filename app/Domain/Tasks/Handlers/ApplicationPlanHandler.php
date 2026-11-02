<?php

namespace App\Domain\Tasks\Handlers;

use App\Ai\Agents\ApplicationPlannerAgent;
use App\Ai\AiModelResolver;
use App\Domain\Applications\ChecklistStatus;
use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\RequirementOverview;
use App\Domain\Tasks\Delegation\Handoff;
use App\Domain\Tasks\TaskContext;
use App\Domain\Tasks\TaskHandler;
use App\Domain\Tasks\TaskOutcome;
use App\Models\AgentTask;
use App\Models\Application;
use App\Models\ApplicationFormPlan;
use App\Models\ApplicationItem;
use App\Models\OnboardingProfile;
use App\Models\WritingPiece;
use App\Models\WritingVersion;
use Throwable;

/**
 * Plans how to fill in a typical first-year application, against a synthetic form snapshot. Each entry says
 * what it rests on: copied from the profile, the student's writing or the checklist; a deterministic check;
 * an AI judgment; or an AI suggestion the student must review. Nothing is filled in here: the browser
 * extension may later type the literal profile values, field by field, after the student approves them.
 */
final class ApplicationPlanHandler implements TaskHandler
{
    public const FORM = 'synthetic-first-year-2026';

    private const PERSONAL_STATEMENT_LIMIT = 650;

    public function __construct(
        private readonly InstitutionResolver $institutions,
        private readonly RequirementOverview $overview,
    ) {}

    public static function type(): string
    {
        return 'application_plan';
    }

    public static function describe(): string
    {
        return 'Prepare a field-by-field plan for filling in one application (practice form snapshot, not the live portal): values copied from saved data, checks, and a few labelled AI judgments. The student\'s browser extension can later type the plain profile values, after approval. Inputs: application_id.';
    }

    public static function rules(): array
    {
        return ['application_id' => ['required', 'string', 'size:26']];
    }

    public static function budget(): array
    {
        return ['max_model_calls' => 1, 'max_attempts' => 2];
    }

    public static function defaultTitle(array $input): string
    {
        return 'Plan the application form';
    }

    /**
     * The form snapshot the plan is made against. Fields marked "fill" hold a literal answer (a name, a
     * country) that the browser extension may type after approval; the rest are summaries or judgments.
     *
     * @return array{key: string, label: string, note: string, sections: list<array{title: string, fields: list<array<string, mixed>>}>}
     */
    public static function formDefinition(): array
    {
        return json_decode((string) file_get_contents(resource_path('forms/synthetic-first-year-application.json')), true);
    }

    public function handle(AgentTask $task, TaskContext $context): TaskOutcome
    {
        $student = $context->student;

        // The step's scope is its student: an application id from anyone else is refused, whatever the plan said.
        $application = Application::query()->where('user_id', $student->getKey())->with('collegeListSchool', 'items')->find($task->input['application_id'] ?? null);

        if ($application === null) {
            return TaskOutcome::failed('That application is not one of yours.');
        }

        $form = self::formDefinition();
        $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->first();
        $school = $application->collegeListSchool;
        $institution = $this->institutions->findForSchool($school);
        $requirements = $institution !== null ? $this->overview->visibleRequirements($institution, $student) : collect();
        $activities = array_values(array_filter((array) ($profile?->activities_extracted_json ?? []), fn ($a) => is_array($a) || is_string($a)));

        $judgments = $this->judgments($task, $context, $profile, $requirements, $activities, $school->school_name);
        $entries = [];

        foreach ($form['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $entries[] = ['section' => $section['title'], 'field' => $field['label'], 'required' => (bool) $field['required'], ...$this->entry($field, $student, $profile, $application, $activities, $judgments)];
            }
        }

        $plan = ApplicationFormPlan::query()->create([
            'application_id' => $application->getKey(),
            'user_id' => $student->getKey(),
            'agent_task_id' => (string) $task->getKey(),
            'form_key' => self::FORM,
            'entries' => $entries,
            'profile_revision' => $context->currentProfileRevision(),
            'model' => $judgments['model'] ?? null,
        ]);

        $counts = array_count_values(array_column($entries, 'basis'));
        $missing = count(array_filter($entries, fn (array $e) => $e['required'] && $e['basis'] === 'validation' && ($e['value'] ?? null) === null));

        return TaskOutcome::completed(sprintf(
            'Planned %d fields for %s: %d from your saved data, %d need your input or a fix, %d AI judgment(s), %d suggestion(s) to review.',
            count($entries),
            $school->school_name,
            ($counts['profile'] ?? 0) + ($counts['writing'] ?? 0) + ($counts['checklist'] ?? 0),
            $missing,
            $counts['model_judgment'] ?? 0,
            $counts['suggestion'] ?? 0,
        ), ['plan_id' => (string) $plan->getKey(), 'application_id' => (string) $application->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $activities
     * @param  array<string, mixed>  $judgments
     * @return array{value: ?string, basis: string, note: ?string}
     */
    private function entry(array $field, $student, ?OnboardingProfile $p, Application $application, array $activities, array $judgments): array
    {
        $missing = fn (string $what = 'your profile') => ['value' => null, 'basis' => 'validation', 'note' => $field['required'] ? "Required — not in {$what} yet; enter it in the portal or add it to {$what}." : 'Optional — nothing on file.'];
        $from = fn (?string $value, string $basis = 'profile', ?string $note = null) => $value !== null && trim($value) !== '' ? ['value' => $value, 'basis' => $basis, 'note' => $note] : $missing();

        return match ($field['source']) {
            'user.name' => $from($student->name),
            'user.email' => $from($student->email),
            'profile.location_country' => $from($p?->location_country),
            'profile.citizenship_residency' => $from($p?->citizenship_residency),
            'profile.current_grade' => $from($p?->current_grade),
            'profile.graduation_year' => $p?->graduation_year !== null && (int) $p->graduation_year < (int) now()->format('Y')
                ? ['value' => (string) $p->graduation_year, 'basis' => 'validation', 'note' => 'Check: this year is in the past — is it right?']
                : $from($p?->graduation_year !== null ? (string) $p->graduation_year : null),
            'profile.curriculum' => $from($p?->curriculum),
            'profile.gpa' => $p?->gpa_value !== null && $p->gpa_scale === null
                ? ['value' => (string) $p->gpa_value, 'basis' => 'validation', 'note' => 'Check: add the grading scale — a GPA without its scale is ambiguous.']
                : $from($p?->gpa_value !== null ? "{$p->gpa_value} on a {$p->gpa_scale} scale" : null),
            'profile.sat_score' => $from($p?->sat_score !== null ? (string) $p->sat_score : null),
            'profile.act_score' => $from($p?->act_score !== null ? (string) $p->act_score : null),
            'profile.target_intake' => $from($p?->target_intake),
            'profile.intended_majors' => $from(is_array($p?->intended_majors_json) && $p->intended_majors_json !== [] ? implode(', ', $p->intended_majors_json) : null),
            'profile.activities' => $activities === []
                ? $missing()
                : ['value' => count($activities).' on file', 'basis' => count($activities) > 10 ? 'validation' : 'profile', 'note' => count($activities) > 10 ? 'Check: forms usually allow 10 — choose which to leave out.' : null],
            'checklist.english_test' => $this->checklistStatus($application, 'english_test'),
            'checklist.round' => $this->round($application),
            'writing.personal_statement' => $this->writing($student, null, 'personal_statement'),
            'writing.supplements' => $this->writing($student, $application, 'supplement'),
            'judgment.test_plan', 'judgment.activity_order' => isset($judgments[$field['key']])
                ? ['value' => $judgments[$field['key']]['judgment'], 'basis' => 'model_judgment', 'note' => 'AI judgment — weigh it yourself.'.($judgments[$field['key']]['based_on'] !== [] ? ' Based on: '.implode(', ', $judgments[$field['key']]['based_on']).'.' : '')]
                : ['value' => null, 'basis' => 'validation', 'note' => 'Not judged — decide this yourself.'],
            'suggestion.additional_info' => isset($judgments['additional_info'])
                ? ['value' => $judgments['additional_info'], 'basis' => 'suggestion', 'note' => 'Suggestion only — not filled in. Use it only if it is true and you rewrite it in your own words.']
                : ['value' => null, 'basis' => 'validation', 'note' => 'Optional — nothing suggests you need it.'],
            default => ['value' => null, 'basis' => 'validation', 'note' => 'Not mapped yet.'],
        };
    }

    /**
     * One budgeted call for the judgment fields, through a handoff with only this student's evidence.
     *
     * @return array<string, mixed>
     */
    private function judgments(AgentTask $task, TaskContext $context, ?OnboardingProfile $p, $requirements, array $activities, string $college): array
    {
        $evidence = [
            ['ref' => 'P1', 'text' => 'Tests: '.(implode(', ', array_filter([$p?->sat_score ? "SAT {$p->sat_score}" : null, $p?->act_score ? "ACT {$p->act_score}" : null])) ?: 'none reported').'; test plan: '.($p?->test_optional_status ?: 'not given')],
            ['ref' => 'P2', 'text' => 'Curriculum: '.($p?->curriculum ?: 'not given').'; graduation: '.($p?->graduation_year ?: 'not given').'; situation: '.($p?->applicant_situation ?: 'not given')],
            ['ref' => 'P3', 'text' => 'Intended majors: '.(is_array($p?->intended_majors_json) ? implode(', ', $p->intended_majors_json) : 'not given')],
        ];

        foreach ($requirements->whereIn('requirement_type', ['test_policy', 'english_test'])->values() as $i => $requirement) {
            $evidence[] = ['ref' => 'R'.($i + 1), 'text' => "{$requirement->label}: ".($requirement->value_text ?: $requirement->quote), 'source' => $requirement->source?->url];
        }

        foreach (array_slice($activities, 0, 10) as $i => $activity) {
            $evidence[] = ['ref' => 'A'.($i + 1), 'text' => is_array($activity) ? json_encode($activity, JSON_UNESCAPED_UNICODE) : (string) $activity];
        }

        $handoff = new Handoff(
            taskId: (string) $task->getKey(),
            studentId: (int) $context->student->getKey(),
            specialist: class_basename(ApplicationPlannerAgent::class),
            goal: "Help the student prepare their application to {$college} by hand: answer test_plan, activity_order and additional_info.",
            profileRevision: $context->currentProfileRevision(),
            evidence: $evidence,
            contract: 'application_judgments',
            budget: ['max_model_calls' => 1],
        );

        try {
            $response = $context->modelCall(ApplicationPlannerAgent::class, fn () => ApplicationPlannerAgent::make()->prompt($handoff->prompt()), $handoff);
        } catch (Throwable $e) {
            report($e);
            $context->event('progress', 'The AI judgments could not be made ('.class_basename($e).'); those fields are left for you to decide.');

            return [];
        }

        $out = ['model' => app(AiModelResolver::class)->modelFor(ApplicationPlannerAgent::class)];

        foreach (['test_plan', 'activity_order'] as $key) {
            $judgment = $response[$key] ?? null;

            if (is_array($judgment) && is_string($judgment['judgment'] ?? null) && trim($judgment['judgment']) !== '') {
                // Only references the planner was actually given count as its basis.
                $out[$key] = ['judgment' => trim($judgment['judgment']), 'based_on' => $handoff->knownRefs($judgment['based_on'] ?? [])];
            }
        }

        if (is_string($response['additional_info'] ?? null) && trim($response['additional_info']) !== '') {
            $out['additional_info'] = trim($response['additional_info']);
        }

        return $out;
    }

    /**
     * @return array{value: ?string, basis: string, note: ?string}
     */
    private function checklistStatus(Application $application, string $kind): array
    {
        $item = $application->items->firstWhere('kind', $kind);

        return $item instanceof ApplicationItem
            ? ['value' => $item->status->label(), 'basis' => 'checklist', 'note' => $item->title]
            : ['value' => null, 'basis' => 'validation', 'note' => 'Optional — not on your checklist.'];
    }

    /**
     * @return array{value: ?string, basis: string, note: ?string}
     */
    private function round(Application $application): array
    {
        $deadlines = $application->items->filter(fn (ApplicationItem $i) => $i->kind === 'deadline' && $i->status !== ChecklistStatus::NotApplicable && $i->due_date !== null)->sortBy('due_date');

        return match ($deadlines->count()) {
            0 => ['value' => null, 'basis' => 'validation', 'note' => 'Required — no deadline on your checklist yet; add the college\'s admissions page.'],
            1 => ['value' => $deadlines->first()->title, 'basis' => 'checklist', 'note' => null],
            default => ['value' => null, 'basis' => 'validation', 'note' => 'Required — choose one: '.$deadlines->pluck('title')->implode('; ').'. Mark the others "not needed" on your checklist.'],
        };
    }

    /**
     * @return array{value: ?string, basis: string, note: ?string}
     */
    private function writing($student, ?Application $application, string $kind): array
    {
        $pieces = WritingPiece::query()
            ->where('user_id', $student->getKey())
            ->where('kind', $kind)
            ->when($application !== null, fn ($q) => $q->where('college_list_school_id', $application->college_list_school_id))
            ->whereNotNull('current_version_id')
            ->get();

        if ($pieces->isEmpty()) {
            return $kind === 'personal_statement'
                ? ['value' => null, 'basis' => 'validation', 'note' => 'Required — no personal statement saved in Writing yet.']
                : ['value' => null, 'basis' => 'validation', 'note' => 'Optional — no essays for this college in Writing yet.'];
        }

        $versions = WritingVersion::query()->whereIn('id', $pieces->pluck('current_version_id'))->get()->keyBy('id');
        $parts = $pieces->map(fn (WritingPiece $piece) => "{$piece->title}: version {$versions[$piece->current_version_id]->number}, {$versions[$piece->current_version_id]->word_count} words");
        $over = $pieces->filter(fn (WritingPiece $piece) => ($limit = $piece->word_limit ?? ($kind === 'personal_statement' ? self::PERSONAL_STATEMENT_LIMIT : null)) !== null && $versions[$piece->current_version_id]->word_count > $limit);

        return $over->isNotEmpty()
            ? ['value' => $parts->implode('; '), 'basis' => 'validation', 'note' => 'Check: over the word limit — '.$over->pluck('title')->implode(', ').'.']
            : ['value' => $parts->implode('; '), 'basis' => 'writing', 'note' => null];
    }
}
