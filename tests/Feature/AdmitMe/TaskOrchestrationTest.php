<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\CollegeResearchAgent;
use App\Ai\Context\ChatMode;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Tools\PlanTasksTool;
use App\Ai\Tools\ReadTasksTool;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\StudentProfileService;
use App\Domain\Tasks\BudgetExhaustedException;
use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\TaskContext;
use App\Domain\Tasks\TaskEvents;
use App\Domain\Tasks\TaskStatus;
use App\Jobs\RunAgentTaskJob;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\CollegeListSchool;
use App\Models\Institution;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\User;
use App\Support\MentorChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Tests\Support\KnowledgeFixtures;
use Tests\TestCase;

/**
 * Phase 7 gate: "research affordable options and prepare a shortlist" becomes visible tasks that produce a
 * sourced proposal, wait for review and apply only what is approved; refreshing never loses or repeats work;
 * cancelling, blocking, retries and recovery behave as stated; Ask mode never stops a run.
 */
class TaskOrchestrationTest extends TestCase
{
    use KnowledgeFixtures;
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create([
            'intended_majors_json' => ['Economics'],
            'target_countries_json' => ['United States'],
            'location_country' => 'Nigeria',
            'financial_aid_need_percent' => 90,
        ]);
        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => 25000, 'budget_currency' => 'USD']);

        // Sourced costs for two of the colleges research will suggest: one affordable, one needing aid, one over budget.
        foreach ([['Synthetic Pine University', 20000, false], ['Synthetic Oak College', 80000, true], ['Synthetic Elm Institute', 90000, false]] as [$name, $cost, $aid]) {
            $institution = Institution::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'website_domain' => str($name)->slug()->toString().'.edu']);
            [, $version] = $this->sourceFor($institution, 'https://'.str($name)->slug().'.edu/costs');
            $this->requirementFrom($version, ['requirement_type' => 'cost', 'label' => 'Total cost of attendance', 'amount' => $cost, 'currency' => 'USD', 'quote' => "Total cost of attendance USD {$cost}"]);

            if ($aid) {
                $this->requirementFrom($version, ['requirement_type' => 'aid', 'label' => 'Need-based aid', 'value_text' => 'Need-based aid is available to international students', 'quote' => 'Need-based aid is available']);
            }
        }
    }

    private function fakeResearch(): void
    {
        CollegeResearchAgent::fake([['candidates' => array_map(fn (array $c) => [
            'school_name' => $c[0], 'tier' => $c[1], 'fit_score' => 80, 'why' => "Strong economics at {$c[0]}.", 'notable_programs' => ['Economics'],
            'country' => 'United States', 'location_state' => 'NY', 'aid_estimate_band' => 'strong', 'median_gpa' => null,
        ], [['Synthetic Pine University', 'match'], ['Synthetic Oak College', 'reach'], ['Synthetic Elm Institute', 'safety']])]]);
    }

    private function startPlaybook(array $extra = []): AgentTask
    {
        $response = $this->actingAs($this->student)->postJson(route('agent-tasks.playbooks.start', 'affordable-shortlist'), $extra)->assertCreated();

        return AgentTask::query()->findOrFail($response->json('goal.id'));
    }

    private function step(AgentTask $goal, string $key): AgentTask
    {
        return $goal->steps()->where('step_key', $key)->sole();
    }

    public function test_the_plan_runs_to_one_sourced_proposal_and_applies_only_what_is_approved(): void
    {
        $this->fakeResearch();

        $goal = $this->startPlaybook();

        $this->assertSame(TaskStatus::AwaitingApproval, $goal->refresh()->status);
        $this->assertSame(TaskStatus::Completed, $this->step($goal, 'research')->status);
        $this->assertSame(['Synthetic Pine University', 'Synthetic Oak College', 'Synthetic Elm Institute'], $this->step($goal, 'research')->result['suggested']);
        $this->assertSame(3, CollegeListSchool::query()->where('user_id', $this->student->id)->where('choice_status', CollegeChoiceStatus::Suggested)->where('agent_task_id', $this->step($goal, 'research')->id)->count(), 'Research results are suggestions — the task\'s artifacts.');
        $this->assertSame(TaskStatus::Completed, $this->step($goal, 'affordability')->status);
        $this->assertSame(TaskStatus::Completed, $this->step($goal, 'refresh')->status, 'Independent work finished while the proposal waits.');

        $proposalStep = $this->step($goal, 'proposal');
        $this->assertSame(TaskStatus::AwaitingApproval, $proposalStep->status);
        $proposal = Proposal::query()->findOrFail($proposalStep->proposal_id);
        $this->assertSame((string) $proposalStep->id, $proposal->agent_task_id);
        $this->assertSame(['Synthetic Pine University', 'Synthetic Oak College'], array_column($proposal->items, 'label'), 'Over-budget Elm is left out; affordable first.');
        $this->assertSame(['Synthetic Elm Institute'], $proposalStep->result['excluded_over_budget']);
        $costEvidence = collect($proposal->items[0]['evidence'])->first(fn ($e) => str_contains($e['text'], 'Cost of attendance'));
        $this->assertSame('https://synthetic-pine-university.edu/costs', $costEvidence['url'], 'Each item cites its sources.');
        $this->assertSame(0, CollegeListSchool::query()->where('user_id', $this->student->id)->where('choice_status', CollegeChoiceStatus::Shortlisted)->count(), 'Nothing is saved before approval.');
        $this->assertSame(1, AgentRun::query()->where('task_id', $this->step($goal, 'research')->id)->where('status', 'completed')->count());

        $this->actingAs($this->student)->postJson(route('approvals.decide', $proposal), [
            'decision' => 'approve',
            'selected_keys' => ['synthetic-pine-university'],
            'proposal_revision' => $proposal->revision,
            'payload_hash' => $proposal->payload_hash,
            'idempotency_key' => 'approve-pine',
        ])->assertOk();

        $this->assertSame(TaskStatus::Completed, $proposalStep->refresh()->status);
        $this->assertSame(['Synthetic Pine University'], $proposalStep->result['applied']);
        $this->assertSame('You approved 1 of 2: Synthetic Pine University.', $proposalStep->result['summary'], 'The step reports the decision, not the old waiting message.');
        $this->assertSame(TaskStatus::Completed, $goal->refresh()->status);
        $this->assertSame(CollegeChoiceStatus::Shortlisted, CollegeListSchool::query()->where('school_name', 'Synthetic Pine University')->sole()->choice_status);
        $this->assertSame(CollegeChoiceStatus::Suggested, CollegeListSchool::query()->where('school_name', 'Synthetic Oak College')->sole()->choice_status);
        $this->assertTrue($proposalStep->events()->where('type', 'approval_decided')->exists());
    }

    public function test_repeating_the_request_or_reopening_the_panel_never_duplicates_work(): void
    {
        $this->fakeResearch();

        $first = $this->startPlaybook(['idempotency_key' => 'double-click']);
        $second = $this->startPlaybook(['idempotency_key' => 'double-click']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5, AgentTask::query()->count());

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->student)->getJson(route('agent-tasks.show', $first))->assertOk();
            app(Orchestrator::class)->dispatchReady($this->student);
            app(Orchestrator::class)->recover();
        }

        $this->assertSame(1, AgentRun::query()->count(), 'Research ran exactly once.');
        $this->assertSame(1, Proposal::query()->count());
        $this->assertSame(1, $this->step($first, 'research')->attempts);
    }

    public function test_a_stopped_worker_is_recovered_once_and_repeated_stops_fail_the_step(): void
    {
        Queue::fake();
        $goal = $this->startPlaybook();
        $research = $this->step($goal, 'research');

        $this->assertSame(TaskStatus::Running, $research->status, 'Claimed for a worker…');
        Queue::assertPushed(RunAgentTaskJob::class, 2); // research and the independent refresh

        // The worker died without finishing: the lease runs out…
        $this->travel(16)->minutes();
        app(Orchestrator::class)->recover();
        $this->assertSame(TaskStatus::Running, $research->refresh()->status, '…and the step is queued and claimed again.');
        $this->assertTrue($research->events()->where('type', 'recovered')->exists());
        Queue::assertPushed(RunAgentTaskJob::class, 4);

        app(Orchestrator::class)->recover();
        Queue::assertPushed(RunAgentTaskJob::class, 4); // a live lease is left alone

        $research->forceFill(['attempts' => $research->max_attempts])->save();
        $this->travel(16)->minutes();
        app(Orchestrator::class)->recover();

        $this->assertSame(TaskStatus::Failed, $research->refresh()->status);
        $this->assertSame(TaskStatus::Blocked, $this->step($goal, 'affordability')->refresh()->status);
        $this->assertStringContainsString('which failed', $this->step($goal, 'affordability')->status_reason);
    }

    public function test_cancelling_stops_future_work_without_undoing_anything(): void
    {
        Queue::fake();
        $goal = $this->startPlaybook();
        $research = $this->step($goal, 'research');

        $this->actingAs($this->student)->postJson(route('agent-tasks.cancel', $goal))->assertOk()->assertJsonPath('goal.status', 'cancelled');

        $this->assertNotNull($research->refresh()->cancel_requested_at, 'A claimed step is asked to stop…');
        $this->assertSame(TaskStatus::Cancelled, $this->step($goal, 'affordability')->status, '…and queued steps never start.');

        app(Orchestrator::class)->run($research->id);
        $this->assertSame(TaskStatus::Cancelled, $research->refresh()->status);
        $this->assertSame(0, AgentRun::query()->count(), 'No model call after cancelling.');

        $this->actingAs($this->student)->postJson(route('agent-tasks.retry', $goal))->assertUnprocessable();
    }

    public function test_a_pending_proposal_survives_cancelling_and_the_students_decision_still_counts(): void
    {
        $this->fakeResearch();
        $goal = $this->startPlaybook();
        $proposal = Proposal::query()->sole();

        app(Orchestrator::class)->cancel($goal);

        $this->assertSame(ProposalStatus::Pending, $proposal->refresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $this->step($goal, 'proposal')->status);

        $this->actingAs($this->student)->postJson(route('approvals.decide', $proposal), [
            'decision' => 'approve', 'selected_keys' => ['synthetic-oak-college'], 'proposal_revision' => $proposal->revision, 'payload_hash' => $proposal->payload_hash,
        ])->assertOk();

        $this->assertSame(CollegeChoiceStatus::Shortlisted, CollegeListSchool::query()->where('school_name', 'Synthetic Oak College')->sole()->choice_status);
        $this->assertSame(TaskStatus::Cancelled, $this->step($goal, 'proposal')->status);
        $this->assertTrue($this->step($goal, 'proposal')->events()->where('type', 'approval_decided')->exists());
    }

    public function test_a_step_blocks_instead_of_guessing_and_continues_after_retry(): void
    {
        $this->fakeResearch();
        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => null, 'budget_currency' => null]);

        $goal = $this->startPlaybook();

        $this->assertSame(TaskStatus::Blocked, $this->step($goal, 'affordability')->status);
        $this->assertStringContainsString('No yearly budget on file', $this->step($goal, 'affordability')->status_reason);
        $this->assertSame(TaskStatus::Queued, $this->step($goal, 'proposal')->status, 'Its dependent waits.');
        $this->assertSame(TaskStatus::Blocked, $goal->refresh()->status);

        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => 25000, 'budget_currency' => 'USD']);
        $this->actingAs($this->student)->postJson(route('agent-tasks.retry', $this->step($goal, 'affordability')))->assertOk();

        $this->assertSame(TaskStatus::Completed, $this->step($goal, 'affordability')->status);
        $this->assertSame(TaskStatus::AwaitingApproval, $this->step($goal, 'proposal')->status);
        $this->assertSame(TaskStatus::AwaitingApproval, $goal->refresh()->status);
    }

    public function test_provider_failures_retry_with_backoff_then_fail_and_budgets_are_enforced(): void
    {
        CollegeResearchAgent::fake(fn () => throw new RuntimeException('provider unavailable'));

        $goal = $this->startPlaybook();
        $research = $this->step($goal, 'research');

        $this->assertSame(TaskStatus::Queued, $research->status);
        $this->assertSame(1, $research->attempts);
        $this->assertTrue($research->available_at->isFuture(), 'Backs off before retrying.');
        $this->assertSame('failed', AgentRun::query()->sole()->status);

        $this->travel(1)->minutes();
        app(Orchestrator::class)->recover();

        $this->assertSame(TaskStatus::Failed, $research->refresh()->status, 'Two attempts are its limit.');
        $this->assertSame(TaskStatus::Blocked, $this->step($goal, 'affordability')->status);

        // A step can never make more model calls than its budget...
        $task = AgentTask::query()->create(['user_id' => $this->student->id, 'type' => 'college_research', 'title' => 'Budgeted', 'status' => 'running', 'created_by' => 'student', 'budget' => ['max_model_calls' => 0], 'usage' => []]);
        $this->expectException(BudgetExhaustedException::class);
        (new TaskContext($task, $this->student, app(TaskEvents::class)))->modelCall(CollegeResearchAgent::class, fn () => null);
    }

    public function test_a_budget_changed_mid_plan_is_used_before_proposing(): void
    {
        Queue::fake();
        $this->fakeResearch();
        $orchestrator = app(Orchestrator::class);
        $goal = $this->startPlaybook();

        $orchestrator->run($this->step($goal, 'research')->id);
        $orchestrator->run($this->step($goal, 'affordability')->id);

        // The student lowers their budget before the proposal step runs: Pine (USD 20,000) no longer fits.
        app(StudentProfileService::class)->update($this->student, ['budget_amount' => 15000], null, ChangeSource::studentManual($this->student, 'test'));

        $orchestrator->run($this->step($goal, 'proposal')->id);
        $proposalStep = $this->step($goal, 'proposal');

        $this->assertTrue($proposalStep->events()->where('type', 'revision_changed')->exists());
        $this->assertSame(['Synthetic Oak College'], $proposalStep->result['proposed']);
        $this->assertEqualsCanonicalizing(['Synthetic Pine University', 'Synthetic Elm Institute'], $proposalStep->result['excluded_over_budget']);
    }

    public function test_plans_are_validated_before_anything_is_created(): void
    {
        $orchestrator = app(Orchestrator::class);

        foreach ([
            [['key' => 'a', 'type' => 'delete_everything']],
            [['key' => 'a', 'type' => 'college_research', 'input' => ['count' => 50]]],
            [['key' => 'b', 'type' => 'affordability_check', 'depends_on' => ['a']], ['key' => 'a', 'type' => 'college_research']],
            [['key' => 'a', 'type' => 'college_research'], ['key' => 'a', 'type' => 'affordability_check']],
            array_fill(0, 7, ['key' => 'x', 'type' => 'refresh_sources']),
        ] as $steps) {
            try {
                $orchestrator->plan($this->student, 'Do something useful', $steps, 'mentor');
                $this->fail('Invalid plan accepted: '.json_encode($steps));
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame(0, AgentTask::query()->count());
    }

    public function test_the_mentor_plans_in_agent_mode_only_and_ask_mode_reads_without_stopping_anything(): void
    {
        $this->fakeResearch();
        $policy = app(MentorToolPolicy::class);
        $names = fn (ChatMode $mode) => array_map(fn ($tool) => class_basename($tool), $policy->toolsFor($this->student, $mode, 'conv-1'));

        $this->assertContains('PlanTasksTool', $names(ChatMode::Agent));
        $this->assertNotContains('PlanTasksTool', $names(ChatMode::Ask));
        $this->assertContains('ReadTasksTool', $names(ChatMode::Ask));

        $invalid = json_decode((string) (new PlanTasksTool($this->student, 'conv-1'))->handle(new Request(['goal' => 'Shortlist', 'steps' => [['key' => 'a', 'type' => 'hack', 'depends_on' => []]]])), true);
        $this->assertSame('invalid_plan', $invalid['status']);
        $this->assertSame(0, AgentTask::query()->count());

        $started = json_decode((string) (new PlanTasksTool($this->student, 'conv-1'))->handle(new Request([
            'goal' => 'Research affordable options and prepare a shortlist',
            'steps' => [
                ['key' => 'research', 'type' => 'college_research', 'title' => null, 'depends_on' => [], 'criteria' => 'economics with aid', 'count' => 3, 'max_items' => null, 'max_sources' => null],
                ['key' => 'afford', 'type' => 'affordability_check', 'title' => null, 'depends_on' => ['research'], 'criteria' => null, 'count' => null, 'max_items' => null, 'max_sources' => null],
                ['key' => 'propose', 'type' => 'propose_shortlist', 'title' => null, 'depends_on' => ['afford'], 'criteria' => null, 'count' => null, 'max_items' => 2, 'max_sources' => null],
            ],
        ])), true);

        $this->assertSame('tasks_started', $started['status']);
        $goal = AgentTask::query()->findOrFail($started['goal_task_id']);
        $this->assertSame(['mentor', 'conv-1'], [$goal->created_by, $goal->conversation_id]);
        $this->assertSame([$goal->id], MentorChatConversation::taskGoalIdsFromToolResults([['name' => 'PlanTasksTool', 'result' => json_encode($started)]]));

        // Reading in Ask mode reports the same state and changes nothing...
        $read = json_decode((string) (new ReadTasksTool($this->student))->handle(new Request([])), true);
        $this->assertSame('Waiting for your approval — nothing changes until you approve the proposal.', $read['tasks'][0]['status']);
        $this->assertSame(TaskStatus::AwaitingApproval, $goal->refresh()->status);
    }

    public function test_task_endpoints_belong_to_their_student(): void
    {
        Queue::fake();
        $goal = $this->startPlaybook();
        $other = User::factory()->create();

        $this->actingAs($other)->getJson(route('agent-tasks.show', $goal))->assertNotFound();

        foreach (['cancel', 'pause', 'resume', 'retry'] as $action) {
            $this->actingAs($other)->postJson(route("agent-tasks.{$action}", $goal))->assertNotFound();
        }

        $this->actingAs($this->student)->postJson(route('agent-tasks.playbooks.start', 'not-a-playbook'))->assertNotFound();
    }
}
