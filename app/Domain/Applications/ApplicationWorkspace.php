<?php

namespace App\Domain\Applications;

use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\RequirementOverview;
use App\Domain\Memory\MemoryReader;
use App\Models\AdmissionRequirement;
use App\Models\AgentTask;
use App\Models\Application;
use App\Models\ApplicationFormPlan;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\Document;
use App\Models\SupplementalEssay;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Everything about one application in one shape — checklist, deadline, blockers and next actions — used by
 * the workspace screen and by the mentor's tool, so chat and the screen always show the same state.
 */
final class ApplicationWorkspace
{
    private const MAX_NEXT_ACTIONS = 5;

    public function __construct(
        private readonly InstitutionResolver $institutions,
        private readonly RequirementOverview $overview,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Application $application): array
    {
        $application->loadMissing(['collegeListSchool', 'user', 'items.requirement.source', 'items.document']);
        $school = $application->collegeListSchool;
        $student = $application->user;
        $institution = $this->institutions->findForSchool($school);
        $cycle = $application->cycle_label ?: $school->admissions_cycle_label;
        $knowledge = $this->overview->for($institution, $cycle, $student);
        $items = $application->items;
        $today = now()->startOfDay();

        $latestRequirement = $institution !== null
            ? AdmissionRequirement::query()->where('institution_id', $institution->id)->visibleTo($student)->active()->max('created_at')
            : null;

        $essays = SupplementalEssay::query()
            ->where('user_id', $student->getKey())
            ->where('college_list_school_id', $school->getKey())
            ->get(['id', 'prompt_text', 'status']);

        return [
            'application' => [
                'id' => (string) $application->id,
                'revision' => (int) $application->revision,
                'status' => $application->status->value,
                'statusLabel' => $application->status->label(),
                'cycle' => $cycle,
                'applicationSystem' => $application->application_system,
                'submittedAt' => $application->submitted_at?->toIso8601String(),
                'submissionEvidence' => $application->submission_evidence_type?->label(),
            ],
            'college' => [
                'id' => (string) $school->id,
                'name' => $school->school_name,
                'choiceStatus' => $school->choice_status->value,
            ],
            'portal' => [
                'url' => $application->portal_url ?: $school->application_portal_url,
                'savedByYou' => $application->portal_url !== null && $application->portal_url !== '',
                'note' => 'With the AdmitMe browser extension the mentor can fill this portal in your browser: you log in, and it asks before submitting or paying. AdmitMe never marks a step done on its own, so mark each one here when the portal shows it.',
            ],
            'deadline' => $this->deadline($items, $school, $today),
            'progress' => $this->progress($items),
            'checklist' => $items->map(fn (ApplicationItem $item) => $this->item($item, $today))->values()->all(),
            'blockers' => $this->blockers($items, $knowledge),
            'nextActions' => $this->nextActions($items, $knowledge, $today),
            'requirements' => [
                'onFile' => count($knowledge['requirements']),
                'unresolved' => $knowledge['unresolved'],
                'checklistBuiltAt' => $application->checklist_built_at?->toIso8601String(),
                // New requirements arrived after the checklist was built: offer a rebuild instead of silently changing it.
                'changedSinceBuilt' => $latestRequirement !== null && ($application->checklist_built_at === null || $application->checklist_built_at->lt($latestRequirement)),
            ],
            'essays' => $essays->map(fn (SupplementalEssay $essay) => [
                'id' => (string) $essay->id,
                'prompt' => $essay->prompt_text,
                'status' => $essay->status,
            ])->values()->all(),
            'documents' => Document::query()
                ->where('user_id', $student->getKey())
                ->orderBy('title')
                ->get(['id', 'title', 'kind'])
                ->map(fn (Document $document) => ['id' => (string) $document->id, 'title' => $document->title, 'kind' => $document->kind])
                ->values()
                ->all(),
            'formPlan' => $this->formPlan($application, $student),
        ];
    }

    /**
     * The latest field-by-field plan for the form, and the plan task while one is being prepared.
     *
     * @return array<string, mixed>
     */
    private function formPlan(Application $application, $student): array
    {
        $plan = ApplicationFormPlan::query()->where('application_id', $application->getKey())->latest('created_at')->latest('id')->first();
        $task = AgentTask::query()
            ->where('user_id', $student->getKey())
            ->where('type', 'application_plan')
            ->where('input->application_id', (string) $application->getKey())
            ->latest('created_at')
            ->first();
        $form = json_decode((string) file_get_contents(resource_path('forms/synthetic-first-year-application.json')), true);

        return [
            'note' => $form['note'] ?? null,
            'formLabel' => $form['label'] ?? null,
            'goalId' => $task !== null && ! $task->status->isTerminal() ? (string) $task->parent_id : null,
            'plan' => $plan !== null ? [
                'id' => (string) $plan->id,
                'createdAt' => $plan->created_at?->toIso8601String(),
                'entries' => $plan->entries,
                // Saved data changed since the plan was made: its values may be out of date.
                'outdated' => $plan->profile_revision < app(MemoryReader::class)->profileRevision($student),
            ] : null,
        ];
    }

    /**
     * A compact version for lists and the mentor's overview of all applications.
     *
     * @return array<string, mixed>
     */
    public function summary(Application $application): array
    {
        $full = $this->for($application);

        return [
            'id' => $full['application']['id'],
            'college' => $full['college']['name'],
            'status' => $full['application']['status'],
            'deadline' => $full['deadline'],
            'progress' => $full['progress'],
            'nextAction' => $full['nextActions'][0]['text'] ?? null,
            'blockers' => count($full['blockers']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ApplicationItem $item, CarbonInterface $today): array
    {
        $source = $item->requirement?->source;

        return [
            'id' => (string) $item->id,
            'revision' => (int) $item->revision,
            'kind' => $item->kind,
            'title' => $item->title,
            'details' => $item->details,
            'origin' => $item->origin,
            'owner' => $item->owner_type->value,
            'ownerLabel' => $item->owner_label,
            'status' => $item->status->value,
            'statusLabel' => $item->status->label(),
            'statusNote' => $item->status_note,
            'allowedStatuses' => array_map(fn (ChecklistStatus $s) => ['value' => $s->value, 'label' => $s->label()], ChecklistStatus::for($item->owner_type)),
            'completionBases' => array_map(fn (CompletionBasis $b) => ['value' => $b->value, 'label' => $b->label()], CompletionBasis::for($item->owner_type)),
            'complete' => $item->status->isComplete(),
            'completionBasis' => $item->completion_basis?->label(),
            'completedAt' => $item->completed_at?->toIso8601String(),
            'dueDate' => $item->due_date?->toDateString(),
            'dueTime' => $item->due_time,
            'timezone' => $item->timezone,
            'overdue' => $item->due_date !== null && $item->due_date->lt($today) && ! $item->status->isClosed(),
            'document' => $item->document !== null ? ['id' => (string) $item->document->id, 'title' => $item->document->title] : null,
            'sourceMissing' => $item->source_missing_at !== null,
            'source' => $source !== null ? [
                'url' => $source->url,
                'title' => $source->title,
                'label' => $this->overview->sourceLabel($source),
                'checkedAt' => $source->checked_at?->toIso8601String(),
                'quote' => $item->requirement?->quote,
            ] : null,
        ];
    }

    /**
     * The next open deadline from a source; otherwise the saved (unverified) research dates, labelled as such.
     *
     * @param  Collection<int, ApplicationItem>  $items
     * @return array<string, mixed>|null
     */
    private function deadline(Collection $items, CollegeListSchool $school, CarbonInterface $today): ?array
    {
        $next = $items
            ->filter(fn (ApplicationItem $i) => $i->kind === 'deadline' && $i->due_date !== null && ! $i->status->isClosed())
            ->sortBy(fn (ApplicationItem $i) => $i->due_date->toDateString())
            ->first();

        if ($next !== null) {
            return [
                'label' => $next->title,
                'date' => $next->due_date->toDateString(),
                'time' => $next->due_time,
                'timezone' => $next->timezone,
                'fromSource' => $next->origin === ApplicationItem::ORIGIN_REQUIREMENT,
                'daysLeft' => (int) $today->diffInDays($next->due_date, false),
                'basis' => $next->requirement?->source !== null ? 'From '.$this->overview->sourceLabel($next->requirement->source) : 'Added by you',
            ];
        }

        $saved = collect(['Early Action' => $school->ea_deadline, 'Early Decision' => $school->ed_deadline, 'Regular Decision' => $school->rd_deadline])
            ->filter(fn ($date) => $date instanceof CarbonInterface)
            ->sort()
            ->first(fn ($date) => $date->gte($today));

        if ($saved === null) {
            return null;
        }

        $round = collect(['Early Action' => $school->ea_deadline, 'Early Decision' => $school->ed_deadline, 'Regular Decision' => $school->rd_deadline])
            ->search(fn ($date) => $date instanceof CarbonInterface && $date->equalTo($saved));

        return [
            'label' => "{$round} deadline",
            'date' => $saved->toDateString(),
            'time' => null,
            'timezone' => null,
            'fromSource' => false,
            'daysLeft' => (int) $today->diffInDays($saved, false),
            'basis' => 'Saved from AI research — not verified. Add the official admissions page to confirm it.',
        ];
    }

    /**
     * @param  Collection<int, ApplicationItem>  $items
     * @return array<string, int>
     */
    private function progress(Collection $items): array
    {
        $counted = $items->reject(fn (ApplicationItem $i) => $i->status === ChecklistStatus::NotApplicable);
        $mine = $counted->where('owner_type', ChecklistOwner::Student);
        $theirs = $counted->where('owner_type', ChecklistOwner::ThirdParty);

        return [
            'done' => $counted->filter(fn (ApplicationItem $i) => $i->status->isComplete())->count(),
            'total' => $counted->count(),
            'yoursDone' => $mine->filter(fn (ApplicationItem $i) => $i->status->isComplete())->count(),
            'yoursTotal' => $mine->count(),
            'othersReceived' => $theirs->filter(fn (ApplicationItem $i) => $i->status->isComplete())->count(),
            'othersRequested' => $theirs->filter(fn (ApplicationItem $i) => $i->status === ChecklistStatus::Requested)->count(),
            'othersTotal' => $theirs->count(),
        ];
    }

    /**
     * @param  Collection<int, ApplicationItem>  $items
     * @param  array<string, mixed>  $knowledge
     * @return list<array<string, mixed>>
     */
    private function blockers(Collection $items, array $knowledge): array
    {
        $blockers = array_map(fn (string $text) => ['kind' => 'requirements', 'text' => $text, 'itemId' => null], $knowledge['unresolved']);

        foreach ($items as $item) {
            if ($item->status === ChecklistStatus::Blocked) {
                $blockers[] = ['kind' => 'item', 'text' => "{$item->title}: ".($item->status_note ?: 'blocked'), 'itemId' => (string) $item->id];
            } elseif ($item->source_missing_at !== null && ! $item->status->isClosed()) {
                $blockers[] = ['kind' => 'item', 'text' => "{$item->title}: the source no longer states this — check the college's site.", 'itemId' => (string) $item->id];
            }
        }

        return $blockers;
    }

    /**
     * Deterministic order: unreliable requirements first, then overdue work, third-party requests (they
     * take time), blocked steps, and the rest by due date.
     *
     * @param  Collection<int, ApplicationItem>  $items
     * @param  array<string, mixed>  $knowledge
     * @return list<array<string, mixed>>
     */
    private function nextActions(Collection $items, array $knowledge, CarbonInterface $today): array
    {
        $actions = [];
        $used = [];
        $open = $items->reject(fn (ApplicationItem $i) => $i->status->isClosed());
        $add = function (ApplicationItem $item, string $text) use (&$actions, &$used): void {
            if (! isset($used[$item->id])) {
                $used[$item->id] = true;
                $actions[] = ['kind' => 'item', 'text' => $text, 'itemId' => (string) $item->id];
            }
        };

        if (count($knowledge['requirements']) === 0) {
            $actions[] = ['kind' => 'source', 'text' => 'Add the college\'s official admissions page so this checklist includes its real requirements.', 'itemId' => null];
        } else {
            foreach (array_slice(array_values(array_filter($knowledge['unresolved'], fn (string $u) => str_starts_with($u, 'Sources disagree'))), 0, 2) as $conflict) {
                $actions[] = ['kind' => 'source', 'text' => "Check the official site: {$conflict}", 'itemId' => null];
            }
        }

        foreach ($open->filter(fn (ApplicationItem $i) => $i->due_date !== null && $i->due_date->lt($today))->sortBy('due_date') as $item) {
            $add($item, "Overdue since {$item->due_date->format('j M Y')}: {$item->title}");
        }

        foreach ($open->filter(fn (ApplicationItem $i) => $i->owner_type === ChecklistOwner::ThirdParty && $i->status === ChecklistStatus::NotRequested) as $item) {
            $add($item, 'Ask '.($item->owner_label ?: 'the sender')." for: {$item->title}");
        }

        foreach ($open->filter(fn (ApplicationItem $i) => $i->status === ChecklistStatus::Blocked) as $item) {
            $add($item, "Unblock: {$item->title}".($item->status_note ? " ({$item->status_note})" : ''));
        }

        $rest = $open
            ->filter(fn (ApplicationItem $i) => $i->owner_type === ChecklistOwner::Student)
            ->sortBy(fn (ApplicationItem $i) => [$i->due_date?->toDateString() ?? '9999-12-31', $i->position]);

        foreach ($rest as $item) {
            // A deadline step's title already carries its date...
            $add($item, $item->title.($item->due_date !== null && $item->kind !== 'deadline' ? ' — due '.$item->due_date->format('j M Y') : ''));
        }

        return array_slice($actions, 0, self::MAX_NEXT_ACTIONS);
    }
}
