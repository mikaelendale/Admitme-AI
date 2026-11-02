<?php

namespace App\Domain\Approvals;

use App\Domain\Applications\ApplicationChecklistService;
use App\Domain\Applications\ApplicationService;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\ChecklistOwner;
use App\Domain\Applications\ChecklistStatus;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Applications\CompletionBasis;
use App\Domain\Applications\SubmissionEvidence;
use App\Domain\Approvals\Concerns\BuildsChangeItems;
use App\Domain\Shared\ChangeSource;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Agent proposals that change the student's existing applications ({@see Proposal::TYPE_APPLICATION_UPDATE}):
 * application fields (status, notes, portal link, system, cycle), a checklist step's status, and new
 * checklist steps. Applied — once approved — through ApplicationService and ApplicationChecklistService, so
 * their rules still hold: "submitted" needs recorded evidence, a step someone else sends is only "received"
 * on the student's report of the portal or the sender, and a value changed since the proposal is not overwritten.
 */
final class ApplicationChangeProposals
{
    use BuildsChangeItems;

    /** @var list<string> */
    public const ACTIONS = ['update', 'checklist_status', 'add_checklist_item'];

    /** Application fields an approved change may set, with how each is labelled for the student. */
    public const FIELDS = [
        'status' => 'Status',
        'notes' => 'Notes',
        'portal_url' => 'Portal link',
        'application_system' => 'Application system',
        'cycle_label' => 'Admissions cycle',
    ];

    /** What a valid value looks like, for errors the agent can act on. */
    private const EXPECTED = [
        'status' => 'one of preparing, ready_for_review, ready_to_submit, submitted, decision_received, closed',
        'notes' => 'text up to 5000 characters, or null to clear the notes',
        'portal_url' => 'a full https:// link, or null to clear it',
        'application_system' => 'one of common_app, coalition, ucas, institution_portal, other, or null',
        'cycle_label' => 'a short label such as "Fall 2027 entry" (up to 64 characters), or null',
    ];

    /** The decision endpoint accepts at most 50 selected items. */
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly ApplicationService $applications,
        private readonly ApplicationChecklistService $checklist,
    ) {}

    /**
     * Build the proposal items, resolving each college among the student's own applications.
     *
     * @param  list<array<string, mixed>>  $changes  Each: college, action; `update`: field + value (or a fields
     *                                               map) and, for a submission, submission_evidence_type + note;
     *                                               `checklist_status`: checklist_item (title), checklist_status,
     *                                               note, completion_basis; `add_checklist_item`: title, due_date,
     *                                               details. Optional reason and evidence.
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
            'changes.*.submission_evidence_type' => ['nullable', 'string', 'max:64'],
            'changes.*.checklist_item' => ['nullable', 'string', 'max:300'],
            'changes.*.checklist_status' => ['nullable', 'string', 'max:64'],
            'changes.*.completion_basis' => ['nullable', 'string', 'max:64'],
            'changes.*.note' => ['nullable', 'string', 'max:2000'],
            'changes.*.title' => ['nullable', 'string', 'max:300'],
            'changes.*.due_date' => ['nullable', 'string', 'max:64'],
            'changes.*.details' => ['nullable', 'string', 'max:4000'],
            ...self::explanationRules(),
        ])->validate();

        $applications = Application::query()
            ->where('user_id', $student->getKey())
            ->with(['collegeListSchool', 'items'])
            ->get()
            ->filter(fn (Application $application) => $application->collegeListSchool !== null)
            ->keyBy(fn (Application $application) => (string) $application->college_list_school_id);

        $schools = $applications->map(fn (Application $application) => $application->collegeListSchool)->values();

        $items = [];
        $errors = [];
        $unchanged = [];

        foreach (array_values($changes) as $index => $change) {
            $match = $this->matchCollege($schools, (string) $change['college']);

            if (! $match instanceof CollegeListSchool) {
                $errors["changes.{$index}"][] = $this->noApplication($student, (string) $change['college'], $match, $schools);

                continue;
            }

            try {
                foreach ($this->itemsFor($applications->get((string) $match->getKey()), $match, $change, $unchanged) as $item) {
                    if (isset($items[$item['key']]) && ! $this->same($items[$item['key']]['after'], $item['after'])) {
                        $errors["changes.{$index}"][] = "{$item['label']} is proposed twice with different values. Propose it once.";
                    }

                    $items[$item['key']] = $item;
                }
            } catch (ValidationException $e) {
                $errors["changes.{$index}"] = [...($errors["changes.{$index}"] ?? []), ...array_merge(...array_values($e->errors()))];
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
        $changes = self::plural(count($items), 'change');
        $where = self::collegesIn($items);

        return count(array_unique(array_column($items, 'application_id'))) === 1
            ? "Update your {$where} application ({$changes})"
            : "Update applications for {$where} ({$changes})";
    }

    /**
     * Apply the items the student selected; each is checked against the current value first, and one that
     * changed since the proposal is `stale` and left as it is.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: ProposalStatus, 1: array<string, string>}
     */
    public function apply(User $student, array $items, ChangeSource $source): array
    {
        $applications = Application::query()
            ->where('user_id', $student->getKey())
            ->whereIn('id', array_values(array_unique(array_map(fn (array $item) => (string) ($item['application_id'] ?? ''), $items))))
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Application $application) => (string) $application->getKey());

        $outcomes = [];

        foreach (collect($items)->groupBy(fn (array $item) => (string) ($item['application_id'] ?? '')) as $applicationId => $group) {
            $application = $applications->get((string) $applicationId);

            if ($application === null) {
                foreach ($group as $item) {
                    $outcomes[$item['key']] = 'stale';
                }

                continue;
            }

            $byAction = $group->groupBy('action');

            $outcomes = [...$outcomes, ...$this->applyFields($application, $byAction->get('update', collect())->all(), $source)];

            foreach ($byAction->get('checklist_status', collect()) as $item) {
                $outcomes[$item['key']] = $this->applyStep($application, $item, $source);
            }

            foreach ($byAction->get('add_checklist_item', collect()) as $item) {
                $outcomes[$item['key']] = $this->applyNewStep($application, $item, $source);
            }
        }

        return [$this->statusFor($outcomes), $outcomes];
    }

    /**
     * The statuses an application passes through to reach `$to`, each one a move the workspace allows
     * (so "submitted" from "preparing" goes through "ready to submit"). Null when it cannot get there.
     *
     * @return list<ApplicationStatus>|null
     */
    public static function statusPath(ApplicationStatus $from, ApplicationStatus $to): ?array
    {
        if ($from === $to) {
            return [];
        }

        $queue = [[$from, []]];
        $seen = [$from->value => true];

        while ($queue !== []) {
            [$status, $path] = array_shift($queue);

            foreach (ApplicationStatus::cases() as $next) {
                if (isset($seen[$next->value]) || ! $status->canTransitionTo($next)) {
                    continue;
                }

                $through = [...$path, $next];

                if ($next === $to) {
                    return $through;
                }

                $seen[$next->value] = true;
                $queue[] = [$next, $through];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  list<string>  $unchanged
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function itemsFor(Application $application, CollegeListSchool $school, array $change, array &$unchanged): array
    {
        $common = [...$this->explanation($change), 'college' => $school->school_name, 'application_id' => (string) $application->getKey()];

        return match ($change['action']) {
            'update' => $this->fieldItems($application, $school, $change, $common, $unchanged),
            'checklist_status' => $this->stepItem($application, $school, $change, $common, $unchanged),
            'add_checklist_item' => $this->newStepItem($application, $school, $change, $common, $unchanged),
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
    private function fieldItems(Application $application, CollegeListSchool $school, array $change, array $common, array &$unchanged): array
    {
        $fields = match (true) {
            is_array($change['fields'] ?? null) => $change['fields'],
            is_string($change['field'] ?? null) => [$change['field'] => $change['value'] ?? null],
            default => [],
        };

        if ($fields === []) {
            throw ValidationException::withMessages(['field' => "Say which field of the {$school->school_name} application to change: one of ".implode(', ', array_keys(self::FIELDS)).'.']);
        }

        $items = [];

        foreach ($fields as $field => $value) {
            if (! is_string($field) || ! array_key_exists($field, self::FIELDS)) {
                throw ValidationException::withMessages(['field' => "\"{$field}\" cannot be changed on an application. Use one of: ".implode(', ', array_keys(self::FIELDS)).'.']);
            }

            $label = "{$school->school_name} application · ".self::FIELDS[$field];
            $after = $this->normalize($field, $value);

            if (Validator::make(['value' => $after], ['value' => $this->rules($field)])->fails()) {
                $shown = is_scalar($value) ? '"'.$value.'"' : (json_encode($value) ?: 'that value');

                throw ValidationException::withMessages(['value' => "{$label}: {$shown} is not valid — use ".self::EXPECTED[$field].'.']);
            }

            $before = self::current($application, $field);

            if ($this->same($before, $after)) {
                $unchanged[] = $before === null ? "{$label} is already empty" : "{$label} is already {$before}";

                continue;
            }

            $item = [
                'key' => "{$school->school_slug}:{$field}",
                'label' => $label,
                'before' => $before,
                'after' => $after,
                ...$common,
                'action' => 'update',
                'field' => $field,
            ];

            if ($field === 'status') {
                $item = [...$item, ...$this->submission($school, $application->status, ApplicationStatus::from((string) $after), $change)];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * The status move must be one the workspace allows (directly or step by step), and any move through
     * "submitted" needs the evidence ApplicationService requires.
     *
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function submission(CollegeListSchool $school, ApplicationStatus $from, ApplicationStatus $to, array $change): array
    {
        $path = self::statusPath($from, $to);

        if ($path === null) {
            $next = array_values(array_map(fn (ApplicationStatus $s) => $s->value, array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $s) => $s !== $from && $from->canTransitionTo($s))));

            throw ValidationException::withMessages(['value' => "The {$school->school_name} application is {$from->label()} and cannot move to {$to->label()}".($next === [] ? '.' : '; from here it can move to: '.implode(', ', $next).'.')]);
        }

        if (! in_array(ApplicationStatus::Submitted, $path, true)) {
            return [];
        }

        $evidence = SubmissionEvidence::tryFrom((string) ($change['submission_evidence_type'] ?? ''));
        $note = self::text($change['note'] ?? null);

        if ($evidence === null || ! is_string($note)) {
            throw ValidationException::withMessages(['submission_evidence_type' => "Marking the {$school->school_name} application submitted needs evidence. Only propose it when the student said they submitted it, with submission_evidence_type (portal_receipt if they have the portal's confirmation, student_confirmation if they only told you) and a note saying what they told you."]);
        }

        return ['submission_evidence_type' => $evidence->value, 'submission_evidence_note' => $note];
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $common
     * @param  list<string>  $unchanged
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function stepItem(Application $application, CollegeListSchool $school, array $change, array $common, array &$unchanged): array
    {
        $title = self::text($change['checklist_item'] ?? null);
        $status = ChecklistStatus::tryFrom(str_replace([' ', '-'], '_', mb_strtolower((string) ($change['checklist_status'] ?? ''))));

        if (! is_string($title)) {
            throw ValidationException::withMessages(['checklist_item' => "Say which {$school->school_name} checklist step to change (checklist_item: its title as ReadApplicationTool shows it)."]);
        }

        if ($status === null) {
            throw ValidationException::withMessages(['checklist_status' => 'checklist_status must be one of '.implode(', ', array_column(ChecklistStatus::cases(), 'value')).'.']);
        }

        $step = $this->matchStep($application->items, $title);

        if (! $step instanceof ApplicationItem) {
            $titles = $application->items->pluck('title')->map(fn ($t) => (string) $t)->all();

            throw ValidationException::withMessages(['checklist_item' => is_array($step)
                ? "\"{$title}\" matches several {$school->school_name} checklist steps (".implode('; ', $step).'). Use the exact title.'
                : "\"{$title}\" is not a step in the {$school->school_name} checklist. Steps: ".($titles === [] ? 'none yet' : implode('; ', $titles)).'. To add a new step use add_checklist_item.']);
        }

        $owner = $step->owner_type;
        $allowed = ChecklistStatus::for($owner);

        if (! in_array($status, $allowed, true)) {
            $who = $owner === ChecklistOwner::ThirdParty ? 'is sent by '.($step->owner_label ?: 'someone else') : "is the student's own step";

            throw ValidationException::withMessages(['checklist_status' => "\"{$step->title}\" {$who}; its status can be: ".implode(', ', array_map(fn (ChecklistStatus $s) => $s->value, $allowed)).'.']);
        }

        $note = self::text($change['note'] ?? null);

        if ($status->needsNote() && ! is_string($note)) {
            throw ValidationException::withMessages(['note' => "Give a note for \"{$step->title}\": ".($status === ChecklistStatus::Blocked ? 'what is blocking it.' : 'why it is not needed.')]);
        }

        $basis = null;

        if ($status->isComplete() && $owner === ChecklistOwner::ThirdParty) {
            $basis = CompletionBasis::tryFrom((string) ($change['completion_basis'] ?? ''));

            if ($basis === null || ! in_array($basis, CompletionBasis::for($owner), true)) {
                throw ValidationException::withMessages(['completion_basis' => "\"{$step->title}\" is sent by ".($step->owner_label ?: 'someone else').'. Mark it received only when the student says the portal shows it arrived (completion_basis: portal_shows_received) or the sender confirmed sending it (sender_confirmed) — asking for it is not the same as it being done.']);
            }
        }

        if ($step->status === $status) {
            $unchanged[] = "\"{$step->title}\" is already {$status->label()}";

            return [];
        }

        return [[
            'key' => "{$school->school_slug}:step:{$step->getKey()}",
            'label' => "{$school->school_name} · ".Str::limit((string) $step->title, 90, '…'),
            'before' => $step->status->value,
            'after' => $status->value,
            ...$common,
            'action' => 'checklist_status',
            'field' => 'checklist_status',
            'item_id' => (string) $step->getKey(),
            'note' => $note,
            'completion_basis' => $basis?->value,
        ]];
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $common
     * @param  list<string>  $unchanged
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function newStepItem(Application $application, CollegeListSchool $school, array $change, array $common, array &$unchanged): array
    {
        $title = self::text($change['title'] ?? null);
        $due = self::date($change['due_date'] ?? null);
        $details = self::text($change['details'] ?? null);

        $invalid = Validator::make(['title' => $title, 'due_date' => $due, 'details' => $details], [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'due_date' => ['nullable', 'string', 'date_format:Y-m-d'],
            'details' => ['nullable', 'string', 'max:4000'],
        ])->fails();

        if ($invalid || ! is_string($title)) {
            throw ValidationException::withMessages(['title' => "A new {$school->school_name} checklist step needs a title (2–200 characters), due_date as YYYY-MM-DD or null, and details up to 4000 characters or null."]);
        }

        if ($application->items->contains(fn (ApplicationItem $step) => mb_strtolower(trim((string) $step->title)) === mb_strtolower($title))) {
            $unchanged[] = "\"{$title}\" is already on the {$school->school_name} checklist";

            return [];
        }

        return [[
            'key' => "{$school->school_slug}:new_step:".substr(sha1(mb_strtolower($title)), 0, 10),
            'label' => "{$school->school_name} · New checklist step",
            'before' => 'Not on your checklist',
            'after' => $title.(is_string($due) ? ' — due '.Carbon::createFromFormat('!Y-m-d', $due)->format('M j, Y') : ''),
            ...$common,
            'action' => 'add_checklist_item',
            'title' => $title,
            'due_date' => $due,
            'details' => $details,
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function applyFields(Application $application, array $items, ChangeSource $source): array
    {
        $outcomes = [];
        $changes = [];
        $statusItem = null;

        foreach ($items as $item) {
            $current = self::current($application, (string) $item['field']);

            $outcomes[$item['key']] = match (true) {
                $this->same($current, $item['after']) => 'already_present',
                ! $this->same($current, $item['before']) => 'stale',
                default => 'applied',
            };

            if ($outcomes[$item['key']] !== 'applied') {
                continue;
            }

            if ($item['field'] === 'status') {
                $statusItem = $item;
            } else {
                $changes[$item['field']] = $item['after'];
            }
        }

        if ($changes !== []) {
            // One write (one revision, one audit entry) for the plain fields...
            $result = $this->attempt(function () use ($application, $changes, $source) {
                $this->applications->update($application, $changes, null, $source);

                return 'applied';
            });

            foreach ($items as $item) {
                if ($item['field'] !== 'status' && $outcomes[$item['key']] === 'applied') {
                    $outcomes[$item['key']] = $result;
                }
            }
        }

        if ($statusItem !== null) {
            // ...and the status moves step by step, all or nothing, through the service's own transition rules.
            $outcomes[$statusItem['key']] = $this->attempt(function () use ($application, $statusItem, $source) {
                $path = self::statusPath(ApplicationStatus::from((string) $statusItem['before']), ApplicationStatus::from((string) $statusItem['after']));

                if ($path === null) {
                    throw ValidationException::withMessages(['status' => 'That status can no longer be reached.']);
                }

                foreach ($path as $step) {
                    $changes = ['status' => $step->value];

                    if ($step === ApplicationStatus::Submitted) {
                        $changes['submission_evidence_type'] = $statusItem['submission_evidence_type'] ?? null;
                        $changes['submission_evidence_note'] = $statusItem['submission_evidence_note'] ?? null;
                    }

                    $this->applications->update($application, $changes, null, $source);
                }

                return 'applied';
            });
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyStep(Application $application, array $item, ChangeSource $source): string
    {
        $step = ApplicationItem::query()
            ->where('application_id', $application->getKey())
            ->where('user_id', $application->user_id)
            ->lockForUpdate()
            ->find($item['item_id'] ?? null);

        if ($step === null) {
            return 'stale';
        }

        if ($step->status->value === $item['after']) {
            return 'already_present';
        }

        if ($step->status->value !== $item['before']) {
            return 'stale';
        }

        return $this->attempt(function () use ($step, $item, $source) {
            $this->checklist->update($step, array_filter([
                'status' => $item['after'],
                'status_note' => $item['note'] ?? null,
                'completion_basis' => $item['completion_basis'] ?? null,
            ], fn ($value) => $value !== null), null, $source);

            return 'applied';
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyNewStep(Application $application, array $item, ChangeSource $source): string
    {
        $title = mb_strtolower(trim((string) $item['title']));

        $exists = ApplicationItem::query()
            ->where('application_id', $application->getKey())
            ->pluck('title')
            ->contains(fn ($existing) => mb_strtolower(trim((string) $existing)) === $title);

        if ($exists) {
            return 'already_present';
        }

        return $this->attempt(function () use ($application, $item, $source) {
            $this->checklist->addManual($application, [
                'title' => (string) $item['title'],
                'due_date' => $item['due_date'] ?? null,
                'details' => $item['details'] ?? null,
            ], $source);

            return 'applied';
        });
    }

    /**
     * @param  Collection<int, ApplicationItem>  $steps
     * @return ApplicationItem|list<string>|null
     */
    private function matchStep(Collection $steps, string $title): ApplicationItem|array|null
    {
        $needle = mb_strtolower(trim($title));
        $exact = $steps->first(fn (ApplicationItem $step) => mb_strtolower(trim((string) $step->title)) === $needle);

        if ($exact !== null) {
            return $exact;
        }

        $partial = $steps->filter(fn (ApplicationItem $step) => str_contains(mb_strtolower((string) $step->title), $needle))->values();

        return match ($partial->count()) {
            0 => null,
            1 => $partial->first(),
            default => $partial->pluck('title')->map(fn ($t) => (string) $t)->all(),
        };
    }

    /**
     * @param  list<string>|null  $candidates
     * @param  Collection<int, CollegeListSchool>  $withApplications
     */
    private function noApplication(User $student, string $name, ?array $candidates, Collection $withApplications): string
    {
        if ($candidates !== null) {
            return "\"{$name}\" matches several applications (".implode(', ', $candidates).'). Use the exact college name.';
        }

        $onList = CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->where('choice_status', '!=', CollegeChoiceStatus::Archived->value)
            ->get();

        $school = $this->matchCollege($onList, $name);

        if ($school instanceof CollegeListSchool) {
            return "{$school->school_name} has no application yet. Propose start_application for it with ProposeCollegeListChangesTool first; once the student approves that, propose these application changes.";
        }

        $names = $withApplications->pluck('school_name')->map(fn ($n) => (string) $n)->all();

        return "\"{$name}\" is not one of the student's applications. ".($names === [] ? 'They have no applications yet.' : 'Applications: '.implode(', ', $names).'.');
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        $value = self::text($value);

        return match ($field) {
            'status', 'application_system' => is_string($value) ? str_replace([' ', '-'], '_', mb_strtolower($value)) : $value,
            default => $value,
        };
    }

    /**
     * @return list<mixed>
     */
    private function rules(string $field): array
    {
        return match ($field) {
            'status' => ['required', Rule::in(array_column(ApplicationStatus::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'portal_url' => ['nullable', 'string', 'url:https', 'max:2048'],
            'application_system' => ['nullable', Rule::in(Application::SYSTEMS)],
            'cycle_label' => ['nullable', 'string', 'max:64'],
        };
    }

    private static function current(Application $application, string $field): mixed
    {
        $value = $application->getAttribute($field);

        return $value instanceof \BackedEnum ? $value->value : self::text($value);
    }
}
