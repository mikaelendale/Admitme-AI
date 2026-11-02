<?php

namespace App\Domain\Approvals;

use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Applications\CollegeListService;
use App\Domain\Approvals\Concerns\BuildsChangeItems;
use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\SourceUrlPolicy;
use App\Domain\Shared\ChangeSource;
use App\Jobs\IngestApprovedSourceJob;
use App\Models\CollegeListSchool;
use App\Models\Institution;
use App\Models\KnowledgeSource;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Agent proposals that change colleges already on the student's list ({@see Proposal::TYPE_COLLEGE_LIST_UPDATE}):
 * saved fields (deadlines, tier, notes, portal link, cycle, progress), removal, starting the application,
 * and adding a source page. Items are built here and applied — once approved — through the same domain
 * services the College list screens use. An item whose value changed after it was proposed is never
 * applied over the newer value.
 */
final class CollegeListChangeProposals
{
    use BuildsChangeItems;

    /** @var list<string> */
    public const ACTIONS = ['update', 'remove', 'start_application', 'add_source'];

    /** Saved fields an approved change may set or clear, with how each is labelled for the student. */
    public const FIELDS = [
        'ea_deadline' => 'Early Action deadline',
        'ed_deadline' => 'Early Decision deadline',
        'rd_deadline' => 'Regular Decision deadline',
        'rolling_admission' => 'Rolling admission',
        'tier' => 'Tier',
        'student_notes' => 'Your notes',
        'application_portal_url' => 'Application portal link',
        'admissions_cycle_label' => 'Admissions cycle',
        'application_status' => 'Application progress',
    ];

    /** What a valid value looks like, for errors the agent can act on. */
    private const EXPECTED = [
        'ea_deadline' => 'a real date written as YYYY-MM-DD, or null to clear it',
        'ed_deadline' => 'a real date written as YYYY-MM-DD, or null to clear it',
        'rd_deadline' => 'a real date written as YYYY-MM-DD, or null to clear it',
        'rolling_admission' => 'true when the college decides on a rolling basis (no fixed deadline), false otherwise',
        'tier' => 'reach, match or safety',
        'student_notes' => 'text up to 1000 characters, or null to clear the notes',
        'application_portal_url' => 'a full https:// link, or null to clear it',
        'admissions_cycle_label' => 'a short label such as "Fall 2027 entry" (up to 64 characters), or null',
        'application_status' => 'one of not_started, researching, applying, submitted, accepted, rejected, waitlisted',
    ];

    private const DEADLINES = ['ea_deadline', 'ed_deadline', 'rd_deadline'];

    /** The decision endpoint accepts at most 50 selected items. */
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly CollegeListService $colleges,
        private readonly ApplicationService $applications,
        private readonly InstitutionResolver $institutions,
        private readonly SourceUrlPolicy $policy,
    ) {}

    /**
     * Build the proposal items: one per field or operation, each resolved against the student's own
     * non-archived colleges. Changes that would not change anything are left out.
     *
     * @param  list<array<string, mixed>>  $changes  Each: college (name or slug), action, and for
     *                                               `update` either field + value or a fields map (null clears);
     *                                               for `add_source` url (+ official); optional reason and evidence.
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException with every problem at once, so the agent can fix them in one retry.
     */
    public function items(User $student, array $changes): array
    {
        Validator::make(['changes' => $changes], [
            'changes' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'changes.*' => ['required', 'array'],
            'changes.*.college' => ['required', 'string', 'max:200'],
            'changes.*.action' => ['required', Rule::in(self::ACTIONS)],
            'changes.*.field' => ['nullable', 'string', 'max:64'],
            'changes.*.fields' => ['nullable', 'array', 'max:'.count(self::FIELDS)],
            'changes.*.url' => ['nullable', 'string', 'max:2048'],
            'changes.*.official' => ['nullable', 'boolean'],
            ...self::explanationRules(),
        ])->validate();

        $schools = CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->where('choice_status', '!=', CollegeChoiceStatus::Archived->value)
            ->with('application:id,college_list_school_id')
            ->orderBy('school_name')
            ->get();

        $items = [];
        $errors = [];
        $unchanged = [];

        foreach (array_values($changes) as $index => $change) {
            $match = $this->matchCollege($schools, (string) $change['college']);

            if (! $match instanceof CollegeListSchool) {
                $errors["changes.{$index}"][] = $this->notOnList((string) $change['college'], $match, $schools);

                continue;
            }

            try {
                foreach ($this->itemsFor($student, $match, $change, $unchanged) as $item) {
                    if (isset($items[$item['key']]) && ! $this->same($items[$item['key']]['after'], $item['after'])) {
                        $errors["changes.{$index}"][] = "{$item['label']} is proposed twice with different values. Propose it once.";
                    }

                    $items[$item['key']] = $item;
                }
            } catch (ValidationException $e) {
                $errors["changes.{$index}"] = [...($errors["changes.{$index}"] ?? []), ...array_merge(...array_values($e->errors()))];
            }
        }

        // Removing a college and changing it in the same proposal would be contradictory...
        foreach (collect($items)->groupBy('school_id') as $group) {
            if ($group->count() > 1 && $group->contains('action', 'remove')) {
                $errors['changes'][] = "{$group->first()['college']} is proposed for removal together with other changes to it. Propose either the removal or the changes.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($items === []) {
            throw ValidationException::withMessages(['changes' => 'Nothing would change: '.implode('; ', $unchanged).'.']);
        }

        if (count($items) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['changes' => 'That is more than '.self::MAX_ITEMS.' changes; split them into smaller proposals.']);
        }

        return array_values($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function title(array $items): string
    {
        $actions = array_values(array_unique(array_column($items, 'action')));
        $fields = array_values(array_unique(array_filter(array_column($items, 'field'))));
        $where = self::collegesIn($items);
        $count = count(array_unique(array_column($items, 'school_id')));

        return match (true) {
            $actions === ['remove'] => "Remove {$where} from your list",
            $actions === ['start_application'] => $count === 1 ? "Start your {$where} application" : "Start applications for {$where}",
            $actions === ['add_source'] => 'Add '.self::plural(count($items), 'source').' for '.$where,
            $actions === ['update'] && array_diff($fields, self::DEADLINES) === [] => "Update deadlines for {$where}",
            default => "Update {$where} on your list (".self::plural(count($items), 'change').')',
        };
    }

    /**
     * Apply the items the student selected. Each is checked against the current value first: if it changed
     * since the proposal the item is `stale` and nothing is overwritten.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: ProposalStatus, 1: array<string, string>}
     */
    public function apply(User $student, array $items, ChangeSource $source): array
    {
        $schools = CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->whereIn('id', array_values(array_unique(array_map(fn (array $item) => (string) ($item['school_id'] ?? ''), $items))))
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (CollegeListSchool $school) => (string) $school->getKey());

        $outcomes = [];

        foreach (collect($items)->groupBy(fn (array $item) => (string) ($item['school_id'] ?? '')) as $schoolId => $group) {
            $school = $schools->get((string) $schoolId);

            // Gone, or taken off the list since the proposal: its changes were meant for a college on the list...
            if ($school === null || $school->choice_status === CollegeChoiceStatus::Archived) {
                foreach ($group as $item) {
                    $outcomes[$item['key']] = $school !== null && $item['action'] === 'remove' ? 'already_present' : 'stale';
                }

                continue;
            }

            $byAction = $group->groupBy('action');

            $outcomes = [...$outcomes, ...$this->applyFields($school, $byAction->get('update', collect())->all(), $source)];

            foreach ($byAction->get('start_application', collect()) as $item) {
                $outcomes[$item['key']] = $this->applyStart($school, $item, $source);
            }

            foreach ($byAction->get('remove', collect()) as $item) {
                $outcomes[$item['key']] = $this->applyRemove($school, $item, $source);
            }

            foreach ($byAction->get('add_source', collect()) as $item) {
                $outcomes[$item['key']] = $this->applySource($student, $school, $item);
            }
        }

        return [$this->statusFor($outcomes), $outcomes];
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  list<string>  $unchanged
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function itemsFor(User $student, CollegeListSchool $school, array $change, array &$unchanged): array
    {
        $common = [...$this->explanation($change), 'college' => $school->school_name, 'school_id' => (string) $school->getKey()];

        return match ($change['action']) {
            'update' => $this->fieldItems($school, $change, $common, $unchanged),
            'remove' => [[
                'key' => "{$school->school_slug}:remove",
                'label' => "{$school->school_name} · Remove from your list",
                'before' => $school->choice_status === CollegeChoiceStatus::Suggested ? 'Suggested by research (not saved yet)' : 'On your list ('.$school->choice_status->label().')',
                'after' => match (true) {
                    $school->choice_status === CollegeChoiceStatus::Suggested => 'Dismissed — history kept',
                    $school->application !== null => 'Removed — history and application kept',
                    default => 'Removed — history kept',
                },
                ...$common,
                'action' => 'remove',
                'expect' => $school->choice_status->value,
            ]],
            'start_application' => $this->startItem($school, $common, $unchanged),
            'add_source' => $this->sourceItem($student, $school, $change, $common, $unchanged),
        };
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $common
     * @param  list<string>  $unchanged
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function fieldItems(CollegeListSchool $school, array $change, array $common, array &$unchanged): array
    {
        $fields = match (true) {
            is_array($change['fields'] ?? null) => $change['fields'],
            is_string($change['field'] ?? null) => [$change['field'] => $change['value'] ?? null],
            default => [],
        };

        if ($fields === []) {
            throw ValidationException::withMessages(['field' => "Say which field to change for {$school->school_name}: one of ".implode(', ', array_keys(self::FIELDS)).'.']);
        }

        $items = [];

        foreach ($fields as $field => $value) {
            if (! is_string($field) || ! array_key_exists($field, self::FIELDS)) {
                throw ValidationException::withMessages(['field' => "\"{$field}\" cannot be changed on a college. Use one of: ".implode(', ', array_keys(self::FIELDS)).'.']);
            }

            $label = "{$school->school_name} · ".self::FIELDS[$field];
            $after = $this->normalize($field, $value);

            if (Validator::make(['value' => $after], ['value' => $this->rules($field)])->fails()) {
                $shown = is_scalar($value) ? '"'.$value.'"' : (json_encode($value) ?: 'that value');

                throw ValidationException::withMessages(['value' => "{$label}: {$shown} is not valid — use ".self::EXPECTED[$field].'.']);
            }

            $before = self::current($school, $field);

            if ($this->same($before, $after)) {
                $unchanged[] = $before === null ? "{$label} is already empty" : "{$label} is already {$before}";

                continue;
            }

            $items[] = [
                'key' => "{$school->school_slug}:{$field}",
                'label' => $label,
                'before' => $before,
                'after' => $after,
                ...$common,
                'action' => 'update',
                'field' => $field,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  list<string>  $unchanged
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function startItem(CollegeListSchool $school, array $common, array &$unchanged): array
    {
        if ($school->choice_status === CollegeChoiceStatus::Applying && $school->application !== null) {
            $unchanged[] = "{$school->school_name} already has an application";

            return [];
        }

        if ($school->choice_status === CollegeChoiceStatus::Suggested) {
            throw ValidationException::withMessages(['action' => "{$school->school_name} is only a research suggestion, not on the saved list. Propose adding it with ProposeCollegeListAdditionsTool first; once the student approves that, you can start its application."]);
        }

        return [[
            'key' => "{$school->school_slug}:start_application",
            'label' => "{$school->school_name} · Start application",
            'before' => $school->choice_status === CollegeChoiceStatus::Applying ? 'Applying — no application opened yet' : $school->choice_status->label().' — not applying yet',
            'after' => 'Applying — application opened with its checklist',
            ...$common,
            'action' => 'start_application',
            'expect' => $school->choice_status->value,
        ]];
    }

    /**
     * A source must pass the same URL policy as one the student adds on the College list page; a page that
     * would be refused is reported to the agent now instead of being shown to the student.
     *
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $common
     * @param  list<string>  $unchanged
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function sourceItem(User $student, CollegeListSchool $school, array $change, array $common, array &$unchanged): array
    {
        $url = self::text($change['url'] ?? null);
        $official = (bool) ($change['official'] ?? false);

        if (! is_string($url)) {
            throw ValidationException::withMessages(['url' => "To add a source for {$school->school_name}, give the page's full https:// link in url."]);
        }

        $institution = $this->institutions->findForSchool($school) ?? new Institution(['name' => $school->school_name, 'slug' => $school->school_slug]);

        try {
            $checked = $this->policy->check($url, $institution, $official);
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();

            if (! $official && $this->acceptedAsOfficial($url, $institution)) {
                $message = "That page is not on {$school->school_name}'s verified website or a known admissions reference site. If the student confirmed it is the college's official website, propose it again with official: true (it is then kept private to them and marked not verified).";
            }

            throw ValidationException::withMessages(['url' => "{$school->school_name} · source {$url}: {$message}"]);
        }

        $onFile = $institution->exists && KnowledgeSource::query()
            ->where('institution_id', $institution->getKey())
            ->where('url_hash', hash('sha256', $checked['url']))
            ->where('status', 'active')
            ->visibleTo($student)
            ->exists();

        if ($onFile) {
            $unchanged[] = "{$checked['url']} is already a source for {$school->school_name}";

            return [];
        }

        $kind = match ($checked['type']) {
            KnowledgeSource::TYPE_OFFICIAL => "{$school->school_name}'s verified website",
            KnowledgeSource::TYPE_REFERENCE => 'admissions reference site',
            default => 'you confirm it is the official site — kept private to you, not verified',
        };

        return [[
            'key' => "{$school->school_slug}:source:".substr(sha1($checked['url']), 0, 10),
            'label' => "{$school->school_name} · Add source",
            'before' => 'Not on file',
            'after' => "{$checked['url']} ({$kind})",
            ...$common,
            'action' => 'add_source',
            'url' => $checked['url'],
            'official' => $official,
        ]];
    }

    /**
     * Whether the page would be accepted as the student's own (unverified) official-site source.
     */
    private function acceptedAsOfficial(string $url, Institution $institution): bool
    {
        try {
            $this->policy->check($url, $institution, true);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function applyFields(CollegeListSchool $school, array $items, ChangeSource $source): array
    {
        $outcomes = [];
        $changes = [];

        foreach ($items as $item) {
            $current = self::current($school, (string) $item['field']);

            $outcomes[$item['key']] = match (true) {
                $this->same($current, $item['after']) => 'already_present',
                ! $this->same($current, $item['before']) => 'stale',
                default => 'applied',
            };

            if ($outcomes[$item['key']] === 'applied') {
                $changes[$item['field']] = $item['after'];
            }
        }

        if ($changes === []) {
            return $outcomes;
        }

        // One write (one revision, one audit entry) for all of this college's fields...
        $result = $this->attempt(function () use ($school, $changes, $source) {
            $this->colleges->update($school, $changes, null, $source);

            return 'applied';
        });

        return array_map(fn (string $outcome) => $outcome === 'applied' ? $result : $outcome, $outcomes);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyStart(CollegeListSchool $school, array $item, ChangeSource $source): string
    {
        $school = $school->fresh();

        if ($school === null) {
            return 'stale';
        }

        if ($school->choice_status === CollegeChoiceStatus::Applying) {
            if ($school->application()->exists()) {
                return 'already_present';
            }

            return $this->attempt(function () use ($school, $source) {
                $this->applications->openFor($school, $source);

                return 'applied';
            });
        }

        if ($school->choice_status->value !== ($item['expect'] ?? null)) {
            return 'stale';
        }

        return $this->attempt(function () use ($school, $source) {
            $this->colleges->update($school, ['choice_status' => CollegeChoiceStatus::Applying->value], null, $source);

            return 'applied';
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyRemove(CollegeListSchool $school, array $item, ChangeSource $source): string
    {
        $school = $school->fresh();

        if ($school === null) {
            return 'stale';
        }

        if ($school->choice_status === CollegeChoiceStatus::Archived) {
            return 'already_present';
        }

        if ($school->choice_status->value !== ($item['expect'] ?? null)) {
            return 'stale';
        }

        return $this->attempt(function () use ($school, $source) {
            $this->colleges->archive($school, null, $source);

            return 'applied';
        });
    }

    /**
     * Same path as the College list page's "add a source": the URL policy decides now (a refused link is this
     * item's outcome, never an error for the whole decision); the page itself is read by a queued job once the
     * decision is saved, so a slow site never holds the decision's locks. The item is then `queued`.
     *
     * @param  array<string, mixed>  $item
     */
    private function applySource(User $student, CollegeListSchool $school, array $item): string
    {
        return $this->attempt(function () use ($student, $school, $item) {
            $institution = $this->institutions->forSchool($school);

            $onFile = KnowledgeSource::query()
                ->where('institution_id', $institution->getKey())
                ->where('url_hash', hash('sha256', (string) $item['url']))
                ->where('status', 'active')
                ->visibleTo($student)
                ->exists();

            if ($onFile) {
                return 'already_present';
            }

            try {
                $this->policy->check((string) $item['url'], $institution, (bool) ($item['official'] ?? false));
            } catch (ValidationException) {
                return 'refused';
            }

            IngestApprovedSourceJob::dispatch((int) $student->getKey(), (string) $school->getKey(), (string) $item['url'], (bool) ($item['official'] ?? false))->afterCommit();

            return 'queued';
        });
    }

    /**
     * @param  Collection<int, CollegeListSchool>  $schools
     * @param  list<string>|null  $candidates
     */
    private function notOnList(string $name, ?array $candidates, Collection $schools): string
    {
        if ($candidates !== null) {
            return "\"{$name}\" matches several colleges on the list (".implode(', ', $candidates).'). Use the exact name.';
        }

        $names = $schools->pluck('school_name')->map(fn ($n) => (string) $n)->all();

        return "\"{$name}\" is not on the student's college list. "
            .($names === [] ? 'The list is empty.' : 'Colleges on the list: '.implode(', ', $names).'.')
            .' To add a new college use ProposeCollegeListAdditionsTool.';
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        $value = self::text($value);

        return match ($field) {
            'ea_deadline', 'ed_deadline', 'rd_deadline' => self::date($value),
            'rolling_admission' => is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value),
            'tier' => is_string($value) ? CollegeListSchool::listTypeToTier(mb_strtolower($value)) : $value,
            'application_status' => is_string($value) ? str_replace([' ', '-'], '_', mb_strtolower($value)) : $value,
            default => $value,
        };
    }

    /**
     * @return list<mixed>
     */
    private function rules(string $field): array
    {
        return match ($field) {
            'ea_deadline', 'ed_deadline', 'rd_deadline' => ['nullable', 'string', 'date_format:Y-m-d'],
            'rolling_admission' => ['required', 'boolean'],
            'tier' => ['required', Rule::in(['reach', 'match', 'safety'])],
            'student_notes' => ['nullable', 'string', 'max:1000'],
            'application_portal_url' => ['nullable', 'string', 'url:https', 'max:2048'],
            'admissions_cycle_label' => ['nullable', 'string', 'max:64'],
            'application_status' => ['required', Rule::in(CollegeListSchool::APPLICATION_STATUSES)],
        };
    }

    /**
     * The saved value in the same form a proposal stores it (dates as YYYY-MM-DD, blank as null).
     */
    private static function current(CollegeListSchool $school, string $field): mixed
    {
        $value = $school->getAttribute($field);

        return match (true) {
            $value instanceof CarbonInterface => $value->toDateString(),
            $value instanceof \BackedEnum => $value->value,
            default => self::text($value),
        };
    }
}
