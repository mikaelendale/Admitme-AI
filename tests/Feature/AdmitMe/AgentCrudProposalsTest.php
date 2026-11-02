<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Agents\RequirementExtractorAgent;
use App\Ai\Context\ChatMode;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Tools\ProposeApplicationChangesTool;
use App\Ai\Tools\ProposeCollegeListChangesTool;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\ChecklistStatus;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Applications\CollegeListService;
use App\Domain\Applications\CompletionBasis;
use App\Domain\Applications\SubmissionEvidence;
use App\Domain\Approvals\ProposalService;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Knowledge\HostResolver;
use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Shared\ChangeSource;
use App\Jobs\IngestApprovedSourceJob;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\AuditEvent;
use App\Models\CollegeListSchool;
use App\Models\KnowledgeSource;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * The mentor can change any part of the student's list and applications — always as a proposal: nothing
 * changes until the student approves, each approved item goes through the same domain service as a manual
 * edit, a value changed since the proposal is never overwritten, and another student's records are out of reach.
 */
class AgentCrudProposalsTest extends TestCase
{
    use RefreshDatabase;

    private const COLLEGE = 'Synthetic Prairie University';

    private const PAGE_URL = 'https://admissions.synthetic-prairie.edu/apply';

    private User $student;

    private CollegeListSchool $school;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never touch real DNS: every host "resolves" to a public documentation address...
        $this->resolveHostsTo('93.184.216.34');

        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create();
        $this->school = CollegeListSchool::factory()->for($this->student)->create([
            'school_name' => self::COLLEGE,
            'school_slug' => 'synthetic-prairie-university',
            'tier' => 'match',
        ]);
    }

    private function resolveHostsTo(string $address): void
    {
        $this->app->instance(HostResolver::class, new class($address) extends HostResolver
        {
            public function __construct(private readonly string $address) {}

            public function addresses(string $host): array
            {
                return [$this->address];
            }
        });
    }

    /**
     * A change as the model sends it: every schema key present, unused ones null.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function listChange(array $overrides): array
    {
        return ['college' => self::COLLEGE, 'action' => 'update', 'field' => null, 'value' => null, 'url' => null, 'official' => null, 'reason' => 'The student asked for it.', 'evidence' => null, ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applicationChange(array $overrides): array
    {
        return [
            'college' => self::COLLEGE, 'action' => 'update', 'field' => null, 'value' => null, 'submission_evidence_type' => null,
            'checklist_item' => null, 'checklist_status' => null, 'completion_basis' => null, 'note' => null,
            'title' => null, 'due_date' => null, 'details' => null, 'reason' => 'The student told me.', ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    private function proposeListChanges(array $changes, ?User $as = null): array
    {
        return json_decode((string) (new ProposeCollegeListChangesTool($as ?? $this->student))->handle(new Request(['changes' => $changes])), true);
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    private function proposeApplicationChanges(array $changes): array
    {
        return json_decode((string) (new ProposeApplicationChangesTool($this->student))->handle(new Request(['changes' => $changes])), true);
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

    private function openApplication(): Application
    {
        app(CollegeListService::class)->update($this->school, ['choice_status' => CollegeChoiceStatus::Applying->value], null, ChangeSource::studentManual($this->student, 'test'));

        return Application::query()->where('college_list_school_id', $this->school->id)->sole();
    }

    public function test_a_deadline_update_waits_for_approval_then_fills_the_deadline_tracker(): void
    {
        $result = $this->proposeListChanges([
            $this->listChange(['field' => 'rd_deadline', 'value' => '2027-01-15', 'evidence' => [
                ['text' => 'Regular Decision: January 15, 2027', 'basis' => 'Official admissions website', 'url' => 'https://admissions.synthetic-prairie.edu/deadlines'],
            ]]),
            $this->listChange(['field' => 'ea_deadline', 'value' => '2026-11-01']),
        ]);

        $proposal = $this->proposal($result);
        $this->assertStringContainsString('Nothing has changed yet', $result['message']);
        $this->assertSame([self::COLLEGE.' · Regular Decision deadline', self::COLLEGE.' · Early Action deadline'], $result['proposed']);
        $this->assertSame(Proposal::TYPE_COLLEGE_LIST_UPDATE, $proposal->type);
        $this->assertSame('Update deadlines for '.self::COLLEGE, $proposal->title);
        $this->assertSame(['synthetic-prairie-university:rd_deadline', 'synthetic-prairie-university:ea_deadline'], array_column($proposal->items, 'key'));
        $this->assertNull($this->school->fresh()->rd_deadline, 'Nothing changes before approval.');

        // The card shows readable values and what the new value rests on...
        $card = $this->actingAs($this->student)->getJson(route('approvals.show', $proposal))->assertOk()->json('proposal');
        $this->assertSame('—', $card['items'][0]['before']);
        $this->assertSame('Jan 15, 2027', $card['items'][0]['after']);
        $this->assertSame('https://admissions.synthetic-prairie.edu/deadlines', $card['items'][0]['evidence'][0]['url']);
        $this->assertNull($card['items'][0]['editValue']);

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:rd_deadline', 'applied')
            ->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:ea_deadline', 'applied');

        $school = $this->school->fresh();
        $this->assertSame('2027-01-15', $school->rd_deadline->toDateString());
        $this->assertSame('2026-11-01', $school->ea_deadline->toDateString());
        $this->assertSame(2, $school->revision, 'Both fields are saved in one write.');

        $audit = AuditEvent::query()->where('action', 'college_choice.updated')->where('subject_id', $school->id)->sole();
        $this->assertSame(ChangeSource::ACTOR_STUDENT, $audit->actor_type);
        $this->assertSame(ChangeSource::SOURCE_AGENT_PROPOSAL, $audit->context['source_type']);
        $this->assertSame($proposal->id, $audit->context['proposal_id']);

        $this->actingAs($this->student)->get(route('college-lists'))
            ->assertInertia(fn ($page) => $page->where('colleges.0.deadlinesDisplay', "EA: Nov 1, 2026\nRD: Jan 15, 2027"));
    }

    public function test_bad_values_and_no_op_changes_come_back_as_errors_the_model_can_act_on(): void
    {
        $this->school->update(['rd_deadline' => '2027-01-15']);

        $invalid = $this->proposeListChanges([
            $this->listChange(['field' => 'rd_deadline', 'value' => 'January 15th']),
            $this->listChange(['field' => 'application_portal_url', 'value' => 'http://apply.synthetic-prairie.edu']),
        ]);

        $this->assertSame('not_proposed', $invalid['status']);
        $this->assertStringContainsString('YYYY-MM-DD', $invalid['errors'][0]);
        $this->assertStringContainsString('https://', $invalid['errors'][1]);
        $this->assertSame([self::COLLEGE], $invalid['colleges_on_list']);

        $same = $this->proposeListChanges([$this->listChange(['field' => 'rd_deadline', 'value' => '2027-01-15'])]);
        $this->assertSame('not_proposed', $same['status']);
        $this->assertStringContainsString('Nothing would change', $same['errors'][0]);

        $this->assertSame(0, Proposal::query()->count());
    }

    public function test_removing_a_college_archives_it_and_keeps_its_history(): void
    {
        $proposal = $this->proposal($this->proposeListChanges([$this->listChange(['action' => 'remove'])]));

        $this->assertSame('Remove '.self::COLLEGE.' from your list', $proposal->title);
        $this->assertSame(CollegeChoiceStatus::Shortlisted, $this->school->fresh()->choice_status);

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.items.0.before', 'On your list (Shortlisted)')
            ->assertJsonPath('proposal.items.0.after', 'Removed — history kept')
            ->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:remove', 'applied');

        $school = $this->school->fresh();
        $this->assertSame(CollegeChoiceStatus::Archived, $school->choice_status);
        $this->assertTrue($school->is_removed);
        $this->assertSame(1, CollegeListSchool::query()->where('user_id', $this->student->id)->count(), 'The row and its history are kept.');
        $this->assertSame(1, AuditEvent::query()->where('action', 'college_choice.updated')->where('subject_id', $school->id)->count());
    }

    public function test_starting_an_application_opens_it_with_its_checklist(): void
    {
        $proposal = $this->proposal($this->proposeListChanges([$this->listChange(['action' => 'start_application'])]));

        $this->assertSame(0, Application::query()->count());

        $this->decide($proposal)->assertOk()->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:start_application', 'applied');

        $application = Application::query()->where('college_list_school_id', $this->school->id)->sole();
        $this->assertSame(ApplicationStatus::Preparing, $application->status);
        $this->assertSame(CollegeChoiceStatus::Applying, $this->school->fresh()->choice_status);
        $this->assertGreaterThan(0, $application->items()->count(), 'The application opens with its checklist.');

        // Proposing it again changes nothing, so no proposal is made...
        $again = $this->proposeListChanges([$this->listChange(['action' => 'start_application'])]);
        $this->assertSame('not_proposed', $again['status']);
        $this->assertStringContainsString('already has an application', $again['errors'][0]);
    }

    public function test_a_value_changed_after_proposing_is_stale_and_never_overwritten(): void
    {
        $proposal = $this->proposal($this->proposeListChanges([
            $this->listChange(['field' => 'rd_deadline', 'value' => '2027-01-15']),
            $this->listChange(['field' => 'tier', 'value' => 'reach']),
        ]));

        // The student types a different deadline on the College list page while the proposal waits...
        $this->actingAs($this->student)
            ->patchJson(route('college-lists.school.update', $this->school), ['rd_deadline' => '2027-01-10', 'expected_revision' => 1])
            ->assertOk();

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:rd_deadline', 'stale')
            ->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:tier', 'applied');

        $school = $this->school->fresh();
        $this->assertSame('2027-01-10', $school->rd_deadline->toDateString(), 'The newer manual edit wins.');
        $this->assertSame('reach', $school->tier);
    }

    public function test_a_proposal_whose_every_item_went_stale_applies_nothing(): void
    {
        $proposal = $this->proposal($this->proposeListChanges([$this->listChange(['action' => 'remove'])]));

        $this->actingAs($this->student)
            ->patchJson(route('college-lists.school.update', $this->school), ['choice_status' => 'applying'])
            ->assertOk();

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'stale')
            ->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:remove', 'stale');

        $this->assertSame(CollegeChoiceStatus::Applying, $this->school->fresh()->choice_status);
    }

    public function test_changes_to_a_college_removed_since_the_proposal_are_stale(): void
    {
        $proposal = $this->proposal($this->proposeListChanges([$this->listChange(['field' => 'rd_deadline', 'value' => '2027-01-15'])]));

        $this->actingAs($this->student)->deleteJson(route('college-lists.school.destroy', $this->school))->assertOk();

        $this->decide($proposal)->assertOk()->assertJsonPath('proposal.status', 'stale');
        $this->assertNull($this->school->fresh()->rd_deadline);
    }

    public function test_rejecting_changes_nothing(): void
    {
        $proposal = $this->proposal($this->proposeListChanges([
            $this->listChange(['field' => 'rd_deadline', 'value' => '2027-01-15']),
            $this->listChange(['action' => 'start_application']),
        ]));

        $this->decide($proposal, ['decision' => 'reject'])->assertOk()->assertJsonPath('proposal.status', 'rejected');

        $school = $this->school->fresh();
        $this->assertNull($school->rd_deadline);
        $this->assertSame(1, $school->revision);
        $this->assertSame(CollegeChoiceStatus::Shortlisted, $school->choice_status);
        $this->assertSame(0, Application::query()->count());
    }

    public function test_another_students_college_cannot_be_targeted(): void
    {
        $other = User::factory()->create();
        $theirs = CollegeListSchool::factory()->for($other)->create(['school_name' => 'Synthetic Harbor College', 'school_slug' => 'synthetic-harbor-college']);

        $result = $this->proposeListChanges([$this->listChange(['college' => 'Synthetic Harbor College', 'field' => 'rd_deadline', 'value' => '2027-01-15'])]);

        $this->assertSame('not_proposed', $result['status']);
        $this->assertStringContainsString('not on the student\'s college list', $result['errors'][0]);
        $this->assertSame([self::COLLEGE], $result['colleges_on_list']);

        try {
            app(ProposalService::class)->proposeCollegeListChanges($this->student, [['college' => 'synthetic-harbor-college', 'action' => 'remove']], 'test-agent');
            $this->fail('Another student\'s college must not resolve.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, Proposal::query()->count());

        // Even a stored item pointing at their row (never produced by the service) cannot touch it...
        $items = [['key' => 'synthetic-harbor-college:remove', 'label' => 'Synthetic Harbor College · Remove', 'before' => 'On your list', 'after' => 'Removed', 'action' => 'remove', 'expect' => 'shortlisted', 'school_id' => (string) $theirs->id, 'college' => 'Synthetic Harbor College']];
        $forged = Proposal::query()->create([
            'user_id' => $this->student->id, 'actor' => 'test', 'type' => Proposal::TYPE_COLLEGE_LIST_UPDATE, 'status' => ProposalStatus::Pending,
            'title' => 'Forged', 'items' => $items, 'revision' => 1, 'payload_hash' => Proposal::hashItems(Proposal::TYPE_COLLEGE_LIST_UPDATE, $items, 1), 'expires_at' => now()->addDay(),
        ]);

        $this->decide($forged)->assertOk()->assertJsonPath('proposal.decision.receipt.synthetic-harbor-college:remove', 'stale');
        $this->assertSame(CollegeChoiceStatus::Shortlisted, $theirs->fresh()->choice_status);

        // The application tool cannot reach their applications either...
        Application::factory()->create(['user_id' => $other->id, 'college_list_school_id' => $theirs->id]);
        $app = $this->proposeApplicationChanges([$this->applicationChange(['college' => 'Synthetic Harbor College', 'field' => 'notes', 'value' => 'x'])]);
        $this->assertSame('not_proposed', $app['status']);
        $this->assertSame([], $app['applications']);
    }

    public function test_ask_mode_gets_neither_new_tool_and_agent_mode_gets_both(): void
    {
        $names = fn (ChatMode $mode) => array_map(fn (object $tool) => class_basename($tool), app(MentorToolPolicy::class)->toolsFor($this->student, $mode));

        $this->assertNotContains('ProposeCollegeListChangesTool', $names(ChatMode::Ask));
        $this->assertNotContains('ProposeApplicationChangesTool', $names(ChatMode::Ask));
        $this->assertContains('ProposeCollegeListChangesTool', $names(ChatMode::Agent));
        $this->assertContains('ProposeApplicationChangesTool', $names(ChatMode::Agent));
    }

    public function test_checklist_step_statuses_follow_the_checklist_rules(): void
    {
        $application = $this->openApplication();
        $form = $application->items()->where('item_key', 'default:application_form')->sole();
        $documents = $application->items()->where('item_key', 'default:school_documents')->sole();

        // Someone else's step is only "received" on the student's report of the portal or the sender...
        $refused = $this->proposeApplicationChanges([
            $this->applicationChange(['action' => 'checklist_status', 'checklist_item' => 'Complete the application form', 'checklist_status' => 'done']),
            $this->applicationChange(['action' => 'checklist_status', 'checklist_item' => 'School transcript and school report', 'checklist_status' => 'received']),
        ]);
        $this->assertSame('not_proposed', $refused['status']);
        $this->assertStringContainsString('completion_basis', implode(' ', $refused['errors']));
        $this->assertSame([self::COLLEGE], $refused['applications']);

        $proposal = $this->proposal($this->proposeApplicationChanges([
            $this->applicationChange(['action' => 'checklist_status', 'checklist_item' => 'Complete the application form', 'checklist_status' => 'done']),
            $this->applicationChange(['action' => 'checklist_status', 'checklist_item' => 'School transcript and school report', 'checklist_status' => 'received', 'completion_basis' => 'sender_confirmed']),
        ]));

        $this->assertSame(Proposal::TYPE_APPLICATION_UPDATE, $proposal->type);
        $this->assertSame("synthetic-prairie-university:step:{$form->id}", $proposal->items[0]['key']);
        $this->assertSame(ChecklistStatus::Todo, $form->fresh()->status, 'Nothing changes before approval.');

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.items.0.before', 'To do')
            ->assertJsonPath('proposal.items.0.after', 'Done (you marked it)')
            ->assertJsonPath('proposal.items.1.after', 'Received — The sender told you it was sent');

        $form->refresh();
        $documents->refresh();
        $this->assertSame(ChecklistStatus::Done, $form->status);
        $this->assertSame(CompletionBasis::StudentReported, $form->completion_basis);
        $this->assertSame(ChecklistStatus::Received, $documents->status);
        $this->assertSame(CompletionBasis::SenderConfirmed, $documents->completion_basis);
        $this->assertSame(2, AuditEvent::query()->where('action', 'application_item.updated')->where('context->source_type', ChangeSource::SOURCE_AGENT_PROPOSAL)->count());
    }

    public function test_a_step_changed_since_the_proposal_is_stale(): void
    {
        $application = $this->openApplication();
        $form = $application->items()->where('item_key', 'default:application_form')->sole();

        $proposal = $this->proposal($this->proposeApplicationChanges([
            $this->applicationChange(['action' => 'checklist_status', 'checklist_item' => 'Complete the application form', 'checklist_status' => 'done']),
        ]));

        $this->actingAs($this->student)
            ->patch(route('applications.items.update', [$application->id, $form->id]), ['status' => 'blocked', 'status_note' => 'Waiting for my counselor'])
            ->assertSessionHasNoErrors();

        $this->decide($proposal)->assertOk()->assertJsonPath('proposal.status', 'stale');
        $this->assertSame(ChecklistStatus::Blocked, $form->fresh()->status);
    }

    public function test_marking_submitted_needs_evidence_and_moves_through_ready_to_submit(): void
    {
        $application = $this->openApplication();

        $missing = $this->proposeApplicationChanges([$this->applicationChange(['field' => 'status', 'value' => 'submitted'])]);
        $this->assertSame('not_proposed', $missing['status']);
        $this->assertStringContainsString('submission_evidence_type', $missing['errors'][0]);

        $noChange = $this->proposeApplicationChanges([$this->applicationChange(['field' => 'status', 'value' => 'preparing'])]);
        $this->assertStringContainsString('Nothing would change', $noChange['errors'][0]);

        $proposal = $this->proposal($this->proposeApplicationChanges([
            $this->applicationChange(['field' => 'status', 'value' => 'submitted', 'submission_evidence_type' => 'student_confirmation', 'note' => 'Submitted it on Friday and got a confirmation email.']),
            $this->applicationChange(['field' => 'notes', 'value' => 'Interview invitations go out in February.']),
            $this->applicationChange(['action' => 'add_checklist_item', 'title' => 'Send the mid-year report', 'due_date' => '2027-02-15', 'details' => 'Ask the counselor in January.']),
        ]));

        $this->assertSame('Update your '.self::COLLEGE.' application (3 changes)', $proposal->title);

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.items.0.before', 'Preparing')
            ->assertJsonPath('proposal.items.0.after', 'Submitted — evidence: Student confirmation: “Submitted it on Friday and got a confirmation email.”')
            ->assertJsonPath('proposal.items.2.after', 'Send the mid-year report — due Feb 15, 2027');

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);
        $this->assertSame(SubmissionEvidence::StudentConfirmation, $application->submission_evidence_type);
        $this->assertSame('Submitted it on Friday and got a confirmation email.', $application->submission_evidence_note);
        $this->assertNotNull($application->submitted_at);
        $this->assertSame('Interview invitations go out in February.', $application->notes);

        $step = ApplicationItem::query()->where('application_id', $application->id)->where('title', 'Send the mid-year report')->sole();
        $this->assertSame(ApplicationItem::ORIGIN_MANUAL, $step->origin);
        $this->assertSame('2027-02-15', $step->due_date->toDateString());

        // Each move went through the application service's own transition rules...
        $moves = AuditEvent::query()->where('action', 'application.updated')->where('subject_id', $application->id)->orderBy('id')->get()
            ->map(fn (AuditEvent $e) => $e->changes['status']['after'] ?? null)->filter()->values()->all();
        $this->assertSame(['ready_to_submit', 'submitted'], $moves);
    }

    public function test_application_changes_for_a_college_without_an_application_point_to_start_application(): void
    {
        $result = $this->proposeApplicationChanges([$this->applicationChange(['field' => 'notes', 'value' => 'Visit in spring'])]);

        $this->assertSame('not_proposed', $result['status']);
        $this->assertStringContainsString('has no application yet', $result['errors'][0]);
        $this->assertStringContainsString('start_application', $result['errors'][0]);
    }

    public function test_add_source_goes_through_the_url_policy(): void
    {
        app(InstitutionResolver::class)->forSchool($this->school)->update(['website_domain' => 'synthetic-prairie.edu']);
        $privateUrl = 'https://prairie-guide.example/apply';
        $page = '<html><head><title>Apply | Synthetic Prairie University</title></head><body><p>Regular Decision applications are due January 15, 2027 for Fall 2027 entry.</p></body></html>';
        Http::fake([
            self::PAGE_URL => Http::response($page, 200, ['Content-Type' => 'text/html']),
            $privateUrl => Http::response($page, 200, ['Content-Type' => 'text/html']),
        ]);
        RequirementExtractorAgent::fake([['requirements' => []], ['requirements' => []]]);

        // Links the policy refuses are reported to the model at once, before anything is fetched...
        $local = $this->proposeListChanges([$this->listChange(['action' => 'add_source', 'url' => 'https://localhost/apply'])]);
        $this->assertSame('not_proposed', $local['status']);
        $this->assertStringContainsString('private or local network', $local['errors'][0]);

        $unverified = $this->proposeListChanges([$this->listChange(['action' => 'add_source', 'url' => $privateUrl])]);
        $this->assertSame('not_proposed', $unverified['status']);
        $this->assertStringContainsString('official: true', $unverified['errors'][0]);

        $proposal = $this->proposal($this->proposeListChanges([
            $this->listChange(['action' => 'add_source', 'url' => self::PAGE_URL]),
            $this->listChange(['action' => 'add_source', 'url' => $privateUrl, 'official' => true]),
        ]));
        $this->assertSame('Add 2 sources for '.self::COLLEGE, $proposal->title);
        Http::assertNothingSent();

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.items.0.after', self::PAGE_URL.' ('.self::COLLEGE."'s verified website)");

        $official = KnowledgeSource::query()->where('url', self::PAGE_URL)->sole();
        $this->assertSame(KnowledgeSource::TYPE_OFFICIAL, $official->source_type);
        $this->assertNull($official->owner_user_id, 'A page on the verified domain is shared knowledge.');
        $this->assertSame($this->student->id, $official->added_by_user_id);

        $own = KnowledgeSource::query()->where('url', $privateUrl)->sole();
        $this->assertSame(KnowledgeSource::TYPE_STUDENT_PROVIDED, $own->source_type);
        $this->assertSame($this->student->id, $own->owner_user_id, 'A page the student vouches for stays private to them.');
        $this->assertTrue($own->claimed_official);
    }

    public function test_a_source_refused_when_approved_is_that_items_outcome_not_an_error(): void
    {
        app(InstitutionResolver::class)->forSchool($this->school)->update(['website_domain' => 'synthetic-prairie.edu']);
        Http::fake();

        $proposal = $this->proposal($this->proposeListChanges([
            $this->listChange(['action' => 'add_source', 'url' => self::PAGE_URL]),
            $this->listChange(['field' => 'admissions_cycle_label', 'value' => 'Fall 2027 entry']),
        ]));

        // By the time the student approves, the host points at a private network address...
        $this->resolveHostsTo('10.0.0.5');

        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.decision.receipt.synthetic-prairie-university:admissions_cycle_label', 'applied')
            ->assertJsonPath('proposal.decision.receipt.'.$proposal->items[0]['key'], 'refused');

        Http::assertNothingSent();
        $this->assertSame(0, KnowledgeSource::query()->count());
        $this->assertSame('Fall 2027 entry', $this->school->fresh()->admissions_cycle_label);
    }

    public function test_the_page_is_read_after_the_decision_and_an_unreadable_one_is_kept_as_a_failed_source(): void
    {
        app(InstitutionResolver::class)->forSchool($this->school)->update(['website_domain' => 'synthetic-prairie.edu']);
        Http::fake([self::PAGE_URL => Http::response('Not found', 404)]);
        RequirementExtractorAgent::fake()->preventStrayPrompts();

        $proposal = $this->proposal($this->proposeListChanges([$this->listChange(['action' => 'add_source', 'url' => self::PAGE_URL])]));

        // Approving queues the read (a slow site must not hold the decision's locks); the item says so.
        $this->decide($proposal)
            ->assertOk()
            ->assertJsonPath('proposal.status', 'applied')
            ->assertJsonPath('proposal.decision.receipt.'.$proposal->items[0]['key'], 'queued');

        // The queued read ran (sync queue in tests) and kept the unreadable page as a failed source with its reason.
        $failed = KnowledgeSource::query()->where('url', self::PAGE_URL)->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertNotEmpty($failed->failure_reason);
        $this->assertSame(0, KnowledgeSource::query()->where('status', 'active')->count());
        RequirementExtractorAgent::assertNeverPrompted();
    }

    public function test_approving_a_source_queues_the_read_after_the_decision_is_saved(): void
    {
        app(InstitutionResolver::class)->forSchool($this->school)->update(['website_domain' => 'synthetic-prairie.edu']);
        Queue::fake();

        $proposal = $this->proposal($this->proposeListChanges([$this->listChange(['action' => 'add_source', 'url' => self::PAGE_URL])]));

        $this->decide($proposal)->assertOk()->assertJsonPath('proposal.decision.receipt.'.$proposal->items[0]['key'], 'queued');

        Queue::assertPushed(IngestApprovedSourceJob::class, fn (IngestApprovedSourceJob $job) => $job->url === self::PAGE_URL
            && $job->studentId === $this->student->id
            && $job->collegeListSchoolId === (string) $this->school->id);
        $this->assertSame(0, KnowledgeSource::query()->count(), 'Nothing is fetched inside the decision.');
    }

    public function test_the_mentor_proposes_list_changes_from_the_chat_instead_of_writing(): void
    {
        AdmitMeGeneralAgent::fake([
            new ToolCall('call_1', 'ProposeCollegeListChangesTool', ['changes' => [
                $this->listChange(['field' => 'rd_deadline', 'value' => '2027-01-15']),
            ]]),
            'I proposed the Regular Decision deadline — approve it and your deadline tracker will show it.',
        ]);

        $this->actingAs($this->student)
            ->postJson(route('dashboard.chat'), ['message' => 'Update my deadlines on the deadline tracker', 'mode' => 'agent'])
            ->streamedContent();

        $proposal = Proposal::query()->where('user_id', $this->student->id)->sole();
        $this->assertSame(Proposal::TYPE_COLLEGE_LIST_UPDATE, $proposal->type);
        $this->assertSame(ProposalStatus::Pending, $proposal->status);
        $this->assertNull($this->school->fresh()->rd_deadline);
    }
}
