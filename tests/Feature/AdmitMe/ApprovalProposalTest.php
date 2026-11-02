<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Context\ChatMode;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Tools\ProposeProfileChangesTool;
use App\Domain\Approvals\ProposalService;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Models\AuditEvent;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\ProposalDecision;
use App\Models\StudentFact;
use App\Models\User;
use App\Support\MentorChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Phase 3 gate: agent-originated changes are durable proposals; approving applies exactly the reviewed
 * items once; rejecting changes nothing; a concurrent manual edit makes a conflicting proposal stale.
 */
class ApprovalProposalTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create(['sat_score' => 1400, 'act_score' => 30, 'gpa_value' => '3.60']);
    }

    private function profile(): OnboardingProfile
    {
        return OnboardingProfile::query()->where('user_id', $this->student->id)->firstOrFail();
    }

    private function proposeScores(): Proposal
    {
        return app(ProposalService::class)->proposeProfileChanges($this->student, [
            'sat_score' => ['value' => 1500, 'reason' => 'Student said they scored 1500 in October.'],
            'act_score' => ['value' => 34, 'reason' => 'Student mentioned a 34 ACT.'],
        ], 'test-agent');
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

    public function test_proposing_changes_nothing_until_the_student_decides(): void
    {
        $result = json_decode((string) (new ProposeProfileChangesTool($this->student))->handle(new Request([
            'changes' => [['field' => 'sat_score', 'value' => '1500', 'reason' => 'Student said so.']],
        ])), true);

        $this->assertSame('pending_approval', $result['status']);
        $this->assertStringContainsString('Nothing has changed yet', $result['message']);
        $this->assertSame(1400, $this->profile()->sat_score);
        $this->assertSame(1, $this->profile()->revision);
        $this->assertSame(ProposalStatus::Pending, Proposal::query()->findOrFail($result['proposal_id'])->status);
    }

    public function test_approving_a_profile_edit_changes_exactly_the_reviewed_fields_once(): void
    {
        $proposal = $this->proposeScores();

        $this->decide($proposal, ['selected_keys' => ['sat_score'], 'idempotency_key' => 'click-1'])
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.decision.receipt.sat_score', 'applied')
            ->assertJsonPath('proposal.decision.receipt.act_score', 'skipped');

        // A double click with the same key, and a retry with a different key, change nothing further...
        $this->decide($proposal, ['selected_keys' => ['sat_score'], 'idempotency_key' => 'click-1'])->assertOk();
        $this->decide($proposal, ['selected_keys' => ['sat_score', 'act_score'], 'idempotency_key' => 'click-2'])
            ->assertOk()
            ->assertJsonPath('proposal.decision.selectedKeys', ['sat_score']);

        $profile = $this->profile();
        $this->assertSame(1500, $profile->sat_score);
        $this->assertSame(30, $profile->act_score, 'Unselected fields are untouched.');
        $this->assertSame('3.60', (string) $profile->gpa_value);
        $this->assertSame(2, $profile->revision, 'Applied exactly once.');
        $this->assertSame(1, ProposalDecision::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'profile.updated')->where('user_id', $this->student->id)->count());

        $fact = StudentFact::query()->where('user_id', $this->student->id)->where('field_key', 'sat_score')->current()->firstOrFail();
        $this->assertSame(ChangeSource::SOURCE_AGENT_PROPOSAL, $fact->source_type);
        $this->assertSame(FactStatus::Confirmed, $fact->status, 'An approved proposal counts as student-confirmed.');
        $this->assertSame($proposal->id, $fact->source_ref['proposal_id']);
    }

    public function test_rejection_changes_nothing(): void
    {
        $proposal = $this->proposeScores();

        $this->decide($proposal, ['decision' => 'reject'])
            ->assertOk()
            ->assertJsonPath('proposal.status', 'rejected');

        $profile = $this->profile();
        $this->assertSame(1400, $profile->sat_score);
        $this->assertSame(30, $profile->act_score);
        $this->assertSame(1, $profile->revision);
    }

    public function test_approving_six_of_ten_proposed_colleges_adds_exactly_six(): void
    {
        $names = array_map(fn (int $i) => "Synthetic Approval College {$i}", range(1, 10));

        $proposal = app(ProposalService::class)->proposeCollegeAdditions(
            $this->student,
            array_map(fn (string $name) => ['school_name' => $name, 'tier' => 'match', 'reason' => 'Fits budget.'], $names),
            'test-agent',
        );

        $this->assertSame(10, count($proposal->items));
        $this->assertSame(0, CollegeListSchool::query()->where('user_id', $this->student->id)->count());

        $chosen = array_slice(array_column($proposal->items, 'key'), 0, 6);

        $this->decide($proposal, ['selected_keys' => $chosen, 'idempotency_key' => 'batch-1'])->assertOk();
        $this->decide($proposal, ['selected_keys' => $chosen, 'idempotency_key' => 'batch-2'])->assertOk();

        $saved = CollegeListSchool::query()->where('user_id', $this->student->id)->get();
        $this->assertCount(6, $saved);
        $this->assertSame(6, $saved->pluck('school_slug')->unique()->count());
        $this->assertSame(array_slice($names, 0, 6), $saved->sortBy('school_name', SORT_NATURAL)->pluck('school_name')->values()->all());
        $this->assertTrue($saved->every(fn (CollegeListSchool $s) => $s->choice_status->value === 'shortlisted'));
    }

    public function test_a_concurrent_manual_edit_makes_a_conflicting_proposal_stale(): void
    {
        $proposal = $this->proposeScores();

        // The student fixes their SAT by hand while the proposal waits...
        $this->actingAs($this->student)
            ->post('/profile-completion/clarify', ['sat_score' => 1450, 'expected_revision' => 1])
            ->assertSessionHasNoErrors();

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'stale')
            ->assertJsonPath('proposal.decision.receipt.sat_score', 'stale');

        $profile = $this->profile();
        $this->assertSame(1450, $profile->sat_score, 'The newer manual edit is never overwritten.');
        $this->assertSame(30, $profile->act_score, 'A stale proposal applies nothing at all.');
    }

    public function test_an_unrelated_manual_edit_does_not_block_the_proposal(): void
    {
        $proposal = $this->proposeScores();

        $this->actingAs($this->student)
            ->post('/profile-completion/clarify', ['location_country' => 'Ghana', 'expected_revision' => 1])
            ->assertSessionHasNoErrors();

        $this->decide($proposal)->assertOk()->assertJsonPath('proposal.status', 'applied');

        $this->assertSame(1500, $this->profile()->sat_score);
        $this->assertSame('Ghana', $this->profile()->location_country);
    }

    public function test_student_edits_create_a_new_revision_and_only_that_value_is_applied(): void
    {
        $proposal = $this->proposeScores();

        $this->decide($proposal, ['selected_keys' => ['sat_score'], 'edits' => ['sat_score' => '1480']])
            ->assertOk()
            ->assertJsonPath('proposal.revision', 2)
            ->assertJsonPath('proposal.status', 'applied');

        $this->assertSame(1480, $this->profile()->sat_score);
    }

    public function test_a_decision_must_match_the_reviewed_payload_and_expired_proposals_cannot_be_approved(): void
    {
        $proposal = $this->proposeScores();

        $this->decide($proposal, ['payload_hash' => str_repeat('0', 64)])->assertStatus(409);
        $this->assertSame(1400, $this->profile()->sat_score);

        $proposal->update(['expires_at' => now()->subMinute()]);

        $this->decide($proposal)->assertStatus(409);
        $this->assertSame(ProposalStatus::Expired, $proposal->fresh()->status);
        $this->assertSame(1400, $this->profile()->sat_score);
    }

    public function test_another_student_cannot_view_or_decide_a_proposal(): void
    {
        $proposal = $this->proposeScores();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson(route('approvals.show', $proposal))->assertNotFound();
        $this->actingAs($other)->postJson(route('approvals.decide', $proposal), [
            'decision' => 'approve', 'selected_keys' => ['sat_score'], 'proposal_revision' => 1, 'payload_hash' => $proposal->payload_hash,
        ])->assertNotFound();

        $this->assertSame(ProposalStatus::Pending, $proposal->fresh()->status);
    }

    public function test_approval_state_survives_a_refresh_and_the_mentor_sees_the_outcome(): void
    {
        $proposal = $this->proposeScores();

        $this->actingAs($this->student)->get(route('approvals.index'))
            ->assertInertia(fn ($page) => $page->component('approvals')->has('pending', 1)->where('pending.0.id', $proposal->id))
            ->assertInertia(fn ($page) => $page->where('pending_approval_count', 1));

        $context = app(ContextBuilder::class)->forMentor($this->student, ChatMode::Agent);
        $this->assertStringContainsString('Waiting for the student (nothing has changed yet)', $context->instructions);

        $this->decide($proposal, ['decision' => 'reject']);

        $context = app(ContextBuilder::class)->forMentor($this->student, ChatMode::Agent);
        $this->assertStringContainsString('Rejected by the student: '.$proposal->title, $context->instructions);
    }

    public function test_agent_mode_proposes_instead_of_writing_and_the_card_reappears_after_reload(): void
    {
        $tools = array_map(fn (object $tool) => class_basename($tool), app(MentorToolPolicy::class)->toolsFor($this->student, ChatMode::Agent));
        $this->assertContains('ProposeCollegeListAdditionsTool', $tools);
        $this->assertNotContains('UpsertStudentCollegeList', $tools, 'Agent mode no longer writes the saved list directly.');

        AdmitMeGeneralAgent::fake([
            new ToolCall('call_1', 'ProposeCollegeListAdditionsTool', ['colleges' => [
                ['school_name' => 'Synthetic Maple College', 'list_type' => 'target', 'reason' => 'Strong CS, within budget.'],
            ]]),
            'I proposed Synthetic Maple College — approve it if you want it on your list.',
        ]);

        $this->actingAs($this->student)
            ->postJson(route('dashboard.chat'), ['message' => 'Add a good CS match', 'mode' => 'agent'])
            ->streamedContent();

        $this->assertSame(0, CollegeListSchool::query()->where('user_id', $this->student->id)->count());
        $proposal = Proposal::query()->where('user_id', $this->student->id)->sole();
        $this->assertSame(ProposalStatus::Pending, $proposal->status);

        $conversationId = (string) DB::table('agent_conversations')->where('participant_id', $this->student->id)->value('id');
        $messages = MentorChatConversation::messagesForDisplay($conversationId, $this->student->id);
        $assistant = collect($messages)->firstWhere('role', 'assistant');

        $this->assertSame([$proposal->id], $assistant['proposal_ids']);
        $this->assertTrue(Str::isUlid($proposal->id));
    }
}
