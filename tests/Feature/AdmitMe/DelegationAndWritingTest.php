<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Agents\ApplicationPlannerAgent;
use App\Ai\Agents\CollegeResearchAgent;
use App\Ai\Agents\WritingCoachAgent;
use App\Ai\AiModelResolver;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Shared\ChangeSource;
use App\Domain\Shared\StaleRevisionException;
use App\Domain\Students\StudentProfileService;
use App\Domain\Tasks\Delegation\Handoff;
use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Writing\WritingPolicy;
use App\Domain\Writing\WritingService;
use App\Jobs\RunAgentTaskJob;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Application;
use App\Models\ApplicationFormPlan;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\Institution;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\User;
use App\Models\WritingFeedback;
use App\Models\WritingPiece;
use App\Models\WritingVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\KnowledgeFixtures;
use Tests\TestCase;

/**
 * Phase 8 gate: independent research runs within limits and is attributable; a changed budget re-evaluates
 * affected work; writing feedback preserves the student's text and authorship; specialists cannot bypass
 * scope or approval rules.
 */
class DelegationAndWritingTest extends TestCase
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
            'activities_extracted_json' => [['name' => 'Debate club captain'], ['name' => 'Maths olympiad']],
        ]);
        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => 25000, 'budget_currency' => 'USD']);
    }

    private function research(string ...$names): void
    {
        CollegeResearchAgent::fake(array_map(fn (string $name) => ['candidates' => [[
            'school_name' => $name, 'tier' => 'match', 'fit_score' => 70, 'why' => 'Fits.', 'notable_programs' => ['Economics'],
            'country' => 'United States', 'location_state' => 'NY', 'aid_estimate_band' => 'strong', 'median_gpa' => null,
        ]]], $names));
    }

    public function test_independent_research_runs_within_the_student_limit_and_every_result_is_attributable(): void
    {
        Queue::fake();
        config(['admitme.tasks.max_running_per_student' => 3]);
        $this->research('Synthetic Ash College', 'Synthetic Beech College', 'Synthetic Cherry College', 'Synthetic Date College');

        $goal = app(Orchestrator::class)->plan($this->student, 'Research four directions at once', array_map(
            fn (string $key) => ['key' => $key, 'type' => 'college_research', 'input' => ['criteria' => "direction {$key}", 'count' => 1]],
            ['a', 'b', 'c', 'd'],
        ), 'mentor');

        $this->assertSame(3, $goal->steps()->where('status', TaskStatus::Running)->count(), 'At most three run at once…');
        $this->assertSame(1, $goal->steps()->where('status', TaskStatus::Queued)->count());
        Queue::assertPushed(RunAgentTaskJob::class, 3);

        foreach ($goal->steps()->where('status', TaskStatus::Running)->pluck('id') as $id) {
            app(Orchestrator::class)->run($id);
        }

        Queue::assertPushed(RunAgentTaskJob::class, 4); // …and the fourth starts when one finishes.
        app(Orchestrator::class)->run($goal->steps()->where('step_key', 'd')->value('id'));

        $this->assertSame(TaskStatus::Completed, $goal->refresh()->status);

        foreach (AgentRun::query()->get() as $run) {
            $this->assertSame($this->student->id, $run->handoff['student_id']);
            $this->assertSame('college_candidates', $run->handoff['contract']);
            $this->assertContains('P1', $run->handoff['evidence_refs']);
            $this->assertNotNull($run->model);
            $this->assertSame(1, CollegeListSchool::query()->where('agent_task_id', $run->task_id)->count(), 'Each suggestion traces back to the step (and run) that made it.');
        }
    }

    public function test_the_global_limit_and_the_provider_rate_limit_hold_work_back_without_failing_it(): void
    {
        Queue::fake();
        config(['admitme.tasks.max_running_global' => 1, 'admitme.tasks.provider_calls_per_minute' => 1]);
        $other = User::factory()->create();
        OnboardingProfile::factory()->for($other)->create();

        app(Orchestrator::class)->plan($this->student, 'Mine to research', [['key' => 'a', 'type' => 'college_research']], 'mentor');
        $theirs = app(Orchestrator::class)->plan($other, 'Theirs to research', [['key' => 'a', 'type' => 'college_research']], 'mentor');

        $this->assertSame(1, AgentTask::query()->where('status', TaskStatus::Running)->whereNotNull('parent_id')->count());
        $this->assertSame(TaskStatus::Queued, $theirs->steps()->sole()->status);

        // The provider limit is already used up this minute: the step waits instead of failing.
        RateLimiter::hit('ai-provider-calls:'.app(AiModelResolver::class)->providerFor(CollegeResearchAgent::class), 60);
        $mine = AgentTask::query()->where('user_id', $this->student->id)->whereNotNull('parent_id')->sole();
        app(Orchestrator::class)->run($mine->id);

        $mine->refresh();
        $this->assertSame(TaskStatus::Queued, $mine->status);
        $this->assertSame(0, $mine->attempts, 'Waiting for the provider does not use up an attempt.');
        $this->assertTrue($mine->available_at->isFuture());
        $this->assertTrue($mine->events()->where('type', 'deferred')->exists());
        $this->assertSame(0, AgentRun::query()->count());
    }

    public function test_a_changed_budget_reevaluates_a_pending_shortlist_and_replaces_its_proposal(): void
    {
        $this->research('Synthetic Pine University');
        CollegeResearchAgent::fake([['candidates' => [
            ['school_name' => 'Synthetic Pine University', 'tier' => 'match', 'fit_score' => 80, 'why' => 'Fits.', 'notable_programs' => ['Economics'], 'country' => 'United States', 'location_state' => 'NY', 'aid_estimate_band' => null, 'median_gpa' => null],
            ['school_name' => 'Synthetic Oak College', 'tier' => 'match', 'fit_score' => 75, 'why' => 'Fits.', 'notable_programs' => ['Economics'], 'country' => 'United States', 'location_state' => 'NY', 'aid_estimate_band' => null, 'median_gpa' => null],
        ]]]);

        foreach ([['Synthetic Pine University', 20000], ['Synthetic Oak College', 24000]] as [$name, $cost]) {
            $institution = Institution::query()->create(['name' => $name, 'slug' => Str::slug($name), 'website_domain' => Str::slug($name).'.edu']);
            [, $version] = $this->sourceFor($institution, 'https://'.Str::slug($name).'.edu/costs');
            $this->requirementFrom($version, ['requirement_type' => 'cost', 'label' => 'Total cost of attendance', 'amount' => $cost, 'currency' => 'USD', 'quote' => "Total cost USD {$cost}"]);
        }

        $goal = app(Orchestrator::class)->plan($this->student, 'Affordable shortlist', [
            ['key' => 'research', 'type' => 'college_research'],
            ['key' => 'afford', 'type' => 'affordability_check', 'depends_on' => ['research']],
            ['key' => 'propose', 'type' => 'propose_shortlist', 'depends_on' => ['afford']],
        ], 'mentor');

        $step = $goal->steps()->where('step_key', 'propose')->sole();
        $first = Proposal::query()->findOrFail($step->proposal_id);
        $this->assertSame(['Synthetic Pine University', 'Synthetic Oak College'], array_column($first->items, 'label'));

        // The student lowers their budget: Oak (USD 24,000) no longer fits.
        app(StudentProfileService::class)->update($this->student, ['budget_amount' => 22000], null, ChangeSource::studentManual($this->student, 'test'));

        $this->assertSame(ProposalStatus::Stale, $first->refresh()->status, 'The old proposal can no longer be approved.');
        $step->refresh();
        $this->assertSame(TaskStatus::AwaitingApproval, $step->status);
        $second = Proposal::query()->findOrFail($step->proposal_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(['Synthetic Pine University'], array_column($second->items, 'label'));
        $this->assertSame(['Synthetic Oak College'], $step->result['excluded_over_budget']);
        $this->assertTrue($step->events()->where('type', 'reevaluating')->exists());
        $this->assertSame(2, $goal->steps()->where('step_key', 'afford')->sole()->events()->where('type', 'started')->count(), 'Affordability was checked again.');
    }

    public function test_a_planner_specialist_stays_in_its_students_scope_and_its_output_is_labelled(): void
    {
        $school = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Lakeside University', 'school_slug' => 'synthetic-lakeside-university']);
        $application = Application::factory()->for($this->student)->create(['college_list_school_id' => $school->id]);
        ApplicationPlannerAgent::fake([[
            'test_plan' => ['judgment' => 'Apply test-optional; no scores on file.', 'based_on' => ['P1', 'R7', 'made-up']],
            'activity_order' => ['judgment' => 'Lead with debate.', 'based_on' => ['A1']],
            'additional_info' => null,
        ]]);

        $goal = app(Orchestrator::class)->plan($this->student, 'Plan the form', [['key' => 'plan', 'type' => 'application_plan', 'input' => ['application_id' => $application->id]]], 'student');

        $this->assertSame(TaskStatus::Completed, $goal->refresh()->status);
        $entries = collect(ApplicationFormPlan::query()->sole()->entries)->keyBy('field');
        $this->assertSame(['profile', $this->student->name], [$entries['Legal name']['basis'], $entries['Legal name']['value']]);
        $this->assertSame('model_judgment', $entries['Report test scores?']['basis']);
        $this->assertStringContainsString('Based on: P1.', $entries['Report test scores?']['note'], 'Only evidence the planner was given counts as its basis.');
        $this->assertStringNotContainsString('made-up', $entries['Report test scores?']['note']);
        $this->assertSame('validation', $entries['Personal statement (650 words max)']['basis']);
        $this->assertSame(['handoff' => 'application_judgments'], ['handoff' => AgentRun::query()->sole()->handoff['contract']]);

        // Someone else's application id in a plan is refused by the step itself.
        $stranger = Application::factory()->create();
        $refused = app(Orchestrator::class)->plan($this->student, 'Plan theirs', [['key' => 'plan', 'type' => 'application_plan', 'input' => ['application_id' => $stranger->id]]], 'mentor');
        $this->assertSame(TaskStatus::Failed, $refused->steps()->sole()->status);
        $this->assertSame('That application is not one of yours.', $refused->steps()->sole()->status_reason);
        $this->assertSame(1, ApplicationFormPlan::query()->count());

        $handoff = new Handoff(null, $this->student->id, 'X', 'goal', 1, [['ref' => 'P1', 'text' => 'a']], 'c');
        $this->assertSame(['P1'], $handoff->knownRefs(['[P1]', 'P2', 5, null]));
    }

    public function test_versions_are_the_students_own_and_never_change(): void
    {
        $piece = app(WritingService::class)->createPiece($this->student, ['title' => 'Personal statement', 'word_limit' => 650]);
        $writing = app(WritingService::class);

        $v1 = $writing->saveVersion($piece, $this->student, 'I learned to argue in debate club.', 0);
        $this->assertSame([1, 'student', 7], [$v1->number, $v1->author, $v1->word_count]);
        $this->assertSame($v1->id, $writing->saveVersion($piece, $this->student, 'I learned to argue in debate club.', 1)->id, 'Unchanged text is not a new version.');

        $v2 = $writing->saveVersion($piece, $this->student, 'I learned to argue — and to listen — in debate club.', 1);
        $this->assertSame(2, $v2->number);
        $this->assertSame('I learned to argue in debate club.', $v1->fresh()->body);

        $this->expectException(StaleRevisionException::class);
        $writing->saveVersion($piece, $this->student, 'Edited in another tab', 1);
    }

    public function test_versions_cannot_be_edited_in_place(): void
    {
        $piece = app(WritingService::class)->createPiece($this->student, ['title' => 'Essay']);
        $version = app(WritingService::class)->saveVersion($piece, $this->student, 'My own words.', 0);

        $this->expectException(LogicException::class);
        $version->update(['body' => 'Someone else\'s words.']);
    }

    public function test_feedback_quotes_the_students_words_and_respects_the_colleges_policy(): void
    {
        $body = 'Debate taught me to listen. I has learned that winning is not everything. My team went to nationals.';
        $piece = app(WritingService::class)->createPiece($this->student, ['title' => 'Why debate', 'word_limit' => 250]);
        $version = app(WritingService::class)->saveVersion($piece, $this->student, $body, 0);

        WritingCoachAgent::fake([[
            'summary' => 'A clear theme; make the nationals story concrete.',
            'comments' => [
                ['quote' => 'My team went to nationals.', 'comment' => 'What did you do there?', 'kind' => 'specificity', 'severity' => 'high', 'fix' => 'At nationals, I led our final round against the champions.'],
                ['quote' => 'I has learned', 'comment' => 'Grammar.', 'kind' => 'language', 'severity' => 'low', 'fix' => 'I have learned'],
                ['quote' => 'My passion for global affairs', 'comment' => 'Expand.', 'kind' => 'clarity', 'severity' => 'medium', 'fix' => null],
            ],
        ], [
            'summary' => 'Fine.',
            'comments' => [
                ['quote' => 'I has learned', 'comment' => 'Grammar.', 'kind' => 'language', 'severity' => 'low', 'fix' => 'I have learned'],
                ['quote' => 'My team went to nationals.', 'comment' => 'Be specific.', 'kind' => 'language', 'severity' => 'low', 'fix' => 'My whole team, after months of practice, travelled together to the national championships in the capital.'],
            ],
        ]]);

        $this->actingAs($this->student)->post(route('writing.feedback', $piece), ['version_id' => $version->id])->assertSessionHasNoErrors();
        $feedback = WritingFeedback::query()->sole();

        $this->assertCount(2, $feedback->comments);
        $this->assertSame(1, $feedback->dropped_comments, 'A comment quoting words the student never wrote is dropped.');
        $this->assertNull($feedback->comments[0]['fix'], 'Feedback-only: no replacement sentences.');
        $this->assertNull($feedback->comments[1]['fix'], 'Feedback-only: not even small language fixes.');
        $this->assertSame($body, $version->fresh()->body, 'The student\'s text is untouched.');
        $this->assertSame(1, WritingVersion::query()->count(), 'The coach never creates a version.');

        // A college that allows light language edits gets small fixes only.
        $school = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Lenient College', 'school_slug' => 'synthetic-lenient-college']);
        Institution::query()->create(['name' => 'Synthetic Lenient College', 'slug' => 'synthetic-lenient-college', 'writing_policy' => WritingPolicy::LightEditing->value]);
        $lenient = app(WritingService::class)->createPiece($this->student, ['title' => 'Why Lenient'], $school);
        $lenientVersion = app(WritingService::class)->saveVersion($lenient, $this->student, $body, 0);

        $this->actingAs($this->student)->post(route('writing.feedback', $lenient), ['version_id' => $lenientVersion->id])->assertSessionHasNoErrors();
        $comments = WritingFeedback::query()->where('writing_piece_id', $lenient->id)->sole()->comments;
        $this->assertSame('I have learned', $comments[0]['fix']);
        $this->assertNull($comments[1]['fix'], 'A "fix" longer than a few words is a rewrite, so it is dropped.');
    }

    public function test_no_ai_policy_means_the_coach_is_never_called(): void
    {
        WritingCoachAgent::fake()->preventStrayPrompts();
        $school = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Strict College', 'school_slug' => 'synthetic-strict-college']);
        Institution::query()->create(['name' => 'Synthetic Strict College', 'slug' => 'synthetic-strict-college', 'writing_policy' => WritingPolicy::NoAiAssistance->value]);
        $piece = app(WritingService::class)->createPiece($this->student, ['title' => 'Why Strict'], $school);
        $version = app(WritingService::class)->saveVersion($piece, $this->student, 'My own essay.', 0);

        $this->actingAs($this->student)->post(route('writing.feedback', $piece), ['version_id' => $version->id])->assertSessionHasErrors('policy');
        WritingCoachAgent::assertNeverPrompted();

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('writing.show', $piece))->assertNotFound();
        $this->actingAs($other)->post(route('writing.versions.store', $piece), ['body' => 'x', 'based_on' => 1])->assertNotFound();
    }

    public function test_an_essay_step_opens_a_writing_piece_with_its_prompt(): void
    {
        $school = CollegeListSchool::factory()->for($this->student)->create();
        $application = Application::factory()->for($this->student)->create(['college_list_school_id' => $school->id]);
        $item = ApplicationItem::query()->create([
            'application_id' => $application->id, 'user_id' => $this->student->id, 'item_key' => 'req:essay', 'kind' => 'essay', 'title' => 'Write: Why us essay',
            'details' => "Prompt: Why do you want to study here? (250 words)\nCycle: Fall 2027 entry", 'origin' => 'requirement', 'owner_type' => 'student', 'status' => 'todo', 'position' => 1,
        ]);

        $this->actingAs($this->student)->post(route('applications.items.writing', [$application, $item]))->assertRedirect();
        $piece = WritingPiece::query()->sole();
        $this->assertSame(['Why us essay', 'Why do you want to study here? (250 words)', 250, $item->id], [$piece->title, $piece->prompt_text, $piece->word_limit, $piece->application_item_id]);

        $this->actingAs($this->student)->post(route('applications.items.writing', [$application, $item]));
        $this->assertSame(1, WritingPiece::query()->count(), 'Reopening the step reuses its piece.');
    }

    public function test_one_mentor_turn_at_a_time_per_conversation(): void
    {
        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert(['id' => $conversationId, 'participant_type' => $this->student->getMorphClass(), 'participant_id' => $this->student->id, 'title' => 'Mine', 'created_at' => now(), 'updated_at' => now()]);
        AdmitMeGeneralAgent::fake(['should not run while another turn is answering']);

        $inProgress = Cache::lock('mentor-turn:'.$conversationId, 120);
        $this->assertTrue($inProgress->get());

        $this->actingAs($this->student)
            ->postJson(route('dashboard.chat'), ['message' => 'Second message', 'conversation_id' => $conversationId])
            ->assertStatus(409)
            ->assertJsonPath('error', 'turn_in_progress');

        AdmitMeGeneralAgent::assertNeverPrompted();
        $inProgress->release();
    }
}
