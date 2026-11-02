<?php

namespace App\Domain\Approvals;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Applications\CollegeListService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Browser\BrowserCommandService;
use App\Domain\Shared\ChangeSource;
use App\Domain\Shared\StaleRevisionException;
use App\Domain\Students\StudentFieldRegistry;
use App\Domain\Students\StudentProfileService;
use App\Domain\Tasks\Orchestrator;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\ProposalDecision;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Durable approval proposals for agent-originated changes.
 *
 * An agent can only *propose*; nothing changes until the student approves. A decision authorizes the
 * exact payload the student reviewed (revision + hash), is recorded once per proposal, and is applied
 * through the same domain services manual edits use. A proposal whose underlying data changed is
 * marked stale instead of overwriting the newer edit.
 */
final class ProposalService
{
    public function __construct(
        private readonly StudentProfileService $profiles,
        private readonly CollegeListService $colleges,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, array{value: mixed, reason?: string|null}>  $changes  Field key => proposed value and reason.
     *
     * @throws ValidationException
     */
    public function proposeProfileChanges(User $student, array $changes, string $actor, ?string $conversationId = null): Proposal
    {
        $unknown = array_values(array_filter(array_keys($changes), fn ($key) => ! is_string($key) || ! StudentFieldRegistry::has($key)));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['changes' => 'These profile fields cannot be proposed: '.implode(', ', $unknown).'.']);
        }

        $values = array_map(fn (array $change) => $change['value'] ?? null, $changes);
        Validator::make($values, StudentFieldRegistry::rules(array_keys($values)))->validate();

        $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->first();
        $items = [];

        foreach ($changes as $key => $change) {
            $field = StudentFieldRegistry::get($key);
            $before = $profile?->getAttribute($field['column']);
            $after = StudentFieldRegistry::normalize($key, $change['value'] ?? null);

            if ($this->same($before, $after)) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $field['label'],
                'before' => $before,
                'after' => $after,
                'reason' => isset($change['reason']) ? Str::limit(trim((string) $change['reason']), 500, '…') : null,
            ];
        }

        if ($items === []) {
            throw ValidationException::withMessages(['changes' => 'Nothing would change: the profile already has these values.']);
        }

        return $this->create($student, Proposal::TYPE_PROFILE_UPDATE, 'Update '.count($items).' profile field(s)', $items, (int) ($profile?->revision ?? 0), $actor, $conversationId);
    }

    /**
     * @param  list<array{school_name: string, tier: string, reason?: string|null}>  $colleges
     *
     * @throws ValidationException
     */
    public function proposeCollegeAdditions(User $student, array $colleges, string $actor, ?string $conversationId = null): Proposal
    {
        Validator::make(['colleges' => $colleges], [
            'colleges' => ['required', 'array', 'min:1', 'max:20'],
            'colleges.*.school_name' => ['required', 'string', 'max:200'],
            'colleges.*.tier' => ['required', 'in:reach,match,safety'],
            'colleges.*.reason' => ['nullable', 'string', 'max:1000'],
            // What each suggestion rests on, shown to the student with the item (Phase 7 task proposals).
            'colleges.*.evidence' => ['nullable', 'array', 'max:8'],
            'colleges.*.evidence.*.text' => ['required', 'string', 'max:400'],
            'colleges.*.evidence.*.basis' => ['required', 'string', 'max:200'],
            'colleges.*.evidence.*.url' => ['nullable', 'string', 'max:2048'],
        ])->validate();

        // Saved colleges only: a research suggestion can still be proposed for the shortlist.
        $onList = CollegeListSchool::query()
            ->where('user_id', $student->getKey())
            ->whereIn('choice_status', [CollegeChoiceStatus::Shortlisted->value, CollegeChoiceStatus::Applying->value])
            ->pluck('school_slug')
            ->all();

        $items = [];

        foreach ($colleges as $college) {
            $name = trim($college['school_name']);
            $slug = Str::slug($name);

            if ($slug === '' || isset($items[$slug]) || in_array($slug, $onList, true)) {
                continue;
            }

            $items[$slug] = [
                'key' => $slug,
                'label' => $name,
                'before' => null,
                'after' => ['school_name' => $name, 'tier' => $college['tier']],
                'reason' => isset($college['reason']) ? Str::limit(trim((string) $college['reason']), 500, '…') : null,
                ...(! empty($college['evidence']) ? ['evidence' => array_values(array_map(fn (array $e) => [
                    'text' => (string) $e['text'],
                    'basis' => (string) $e['basis'],
                    'url' => isset($e['url']) && is_string($e['url']) && str_starts_with($e['url'], 'http') ? $e['url'] : null,
                ], $college['evidence']))] : []),
            ];
        }

        if ($items === []) {
            throw ValidationException::withMessages(['colleges' => 'Every proposed college is already on the list.']);
        }

        return $this->create($student, Proposal::TYPE_COLLEGE_LIST_ADD, 'Add '.count($items).' college(s) to the list', array_values($items), null, $actor, $conversationId);
    }

    /**
     * Changes to colleges already on the student's list — saved fields (deadlines, tier, notes, portal link,
     * cycle, progress), removal, starting the application, adding a source page — one item per field or
     * operation. Colleges are resolved by name or slug among the student's own non-archived choices only.
     *
     * @param  list<array<string, mixed>>  $changes  See {@see CollegeListChangeProposals::items()}.
     *
     * @throws ValidationException
     */
    public function proposeCollegeListChanges(User $student, array $changes, string $actor, ?string $conversationId = null): Proposal
    {
        $changeSet = app(CollegeListChangeProposals::class);
        $items = $changeSet->items($student, $changes);

        return $this->create($student, Proposal::TYPE_COLLEGE_LIST_UPDATE, $changeSet->title($items), $items, null, $actor, $conversationId);
    }

    /**
     * Changes to the student's existing applications — status, notes, portal link, system, cycle, checklist
     * step statuses and new checklist steps — one item per field or step.
     *
     * @param  list<array<string, mixed>>  $changes  See {@see ApplicationChangeProposals::items()}.
     *
     * @throws ValidationException
     */
    public function proposeApplicationChanges(User $student, array $changes, string $actor, ?string $conversationId = null): Proposal
    {
        $changeSet = app(ApplicationChangeProposals::class);
        $items = $changeSet->items($student, $changes);

        return $this->create($student, Proposal::TYPE_APPLICATION_UPDATE, $changeSet->title($items), $items, null, $actor, $conversationId);
    }

    /**
     * Accept (as read or corrected) or reject values read from the student's documents — the Documents
     * review, proposed in one card. Only the student's own values that are still waiting for review.
     *
     * @param  list<array<string, mixed>>  $decisions  See {@see DocumentValueProposals::items()}.
     *
     * @throws ValidationException
     */
    public function proposeDocumentValueDecisions(User $student, array $decisions, string $actor, ?string $conversationId = null): Proposal
    {
        $changeSet = app(DocumentValueProposals::class);
        $items = $changeSet->items($student, $decisions);

        return $this->create($student, Proposal::TYPE_DOCUMENT_VALUES, $changeSet->title($items), $items, null, $actor, $conversationId);
    }

    /**
     * Values to type into fields of a page in the student's browser. The proposal is bound (context) to the
     * browser session, snapshot, tab and origin it was prepared against; approving queues bridge commands.
     *
     * @param  list<array{key: string, label: string, before: mixed, after: mixed, reason?: string|null}>  $items
     * @param  array<string, mixed>  $context
     */
    public function proposeBrowserFill(User $student, string $title, array $items, array $context, string $actor, ?string $conversationId = null): Proposal
    {
        $proposal = $this->create($student, Proposal::TYPE_BROWSER_FILL, $title, array_values($items), null, $actor, $conversationId);
        $proposal->forceFill(['context' => $context])->save();

        return $proposal;
    }

    /**
     * Record the student's decision and apply it. Returns the single authoritative decision; repeating the
     * request (double click, retry) returns the same decision without applying anything again.
     *
     * @param  list<string>  $selectedKeys
     * @param  array<string, mixed>  $edits  Item key => value the student typed instead (profile proposals only).
     *
     * @throws ProposalConflictException
     * @throws ValidationException
     */
    public function decide(
        User $student,
        Proposal $proposal,
        string $decision,
        array $selectedKeys,
        array $edits,
        int $reviewedRevision,
        string $reviewedHash,
        ?string $idempotencyKey = null,
    ): ProposalDecision {
        if ($idempotencyKey !== null && ($replayed = ProposalDecision::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $replayed;
        }

        // Persist expiry outside the decision transaction, which rolls back when the decision is refused...
        $this->refreshExpiry($proposal->fresh() ?? $proposal);

        try {
            $recorded = DB::transaction(fn () => $this->decideLocked($student, $proposal, $decision, $selectedKeys, $edits, $reviewedRevision, $reviewedHash, $idempotencyKey));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request recorded the decision first; that one is authoritative...
            return ProposalDecision::query()->where('proposal_id', $proposal->getKey())->firstOrFail();
        }

        $this->notifyTask($recorded->proposal_id);

        return $recorded;
    }

    /**
     * Expire pending proposals whose time ran out. Returns the proposal (refreshed).
     */
    public function refreshExpiry(Proposal $proposal): Proposal
    {
        if ($proposal->status === ProposalStatus::Pending && $proposal->isExpired()) {
            $proposal->update(['status' => ProposalStatus::Expired]);
            $this->notifyTask((string) $proposal->getKey());
        }

        return $proposal;
    }

    /**
     * A proposal made by an orchestrated task moves that task on once it is decided (or stale/expired).
     */
    private function notifyTask(string $proposalId): void
    {
        $proposal = Proposal::query()->with('decision')->find($proposalId);

        if ($proposal?->agent_task_id !== null && $proposal->status !== ProposalStatus::Pending) {
            app(Orchestrator::class)->proposalDecided($proposal);
        }
    }

    /**
     * @return Collection<int, Proposal>
     */
    public function pendingFor(User $student): Collection
    {
        return Proposal::query()
            ->where('user_id', $student->getKey())
            ->where('status', ProposalStatus::Pending)
            ->latest('created_at')
            ->get()
            ->map(fn (Proposal $proposal) => $this->refreshExpiry($proposal))
            ->filter(fn (Proposal $proposal) => $proposal->status === ProposalStatus::Pending)
            ->values();
    }

    /**
     * @param  list<string>  $selectedKeys
     * @param  array<string, mixed>  $edits
     */
    private function decideLocked(User $student, Proposal $proposal, string $decision, array $selectedKeys, array $edits, int $reviewedRevision, string $reviewedHash, ?string $idempotencyKey): ProposalDecision
    {
        /** @var Proposal $locked */
        $locked = Proposal::query()->lockForUpdate()->findOrFail($proposal->getKey());

        if (($existing = $locked->decision()->first()) !== null) {
            return $existing;
        }

        if ($locked->status === ProposalStatus::Pending && $locked->isExpired()) {
            throw new ProposalConflictException('This proposal expired before it was decided. Ask the mentor for a fresh one.');
        }

        if ($locked->status !== ProposalStatus::Pending) {
            throw new ProposalConflictException("This proposal is {$locked->status->value} and can no longer be decided.");
        }

        if ($reviewedRevision !== $locked->revision || ! hash_equals($locked->payload_hash, $reviewedHash)) {
            throw new ProposalConflictException('This proposal changed since you reviewed it. Reload it and review again.');
        }

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }

        $itemKeys = array_column($locked->items, 'key');

        if ($decision === 'reject') {
            return $this->record($student, $locked, 'reject', [], array_fill_keys($itemKeys, 'rejected'), ProposalStatus::Rejected, $idempotencyKey);
        }

        $selectedKeys = array_values(array_unique(array_map('strval', $selectedKeys)));

        if ($selectedKeys === [] || array_diff($selectedKeys, $itemKeys) !== []) {
            throw ValidationException::withMessages(['selected_keys' => 'Select at least one of the proposed items.']);
        }

        if ($edits !== []) {
            $this->applyEdits($locked, $selectedKeys, $edits);
        }

        $selected = array_values(array_filter($locked->items, fn (array $item) => in_array($item['key'], $selectedKeys, true)));
        $source = new ChangeSource(ChangeSource::ACTOR_STUDENT, (string) $student->getKey(), ChangeSource::SOURCE_AGENT_PROPOSAL, [
            'proposal_id' => $locked->getKey(),
            'proposed_by' => $locked->actor,
        ]);

        [$status, $outcomes] = match ($locked->type) {
            Proposal::TYPE_PROFILE_UPDATE => $this->applyProfile($student, $selected, $source),
            Proposal::TYPE_COLLEGE_LIST_ADD => $this->applyCollegeAdditions($student, $selected, $source),
            Proposal::TYPE_COLLEGE_LIST_UPDATE => app(CollegeListChangeProposals::class)->apply($student, $selected, $source),
            Proposal::TYPE_APPLICATION_UPDATE => app(ApplicationChangeProposals::class)->apply($student, $selected, $source),
            Proposal::TYPE_DOCUMENT_VALUES => app(DocumentValueProposals::class)->apply($student, $selected, $source),
            Proposal::TYPE_BROWSER_FILL => app(BrowserCommandService::class)->queueApprovedFills($student, $locked, $selected),
            default => throw new ProposalConflictException("Unsupported proposal type [{$locked->type}]."),
        };

        $receipt = array_fill_keys($itemKeys, 'skipped');
        foreach ($outcomes as $key => $outcome) {
            $receipt[$key] = $outcome;
        }

        return $this->record($student, $locked, 'approve', $selectedKeys, $receipt, $status, $idempotencyKey);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: ProposalStatus, 1: array<string, string>}
     */
    private function applyProfile(User $student, array $items, ChangeSource $source): array
    {
        /** @var OnboardingProfile|null $profile */
        $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->lockForUpdate()->first();

        $conflicts = [];
        foreach ($items as $item) {
            $current = $profile?->getAttribute(StudentFieldRegistry::column($item['key']));

            if (! $this->same($current, $item['before'])) {
                $conflicts[$item['key']] = 'stale';
            }
        }

        // The student (or another flow) changed these values after the proposal was made; never overwrite them...
        if ($conflicts !== []) {
            return [ProposalStatus::Stale, $conflicts];
        }

        $changes = [];
        foreach ($items as $item) {
            $changes[$item['key']] = $item['after'];
        }

        try {
            $result = $this->profiles->update($student, $changes, $profile !== null ? (int) $profile->revision : null, $source);
        } catch (StaleRevisionException) {
            return [ProposalStatus::Stale, array_fill_keys(array_keys($changes), 'stale')];
        }

        $outcomes = [];
        foreach (array_keys($changes) as $key) {
            $outcomes[$key] = array_key_exists($key, $result->changes) ? 'applied' : 'already_present';
        }

        return [ProposalStatus::Applied, $outcomes];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: ProposalStatus, 1: array<string, string>}
     */
    private function applyCollegeAdditions(User $student, array $items, ChangeSource $source): array
    {
        $outcomes = [];

        foreach ($items as $item) {
            [, $outcome] = $this->colleges->addFromProposal(
                $student,
                (string) $item['after']['school_name'],
                (string) $item['after']['tier'],
                $item['reason'] ?? null,
                $source,
            );

            $outcomes[$item['key']] = $outcome;
        }

        return [ProposalStatus::Applied, $outcomes];
    }

    /**
     * The student changed some proposed values before approving: record a new proposal revision so the
     * decision authorizes exactly what they saw.
     *
     * @param  list<string>  $selectedKeys
     * @param  array<string, mixed>  $edits
     */
    private function applyEdits(Proposal $proposal, array $selectedKeys, array $edits): void
    {
        if ($proposal->type !== Proposal::TYPE_PROFILE_UPDATE) {
            throw ValidationException::withMessages(['edits' => 'Only profile proposals can be edited before approval.']);
        }

        $edits = array_intersect_key($edits, array_flip($selectedKeys));
        Validator::make($edits, StudentFieldRegistry::rules(array_keys($edits)))->validate();

        $before = $proposal->items;
        $items = array_map(function (array $item) use ($edits) {
            if (array_key_exists($item['key'], $edits)) {
                $item['after'] = StudentFieldRegistry::normalize($item['key'], $edits[$item['key']]);
                $item['edited_by_student'] = true;
            }

            return $item;
        }, $proposal->items);

        $proposal->items = $items;
        $proposal->revision = $proposal->revision + 1;
        $proposal->payload_hash = Proposal::hashItems($proposal->type, $items, $proposal->revision);
        $proposal->save();

        $this->audit->record((int) $proposal->user_id, ChangeSource::system('proposal.edit'), 'proposal.edited', $proposal, $proposal->revision - 1, $proposal->revision, [
            'items' => [$before, $items],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function create(User $student, string $type, string $title, array $items, ?int $targetRevision, string $actor, ?string $conversationId): Proposal
    {
        $proposal = Proposal::query()->create([
            'user_id' => $student->getKey(),
            'conversation_id' => $conversationId,
            'actor' => $actor,
            'type' => $type,
            'status' => ProposalStatus::Pending,
            'title' => $title,
            'items' => $items,
            'target_revision' => $targetRevision,
            'revision' => 1,
            'payload_hash' => Proposal::hashItems($type, $items, 1),
            'expires_at' => now()->addDays((int) config('admitme.approvals.expires_after_days', 7)),
        ]);

        $this->audit->record((int) $student->getKey(), new ChangeSource(ChangeSource::ACTOR_AGENT, $actor, ChangeSource::SOURCE_AGENT_TOOL), 'proposal.created', $proposal, null, 1, [
            'items' => [null, $items],
        ], ['type' => $type]);

        return $proposal;
    }

    /**
     * @param  list<string>  $selectedKeys
     * @param  array<string, string>  $receipt
     */
    private function record(User $student, Proposal $proposal, string $decision, array $selectedKeys, array $receipt, ProposalStatus $status, ?string $idempotencyKey): ProposalDecision
    {
        $record = ProposalDecision::query()->create([
            'proposal_id' => $proposal->getKey(),
            'user_id' => $student->getKey(),
            'decision' => $decision,
            'selected_keys' => $selectedKeys,
            'proposal_revision' => $proposal->revision,
            'payload_hash' => $proposal->payload_hash,
            'idempotency_key' => $idempotencyKey,
            'receipt' => $receipt,
        ]);

        $proposal->update(['status' => $status]);

        $this->audit->record((int) $student->getKey(), ChangeSource::studentManual($student, 'approvals.decide'), "proposal.{$status->value}", $proposal, $proposal->revision, $proposal->revision, [
            'status' => [ProposalStatus::Pending->value, $status->value],
        ], ['decision' => $decision, 'receipt' => $receipt]);

        return $record;
    }

    private function same(mixed $a, mixed $b): bool
    {
        $normalize = fn (mixed $value) => is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))
            ? (string) (0 + $value)
            : $value;

        return json_encode(is_array($a) ? array_map($normalize, $a) : $normalize($a)) === json_encode(is_array($b) ? array_map($normalize, $b) : $normalize($b));
    }
}
