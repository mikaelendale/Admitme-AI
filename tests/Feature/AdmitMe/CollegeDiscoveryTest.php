<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Tools\GetStudentCollegeList;
use App\Ai\Tools\ReadCollegeListTool;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Approvals\ProposalService;
use App\Domain\Discovery\CollegeAssessor;
use App\Models\AuditEvent;
use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use App\Models\JobTracking;
use App\Models\KnowledgeSource;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request;
use Tests\Support\KnowledgeFixtures;
use Tests\TestCase;

/**
 * Phase 6: recommendations explain themselves, affordability is never guessed, and students approve the
 * suggestions they want (N of M) or compare their saved colleges — the same state in chat and on screen.
 */
class CollegeDiscoveryTest extends TestCase
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
            'target_countries_json' => ['USA'],
            'gpa_value' => '3.60',
            'gpa_scale' => '4.00',
            'sat_score' => null,
            'act_score' => null,
            'budget_amount' => null,
            'budget_currency' => null,
            'location_country' => 'Nigeria',
            'financial_aid_need_percent' => 80,
            'target_intake' => 'Fall 2027 entry',
        ]);
    }

    private function school(array $attributes = []): CollegeListSchool
    {
        return CollegeListSchool::factory()->for($this->student)->create([
            'school_name' => 'Synthetic Lakeside University',
            'school_slug' => 'synthetic-lakeside-university',
            'notable_programs' => ['Economics', 'Data Science'],
            'median_gpa' => '3.80',
            'location_state' => 'MA',
            'aid_estimate_band' => 'moderate',
            ...$attributes,
        ]);
    }

    public function test_reasons_show_their_basis_and_affordability_without_data_is_incomplete(): void
    {
        $assessment = app(CollegeAssessor::class)->assess($this->student, $this->school());
        $reasons = collect($assessment['reasons'])->keyBy('kind');

        $this->assertSame(['good', 'estimate'], [$reasons['destination']['status'], $reasons['destination']['basis']], 'A US state code means the US; USA matches "United States".');
        $this->assertSame(['good', 'estimate'], [$reasons['interests']['status'], $reasons['interests']['basis']]);
        $this->assertSame('concern', $reasons['grades']['status']);
        $this->assertStringContainsString('3.6 is below the reported median 3.8', $reasons['grades']['text']);
        $this->assertSame('AI research estimate — not verified', $reasons['grades']['basisLabel']);
        $this->assertSame(['unknown', 'missing'], [$reasons['testing']['status'], $reasons['testing']['basis']]);

        $this->assertSame('incomplete', $assessment['affordability']['status']);
        $this->assertFalse($assessment['affordability']['complete']);
        $this->assertCount(2, $assessment['affordability']['missing'], 'Both the budget and a sourced cost are missing.');
        $this->assertStringContainsString('Aid outlook: moderate', collect($assessment['reasons'])->firstWhere('kind', 'aid')['text']);
        $this->assertSame(['deadline', 'fee', 'test_policy'], $assessment['requirements']['missingKeyTypes']);
    }

    public function test_sourced_cost_and_aid_drive_affordability_and_currencies_are_never_converted(): void
    {
        $school = $this->school();
        [, $version] = $this->sourceFor($this->institutionFor($school), 'https://synthetic-lakeside.edu/costs');
        $this->requirementFrom($version, ['requirement_type' => 'cost', 'label' => 'Estimated cost of attendance', 'value_text' => 'Total cost of attendance, 2026–27', 'amount' => 82500, 'currency' => 'USD', 'applicant_scope' => 'international', 'quote' => 'Total cost of attendance for international students: USD 82,500']);
        $this->requirementFrom($version, ['requirement_type' => 'aid', 'label' => 'Financial aid', 'value_text' => 'Need-based aid is available to international students', 'quote' => 'Need-based aid is available to international students']);
        $this->requirementFrom($version, ['requirement_type' => 'test_policy', 'label' => 'Testing policy', 'value_text' => 'Test-optional', 'cycle_label' => 'Fall 2027 entry', 'quote' => 'We are test-optional']);

        $profile = OnboardingProfile::query()->where('user_id', $this->student->id)->sole();
        $assess = fn () => app(CollegeAssessor::class)->assess($this->student, $school);

        $profile->forceFill(['budget_amount' => 25000, 'budget_currency' => 'USD'])->save();
        $needsAid = $assess();
        $this->assertSame('needs_aid', $needsAid['affordability']['status']);
        $this->assertFalse($needsAid['affordability']['complete'], 'Needing aid is not a conclusion until there is an aid offer.');
        $this->assertStringContainsString('USD 57,500', $needsAid['affordability']['summary']);
        $testing = collect($needsAid['reasons'])->firstWhere('kind', 'testing');
        $this->assertSame(['good', 'source'], [$testing['status'], $testing['basis']]);
        $this->assertSame('https://synthetic-lakeside.edu/costs', collect($needsAid['reasons'])->firstWhere('kind', 'cost')['source']['url']);

        $profile->forceFill(['budget_currency' => 'GBP'])->save();
        $mismatch = $assess();
        $this->assertSame('incomplete', $mismatch['affordability']['status']);
        $this->assertStringContainsString("doesn't convert currencies", implode(' ', $mismatch['affordability']['missing']));

        $profile->forceFill(['budget_amount' => 90000, 'budget_currency' => 'USD'])->save();
        $within = $assess();
        $this->assertSame('within_budget', $within['affordability']['status']);
        $this->assertTrue($within['affordability']['complete']);
    }

    public function test_component_costs_are_not_mistaken_for_the_total_even_when_the_colleges_country_is_unknown(): void
    {
        // Found end-to-end: no country on file and only international-scoped figures, one of them the total.
        $school = $this->school(['location_state' => null]);
        [, $version] = $this->sourceFor($this->institutionFor($school), 'https://synthetic-lakeside.edu/costs');
        foreach ([['Cost of attendance: tuition', 61000], ['Housing and meals', 17500], ['Books and other costs', 4000], ['Total estimated cost of attendance', 82500]] as [$label, $amount]) {
            $this->requirementFrom($version, ['requirement_type' => 'cost', 'label' => $label, 'amount' => $amount, 'currency' => 'USD', 'applicant_scope' => 'international', 'quote' => $label]);
        }
        $this->requirementFrom($version, ['requirement_type' => 'aid', 'label' => 'Need-based aid', 'value_text' => 'Need-based aid is available to international students', 'applicant_scope' => 'international', 'quote' => 'Need-based aid is available']);
        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => 25000, 'budget_currency' => 'USD']);

        $assessment = app(CollegeAssessor::class)->assess($this->student, $school);

        $this->assertNull($assessment['country']);
        $this->assertSame('needs_aid', $assessment['affordability']['status']);
        $this->assertSame(82500.0, $assessment['affordability']['cost']['amount']);
        $this->assertStringContainsString('for international students', collect($assessment['reasons'])->firstWhere('kind', 'cost')['text']);
    }

    public function test_a_tuition_only_figure_never_completes_affordability(): void
    {
        $school = $this->school();
        [, $version] = $this->sourceFor($this->institutionFor($school), 'https://synthetic-lakeside.edu/tuition');
        $this->requirementFrom($version, ['requirement_type' => 'cost', 'label' => 'Tuition', 'value_text' => 'Tuition only', 'amount' => 20000, 'currency' => 'USD', 'quote' => 'Tuition is USD 20,000']);
        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => 30000, 'budget_currency' => 'USD']);

        $affordability = app(CollegeAssessor::class)->assess($this->student, $school)['affordability'];

        $this->assertSame('within_budget', $affordability['status']);
        $this->assertFalse($affordability['complete']);
        $this->assertStringContainsString('tuition only', $affordability['summary']);
    }

    public function test_students_approve_some_suggestions_and_dismiss_others(): void
    {
        [$a, $b, $c, $d] = CollegeListSchool::factory()->for($this->student)->suggested()->count(4)->create()->all();
        $saved = CollegeListSchool::factory()->for($this->student)->create();
        $foreign = CollegeListSchool::factory()->suggested()->create();

        $receipt = $this->actingAs($this->student)
            ->postJson(route('college-lists.suggestions.review'), ['add' => [$a->id, $b->id, $saved->id], 'dismiss' => [$c->id, $foreign->id]])
            ->assertOk()
            ->json();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $receipt['added']);
        $this->assertSame([$c->id], $receipt['dismissed']);
        $this->assertSame('already_shortlisted', $receipt['skipped'][$saved->id]);
        $this->assertSame('not_found', $receipt['skipped'][$foreign->id], "Another student's suggestion is invisible.");

        $this->assertSame(CollegeChoiceStatus::Shortlisted, $a->fresh()->choice_status);
        $this->assertSame(CollegeChoiceStatus::Archived, $c->fresh()->choice_status);
        $this->assertSame(CollegeChoiceStatus::Suggested, $d->fresh()->choice_status, 'Undecided suggestions stay suggestions.');
        $this->assertSame(CollegeChoiceStatus::Suggested, $foreign->fresh()->choice_status);
        $this->assertSame(3, AuditEvent::query()->where('user_id', $this->student->id)->where('action', 'college_choice.updated')->count());

        // Repeating the decision changes nothing...
        $again = $this->actingAs($this->student)->postJson(route('college-lists.suggestions.review'), ['add' => [$a->id], 'dismiss' => []])->assertOk()->json();
        $this->assertSame([], $again['added']);
        $this->assertSame('already_shortlisted', $again['skipped'][$a->id]);

        $this->actingAs($this->student)->postJson(route('college-lists.suggestions.review'), ['add' => [$d->id], 'dismiss' => [$d->id]])->assertUnprocessable();
        $this->actingAs($this->student)->postJson(route('college-lists.suggestions.review'), ['add' => [], 'dismiss' => []])->assertUnprocessable();
    }

    public function test_a_finished_research_task_shows_its_suggestions_awaiting_review(): void
    {
        $run = CollegeListRun::query()->create(['user_id' => $this->student->id, 'status' => CollegeListRun::STATUS_COMPLETED, 'intent' => 'generate', 'request_message' => 'Economics in the US', 'completed_at' => now()]);
        CollegeListSchool::factory()->for($this->student)->suggested()->count(2)->create(['college_list_run_id' => $run->id]);
        CollegeListSchool::factory()->for($this->student)->create(['college_list_run_id' => $run->id]);
        JobTracking::query()->create(['user_id' => $this->student->id, 'job_type' => JobTracking::TYPE_COLLEGE_LIST_COUNCIL, 'status' => JobTracking::STATUS_COMPLETED, 'payload' => ['run_id' => (string) $run->id], 'completed_at' => now()]);

        $this->actingAs($this->student)->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.note', '2 suggestions awaiting your review')
                ->where('tasks.0.href', '/college-lists'));
    }

    public function test_the_legacy_list_tool_never_presents_a_suggestion_as_saved(): void
    {
        // Found end-to-end: the mentor told a student a research suggestion was "already on your saved list".
        $this->school(['choice_status' => CollegeChoiceStatus::Suggested]);
        CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Northfield College']);

        $rows = collect(json_decode((string) (new GetStudentCollegeList($this->student))->handle(new Request([])), true))->keyBy('school_name');

        $this->assertSame(['suggested', false], [$rows['Synthetic Lakeside University']['list_status'], $rows['Synthetic Lakeside University']['on_saved_list']]);
        $this->assertSame(['shortlisted', true], [$rows['Synthetic Northfield College']['list_status'], $rows['Synthetic Northfield College']['on_saved_list']]);
    }

    public function test_a_mentor_proposal_can_promote_a_research_suggestion(): void
    {
        $suggestion = $this->school(['choice_status' => CollegeChoiceStatus::Suggested]);

        $proposal = app(ProposalService::class)->proposeCollegeAdditions($this->student, [
            ['school_name' => 'Synthetic Lakeside University', 'tier' => 'reach', 'reason' => 'Strong economics.'],
        ], 'mentor');

        $this->actingAs($this->student)->postJson(route('approvals.decide', $proposal), [
            'decision' => 'approve',
            'selected_keys' => [$proposal->items[0]['key']],
            'proposal_revision' => $proposal->revision,
            'payload_hash' => $proposal->payload_hash,
            'idempotency_key' => 'promote-1',
        ])->assertOk();

        $this->assertSame(CollegeChoiceStatus::Shortlisted, $suggestion->fresh()->choice_status);
        $this->assertSame(1, CollegeListSchool::query()->where('user_id', $this->student->id)->count(), 'Promoted, not duplicated.');
    }

    public function test_list_page_chat_tool_and_compare_view_share_the_same_assessment(): void
    {
        $school = $this->school();
        $other = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Northfield College', 'school_slug' => 'synthetic-northfield-college']);
        $foreign = CollegeListSchool::factory()->create();

        $listed = null;
        $this->actingAs($this->student)->get(route('college-lists'))->assertOk()
            ->assertInertia(function (Assert $page) use (&$listed) {
                $page->component('college-lists')->has('colleges', 2);
                $listed = collect($page->toArray()['props']['colleges'])->keyBy('name');
            });

        $tool = collect(json_decode((string) (new ReadCollegeListTool($this->student))->handle(new Request(['status' => 'active'])), true)['colleges'])->keyBy('name');
        $this->assertSame(
            $listed['Synthetic Lakeside University']['assessment']['affordability']['summary'],
            $tool['Synthetic Lakeside University']['fit_check']['affordability']['summary'],
        );
        $this->assertSame(
            array_column($listed['Synthetic Lakeside University']['assessment']['reasons'], 'text'),
            array_column($tool['Synthetic Lakeside University']['fit_check']['reasons'], 'text'),
        );

        $this->actingAs($this->student)->get(route('college-lists.compare', ['ids' => implode(',', [$other->id, $school->id, $foreign->id])]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('college-lists/compare')
                ->has('colleges', 2)
                ->where('colleges.0.name', 'Synthetic Northfield College')
                ->where('colleges.1.assessment.affordability.status', 'incomplete'));
    }

    public function test_private_sources_only_shape_their_owners_assessment(): void
    {
        $school = $this->school();
        $institution = $this->institutionFor($school);
        [, $version] = $this->sourceFor($institution, 'https://lakeside-guide.example/costs', KnowledgeSource::TYPE_STUDENT_PROVIDED, $this->student);
        $this->requirementFrom($version, ['requirement_type' => 'cost', 'label' => 'Total cost of attendance', 'amount' => 10000, 'currency' => 'USD', 'quote' => 'Total cost USD 10,000']);
        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => 20000, 'budget_currency' => 'USD']);

        $peer = User::factory()->create();
        OnboardingProfile::factory()->for($peer)->create(['budget_amount' => 20000, 'budget_currency' => 'USD']);
        $peerSchool = CollegeListSchool::factory()->for($peer)->create(['school_name' => 'Synthetic Lakeside University', 'school_slug' => 'synthetic-lakeside-university']);

        $mine = app(CollegeAssessor::class)->assess($this->student, $school);
        $theirs = app(CollegeAssessor::class)->assess($peer, $peerSchool);

        $this->assertSame('within_budget', $mine['affordability']['status']);
        $this->assertStringContainsString('your source, not verified', collect($mine['reasons'])->firstWhere('kind', 'cost')['basisLabel']);
        $this->assertSame('incomplete', $theirs['affordability']['status'], "Another student's private page never counts for someone else.");
    }
}
