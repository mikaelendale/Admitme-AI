<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Context\ChatMode;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Tools\ManageTasksTool;
use App\Ai\Tools\ProposeDocumentValueDecisionsTool;
use App\Ai\Tools\ReadPendingDocumentValuesTool;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentFieldRegistry;
use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\Playbooks;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentTask;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * The mentor can work through values read from the student's documents — reading them as unconfirmed
 * candidates and proposing accept/reject decisions the student approves — and can pause, resume, retry or
 * cancel the student's own background plans on request, like the task panel.
 */
class AgentDocumentAndTaskToolsTest extends TestCase
{
    use RefreshDatabase;

    private const GOAL = 'Research affordable options and prepare a shortlist';

    private User $student;

    private Document $transcript;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create(['gpa_value' => '3.60', 'sat_score' => null, 'curriculum' => null]);
        $this->transcript = $this->document($this->student, 'transcript-v1');
    }

    private function document(User $owner, string $title): Document
    {
        $document = Document::query()->create(['user_id' => $owner->id, 'kind' => 'transcript', 'title' => $title]);
        $this->newVersion($document, 1);

        return $document->refresh();
    }

    private function newVersion(Document $document, int $number): DocumentVersion
    {
        $version = DocumentVersion::query()->create([
            'document_id' => $document->id, 'user_id' => $document->user_id, 'version' => $number, 'disk' => 'local',
            'path' => "documents/{$document->user_id}/{$document->id}-v{$number}.pdf", 'original_name' => "{$document->title}.pdf",
            'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat((string) $number, 64), 'status' => 'needs_review', 'page_count' => 2,
        ]);
        $document->update(['current_version_id' => $version->id]);

        return $version;
    }

    /**
     * A synthetic value read from a document page, waiting for the owner's review.
     */
    private function value(Document $document, string $field, mixed $value, int $page, string $quote): StudentFact
    {
        $profile = OnboardingProfile::query()->where('user_id', $document->user_id)->first();
        $current = $profile?->getAttribute(StudentFieldRegistry::column($field));

        return StudentFact::query()->create([
            'user_id' => $document->user_id,
            'field_key' => $field,
            'value' => $value,
            'status' => $current !== null ? FactStatus::Conflict : FactStatus::Candidate,
            'source_type' => ChangeSource::SOURCE_DOCUMENT,
            'source_ref' => ['document_id' => $document->id, 'document_version_id' => $document->current_version_id, 'version' => 1, 'page' => $page, 'quote' => $quote, 'ambiguous' => false],
            'actor_type' => ChangeSource::ACTOR_SYSTEM,
            'profile_revision' => (int) ($profile?->revision ?? 0),
            'review_state' => StudentFact::REVIEW_PENDING,
        ]);
    }

    private function profile(?User $owner = null): OnboardingProfile
    {
        return OnboardingProfile::query()->where('user_id', ($owner ?? $this->student)->id)->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $decisions
     * @return array<string, mixed>
     */
    private function proposeDecisions(array $decisions): array
    {
        return json_decode((string) (new ProposeDocumentValueDecisionsTool($this->student))->handle(new Request(['decisions' => $decisions])), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(StudentFact $fact, string $action, ?string $edited = null, ?string $reason = null): array
    {
        return ['value_id' => $fact->id, 'action' => $action, 'edited_value' => $edited, 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function proposal(array $result): Proposal
    {
        $this->assertSame('pending_approval', $result['status'] ?? null, json_encode($result) ?: '');

        return Proposal::query()->findOrFail($result['proposal_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function decide(Proposal $proposal, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->student)->postJson(route('approvals.decide', $proposal), [
            'decision' => 'approve',
            'selected_keys' => array_column($proposal->items, 'key'),
            'proposal_revision' => $proposal->revision,
            'payload_hash' => $proposal->payload_hash,
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manage(string $action, string $task, ?bool $confirmed = null, ?User $as = null): array
    {
        return json_decode((string) (new ManageTasksTool($as ?? $this->student))->handle(new Request(['action' => $action, 'task' => $task, 'confirmed' => $confirmed])), true);
    }

    /**
     * A plan as the playbook starts it. With the queue faked, its first steps are claimed (running) and
     * the steps that depend on them wait (queued).
     */
    private function plan(?User $owner = null, string $goal = self::GOAL): AgentTask
    {
        Queue::fake();

        return app(Orchestrator::class)->plan($owner ?? $this->student, $goal, Playbooks::get(Playbooks::AFFORDABLE_SHORTLIST)['steps'], 'mentor');
    }

    private function step(AgentTask $goal, string $key): AgentTask
    {
        return $goal->steps()->where('step_key', $key)->sole();
    }

    public function test_the_read_tool_lists_only_the_students_own_values_waiting_for_review(): void
    {
        $gpa = $this->value($this->transcript, 'gpa_value', '3.85', 1, 'Cumulative GPA: 3.85');
        $sat = $this->value($this->transcript, 'sat_score', 1520, 2, 'SAT total: 1520');
        $this->value($this->transcript, 'curriculum', 'IB Diploma', 1, 'Programme: IB Diploma')->update(['review_state' => StudentFact::REVIEW_ACCEPTED]);

        $other = User::factory()->create();
        OnboardingProfile::factory()->for($other)->create();
        $this->value($this->document($other, 'their transcript'), 'gpa_value', '2.10', 1, 'GPA 2.10');

        $read = json_decode((string) (new ReadPendingDocumentValuesTool($this->student))->handle(new Request(['document' => null])), true);

        $this->assertSame([$gpa->id, $sat->id], array_column($read['values'], 'id'), 'Only this student\'s values still waiting for review.');
        $this->assertSame([
            'id' => $gpa->id,
            'field' => 'gpa_value',
            'label' => 'GPA / grade average (as reported)',
            'extracted_value' => '3.85',
            'profile_value' => '3.60',
            'document' => 'transcript-v1',
            'page' => 1,
            'quote' => 'Cumulative GPA: 3.85',
        ], $read['values'][0]);
        $this->assertSame('1520', $read['values'][1]['extracted_value']);
        $this->assertNull($read['values'][1]['profile_value']);
        $this->assertSame(2, $read['waiting_total']);
        $this->assertStringContainsString('Not confirmed', $read['message']);

        $filtered = json_decode((string) (new ReadPendingDocumentValuesTool($this->student))->handle(new Request(['document' => 'resume'])), true);
        $this->assertSame([], $filtered['values']);

        $this->assertSame(0, Proposal::query()->count(), 'Reading changes nothing.');
    }

    public function test_accepting_values_through_a_proposal_updates_the_profile_and_accepts_the_facts(): void
    {
        $gpa = $this->value($this->transcript, 'gpa_value', '3.85', 1, 'Cumulative GPA: 3.85');
        $sat = $this->value($this->transcript, 'sat_score', 1520, 2, 'SAT total: 1520');

        $proposal = $this->proposal($this->proposeDecisions([
            $this->decision($gpa, 'accept', null, 'The transcript shows 3.85.'),
            $this->decision($sat, 'accept', '1510', 'The student says the official score is 1510.'),
        ]));

        $this->assertSame(Proposal::TYPE_DOCUMENT_VALUES, $proposal->type);
        $this->assertSame('Save 2 values from transcript-v1 to your profile', $proposal->title);
        $this->assertSame(["fact:{$gpa->id}", "fact:{$sat->id}"], array_column($proposal->items, 'key'));
        $this->assertSame('GPA / grade average (as reported) · transcript-v1, p.1', $proposal->items[0]['label']);
        $this->assertSame('3.60', (string) $this->profile()->gpa_value, 'Nothing changes before approval.');
        $this->assertSame(StudentFact::REVIEW_PENDING, $gpa->fresh()->review_state);

        $card = $this->actingAs($this->student)->getJson(route('approvals.show', $proposal))->assertOk()->json('proposal');
        $this->assertSame(['3.60', '3.85'], [$card['items'][0]['before'], $card['items'][0]['after']]);
        $this->assertSame(['—', '1510'], [$card['items'][1]['before'], $card['items'][1]['after']]);
        $this->assertSame('“Cumulative GPA: 3.85”', $card['items'][0]['evidence'][0]['text']);
        $this->assertSame('transcript-v1, page 1 — read as 3.85', $card['items'][0]['evidence'][0]['basis']);

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath("proposal.decision.receipt.fact:{$gpa->id}", 'applied')
            ->assertJsonPath("proposal.decision.receipt.fact:{$sat->id}", 'applied');

        $profile = $this->profile();
        $this->assertSame('3.85', (string) $profile->gpa_value);
        $this->assertSame(1510, $profile->sat_score, 'The corrected value is saved, not the one read.');
        $this->assertSame(StudentFact::REVIEW_ACCEPTED, $gpa->fresh()->review_state);
        $this->assertSame(StudentFact::REVIEW_ACCEPTED, $sat->fresh()->review_state);

        $confirmed = StudentFact::query()->where('user_id', $this->student->id)->where('field_key', 'gpa_value')->current()->latest('id')->firstOrFail();
        $this->assertSame(FactStatus::Confirmed, $confirmed->status);
        $this->assertSame(ChangeSource::SOURCE_DOCUMENT, $confirmed->source_type);
        $this->assertSame($this->transcript->id, $confirmed->source_ref['document_id']);
        $this->assertSame($gpa->id, $confirmed->source_ref['candidate_id']);
    }

    public function test_rejecting_a_value_leaves_the_profile_alone(): void
    {
        $curriculum = $this->value($this->transcript, 'curriculum', 'IB Diploma', 1, 'Programme: IB Diploma Programme');

        $proposal = $this->proposal($this->proposeDecisions([$this->decision($curriculum, 'reject', null, 'The student follows A-levels, not IB.')]));

        $this->assertSame('Reject 1 value read from transcript-v1', $proposal->title);

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.items.0.after', 'Rejected — not saved')
            ->assertJsonPath("proposal.decision.receipt.fact:{$curriculum->id}", 'applied');

        $this->assertNull($this->profile()->curriculum);
        $this->assertSame(1, $this->profile()->revision);
        $this->assertSame(StudentFact::REVIEW_REJECTED, $curriculum->fresh()->review_state);
    }

    public function test_bad_decisions_come_back_as_errors_the_model_can_act_on(): void
    {
        $sat = $this->value($this->transcript, 'sat_score', 1520, 2, 'SAT total: 1520');
        $gpa = $this->value($this->transcript, 'gpa_value', '3.85', 1, 'Cumulative GPA: 3.85');
        $secondGpa = $this->value($this->transcript, 'gpa_value', '3.9', 2, 'Weighted GPA 3.9');

        $result = $this->proposeDecisions([
            $this->decision($sat, 'accept', '1700'),
            $this->decision($gpa, 'accept'),
            $this->decision($secondGpa, 'accept'),
        ]);

        $this->assertSame('not_proposed', $result['status']);
        $this->assertStringContainsString('SAT score: "1700" is not a valid value', $result['errors'][0]);
        $this->assertStringContainsString('More than one value for GPA / grade average (as reported)', $result['errors'][1]);
        $this->assertSame([$sat->id, $gpa->id, $secondGpa->id], array_column($result['values_waiting'], 'id'));
        $this->assertSame(0, Proposal::query()->count());
    }

    public function test_a_value_reviewed_or_replaced_since_the_proposal_is_stale(): void
    {
        $gpa = $this->value($this->transcript, 'gpa_value', '3.85', 1, 'Cumulative GPA: 3.85');
        $sat = $this->value($this->transcript, 'sat_score', 1520, 2, 'SAT total: 1520');

        $proposal = $this->proposal($this->proposeDecisions([$this->decision($gpa, 'accept'), $this->decision($sat, 'accept')]));

        // The student rejects the GPA on the Documents page while the proposal waits, and then edits their SAT by hand...
        $this->actingAs($this->student)->post(route('documents.values.decide', $gpa), ['action' => 'reject'])->assertSessionHasNoErrors();
        $this->actingAs($this->student)->post('/profile-completion/clarify', ['sat_score' => 1490, 'expected_revision' => $this->profile()->revision])->assertSessionHasNoErrors();

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'stale')
            ->assertJsonPath("proposal.decision.receipt.fact:{$gpa->id}", 'stale')
            ->assertJsonPath("proposal.decision.receipt.fact:{$sat->id}", 'stale');

        $this->assertSame('3.60', (string) $this->profile()->gpa_value);
        $this->assertSame(1490, $this->profile()->sat_score, 'The newer manual edit is never overwritten.');
        $this->assertSame(StudentFact::REVIEW_REJECTED, $gpa->fresh()->review_state);
        $this->assertSame(StudentFact::REVIEW_PENDING, $sat->fresh()->review_state, 'Still waiting for the student on the Documents page.');

        // A value read from a version of the document that was replaced since cannot be decided either...
        $curriculum = $this->value($this->transcript, 'curriculum', 'IB Diploma', 1, 'Programme: IB Diploma Programme');
        $next = $this->proposal($this->proposeDecisions([$this->decision($curriculum, 'accept')]));
        $this->newVersion($this->transcript, 2);

        $this->decide($next)->assertOk()->assertJsonPath('proposal.status', 'stale');
        $this->assertNull($this->profile()->curriculum);
    }

    public function test_another_students_values_cannot_be_targeted(): void
    {
        $mine = $this->value($this->transcript, 'gpa_value', '3.85', 1, 'Cumulative GPA: 3.85');
        $other = User::factory()->create();
        OnboardingProfile::factory()->for($other)->create(['gpa_value' => null]);
        $theirs = $this->value($this->document($other, 'their transcript'), 'gpa_value', '2.10', 1, 'GPA 2.10');

        $result = $this->proposeDecisions([$this->decision($theirs, 'accept')]);

        $this->assertSame('not_proposed', $result['status']);
        $this->assertStringContainsString("Value {$theirs->id} is not one of the student's document values waiting for review", $result['errors'][0]);
        $this->assertSame([$mine->id], array_column($result['values_waiting'], 'id'));
        $this->assertSame(0, Proposal::query()->count());

        // Even a stored item pointing at their value (never produced by the service) cannot decide it...
        $items = [['key' => "fact:{$theirs->id}", 'label' => 'GPA', 'before' => null, 'after' => '2.10', 'action' => 'accept', 'fact_id' => $theirs->id, 'field' => 'gpa_value', 'edited_value' => null]];
        $forged = Proposal::query()->create([
            'user_id' => $this->student->id, 'actor' => 'test', 'type' => Proposal::TYPE_DOCUMENT_VALUES, 'status' => ProposalStatus::Pending,
            'title' => 'Forged', 'items' => $items, 'revision' => 1, 'payload_hash' => Proposal::hashItems(Proposal::TYPE_DOCUMENT_VALUES, $items, 1), 'expires_at' => now()->addDay(),
        ]);

        $this->decide($forged)->assertOk()->assertJsonPath("proposal.decision.receipt.fact:{$theirs->id}", 'stale');
        $this->assertSame(StudentFact::REVIEW_PENDING, $theirs->fresh()->review_state);
        $this->assertNull($this->profile($other)->gpa_value);
        $this->assertNull(OnboardingProfile::query()->where('user_id', $this->student->id)->value('sat_score'));
    }

    public function test_ask_mode_reads_document_values_but_cannot_decide_them_or_manage_tasks(): void
    {
        $names = fn (ChatMode $mode) => array_map(fn (object $tool) => class_basename($tool), app(MentorToolPolicy::class)->toolsFor($this->student, $mode));

        $this->assertContains('ReadPendingDocumentValuesTool', $names(ChatMode::Ask));
        $this->assertNotContains('ProposeDocumentValueDecisionsTool', $names(ChatMode::Ask));
        $this->assertNotContains('ManageTasksTool', $names(ChatMode::Ask));

        foreach (['ReadPendingDocumentValuesTool', 'ProposeDocumentValueDecisionsTool', 'ManageTasksTool'] as $tool) {
            $this->assertContains($tool, $names(ChatMode::Agent));
        }
    }

    public function test_the_approvals_context_names_applied_items_as_the_student_saw_them(): void
    {
        $gpa = $this->value($this->transcript, 'gpa_value', '3.85', 1, 'Cumulative GPA: 3.85');
        $proposal = $this->proposal($this->proposeDecisions([$this->decision($gpa, 'accept')]));
        $this->decide($proposal)->assertOk();

        $context = app(ContextBuilder::class)->forMentor($this->student, ChatMode::Agent)->instructions;

        $this->assertStringContainsString('- Approved: Save 1 value from transcript-v1 to your profile — applied: GPA / grade average (as reported) · transcript-v1, p.1', $context);
        $this->assertStringNotContainsString("fact:{$gpa->id}", $context);
    }

    public function test_pausing_and_resuming_a_plan_by_its_title(): void
    {
        $goal = $this->plan();
        $this->assertSame(TaskStatus::Queued, $this->step($goal, 'affordability')->status);

        $paused = $this->manage('pause', 'research affordable options and prepare a shortlist');

        $this->assertSame('task_updated', $paused['status']);
        $this->assertSame(self::GOAL, $paused['plan']);
        $this->assertSame(TaskStatus::Paused, $this->step($goal, 'affordability')->status);
        $this->assertSame(TaskStatus::Paused, $this->step($goal, 'proposal')->status);
        $this->assertSame(TaskStatus::Running, $this->step($goal, 'research')->status, 'A running step finishes its attempt.');
        $this->assertSame('Paused', collect($paused['steps'])->firstWhere('step', $this->step($goal, 'affordability')->title)['status']);
        $this->assertSame($goal->refresh()->status->label(), $paused['plan_status']);

        $this->assertSame('not_changed', $this->manage('pause', self::GOAL)['status'], 'Nothing left to pause.');

        $resumed = $this->manage('resume', self::GOAL);

        $this->assertSame('task_updated', $resumed['status']);
        $this->assertSame(TaskStatus::Queued, $this->step($goal, 'affordability')->status);
        $this->assertTrue($this->step($goal, 'affordability')->events()->where('type', 'resumed')->exists());
        $this->assertSame('not_changed', $this->manage('resume', self::GOAL)['status'], 'Nothing left to resume.');
    }

    public function test_retrying_a_failed_step_by_the_plans_id(): void
    {
        $goal = $this->plan();
        $refresh = $this->step($goal, 'refresh');
        $refresh->forceFill(['status' => TaskStatus::Failed, 'status_reason' => 'The page could not be reached.', 'finished_at' => now()])->save();

        $result = $this->manage('retry', $goal->id);

        $this->assertSame('task_updated', $result['status']);
        $this->assertSame('retry', $result['action']);
        $this->assertNotSame(TaskStatus::Failed, $refresh->refresh()->status);
        $this->assertTrue($refresh->events()->where('type', 'retried')->exists());
        $this->assertSame('not_changed', $this->manage('retry', $goal->id)['status'], 'Nothing failed or blocked any more.');
    }

    public function test_cancelling_needs_the_students_confirmation_and_cannot_be_undone(): void
    {
        $goal = $this->plan();

        $unconfirmed = $this->manage('cancel', self::GOAL);

        $this->assertSame('confirmation_needed', $unconfirmed['status']);
        $this->assertStringContainsString('cannot be undone', $unconfirmed['message']);
        $this->assertNotSame(TaskStatus::Cancelled, $goal->refresh()->status, 'Nothing changes without confirmation.');

        $cancelled = $this->manage('cancel', self::GOAL, true);

        $this->assertSame('task_updated', $cancelled['status']);
        $this->assertSame(TaskStatus::Cancelled, $goal->refresh()->status);
        $this->assertStringStartsWith('Cancelled', $cancelled['plan_status']);
        $this->assertSame(TaskStatus::Cancelled, $this->step($goal, 'affordability')->status);
        $this->assertNotNull($this->step($goal, 'research')->cancel_requested_at, 'A running step is asked to stop.');

        $this->assertSame('not_changed', $this->manage('cancel', self::GOAL, true)['status']);
        $retry = $this->manage('retry', self::GOAL);
        $this->assertSame('not_changed', $retry['status']);
        $this->assertStringContainsString('cancelled', $retry['message']);
    }

    public function test_another_students_plans_cannot_be_managed(): void
    {
        $mine = $this->plan();
        $other = User::factory()->create();
        $theirs = $this->plan($other, 'Find engineering scholarships');

        $byId = $this->manage('cancel', $theirs->id, true);
        $byTitle = $this->manage('pause', 'Find engineering scholarships');

        $this->assertSame('not_found', $byId['status']);
        $this->assertSame('not_found', $byTitle['status']);
        $this->assertSame([self::GOAL.' ('.$mine->refresh()->status->label().')'], $byTitle['plans']);
        $this->assertNotSame(TaskStatus::Cancelled, $theirs->refresh()->status);
        $this->assertSame(TaskStatus::Queued, $this->step($theirs, 'affordability')->status);

        // Their own plan is theirs to manage...
        $this->assertSame('task_updated', $this->manage('pause', 'Find engineering scholarships', null, $other)['status']);
        $this->assertSame(TaskStatus::Paused, $this->step($theirs, 'affordability')->status);
    }
}
