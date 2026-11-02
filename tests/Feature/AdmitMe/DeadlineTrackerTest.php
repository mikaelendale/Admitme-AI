<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Tools\ProposeCollegeListChangesTool;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\User;
use App\Support\DashboardInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * The deadline tracker: every college on the list with its Early Action, Early Decision and Regular Decision
 * dates (or rolling admission) and the official deadlines on its application checklist, as a calendar. Every
 * college needs a deadline; the student and the mentor (through approval) can set them.
 */
class DeadlineTrackerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create();
    }

    public function test_the_calendar_has_every_round_and_the_official_checklist_deadlines(): void
    {
        $purdue = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Purdue', 'ea_deadline' => '2026-11-15', 'rd_deadline' => '2027-01-15']);
        $berea = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Berea', 'ed_deadline' => '2026-11-01', 'choice_status' => CollegeChoiceStatus::Applying]);
        $application = Application::factory()->for($this->student)->create(['college_list_school_id' => $berea->id]);
        ApplicationItem::query()->create([
            'application_id' => $application->id, 'user_id' => $this->student->id, 'item_key' => 'req:deadline', 'kind' => 'deadline',
            'title' => 'Application deadline: Jan 5, 2027', 'origin' => 'requirement', 'owner_type' => 'student', 'status' => 'todo', 'position' => 1, 'due_date' => '2027-01-05',
        ]);
        // Suggested or archived colleges are not on the tracker.
        CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Suggested', 'rd_deadline' => '2027-01-01', 'choice_status' => CollegeChoiceStatus::Suggested]);

        $this->actingAs($this->student)->get(route('deadlines.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('deadlines')
                ->where('today', '2026-11-01')
                ->has('colleges', 2)
                ->has('events', 4)
                // Soonest first: Berea's Early Decision today, Purdue's Early Action, the official step, then RD.
                ->where('events.0.kind', 'ed')
                ->where('events.0.college', 'Synthetic Berea')
                ->where('events.1.kind', 'ea')
                ->where('events.2.kind', 'step')
                ->where('events.2.source', 'official')
                ->where('events.2.href', "/applications/{$application->id}")
                ->where('events.3.kind', 'rd')
                ->where('events.3.source', 'list')
                ->where('colleges.0.name', 'Synthetic Berea')
                ->where('colleges.1.ea', '2026-11-15')
                ->where('colleges.1.needsDeadline', false));

        $this->assertNotNull($purdue);
    }

    public function test_every_college_needs_a_deadline_and_rolling_admission_counts_as_one(): void
    {
        CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Without']);
        CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Rolling', 'rolling_admission' => true]);

        $this->actingAs($this->student)->get(route('deadlines.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('colleges.0.name', 'Synthetic Rolling')
                ->where('colleges.0.rolling', true)
                ->where('colleges.0.needsDeadline', false)
                ->where('colleges.1.name', 'Synthetic Without')
                ->where('colleges.1.needsDeadline', true));

        // Home asks for it too.
        $item = collect(DashboardInbox::for($this->student)['items'])->firstWhere('kind', 'deadline_missing');
        $this->assertNotNull($item);
        $this->assertSame('Synthetic Without needs a deadline', $item['title']);
        $this->assertSame('/deadlines', $item['href']);
    }

    public function test_the_student_saves_deadlines_and_rolling_admission(): void
    {
        $school = CollegeListSchool::factory()->for($this->student)->create();

        $this->actingAs($this->student)->patchJson(route('college-lists.school.update', $school), [
            'ea_deadline' => '2026-11-15',
            'rd_deadline' => '2027-01-15',
            'rolling_admission' => false,
            'expected_revision' => $school->revision,
        ])->assertOk();

        $school->refresh();
        $this->assertSame(['2026-11-15', null, '2027-01-15', false], [$school->ea_deadline?->toDateString(), $school->ed_deadline, $school->rd_deadline?->toDateString(), $school->rolling_admission]);

        $this->patchJson(route('college-lists.school.update', $school), ['ea_deadline' => null, 'rd_deadline' => null, 'rolling_admission' => true])->assertOk();
        $this->assertTrue($school->fresh()->rolling_admission);

        $this->patchJson(route('college-lists.school.update', $school), ['rd_deadline' => '15/01/2027'])->assertStatus(422);
    }

    public function test_another_students_college_cannot_be_changed(): void
    {
        $theirs = CollegeListSchool::factory()->for(User::factory())->create();

        $this->actingAs($this->student)->patchJson(route('college-lists.school.update', $theirs), ['rd_deadline' => '2027-01-15'])->assertNotFound();
        $this->assertNull($theirs->fresh()->rd_deadline);
    }

    public function test_the_mentor_can_propose_rolling_admission_for_approval(): void
    {
        $school = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Rolling College']);

        $result = json_decode((string) (new ProposeCollegeListChangesTool($this->student))->handle(new Request(['changes' => [[
            'college' => 'Synthetic Rolling College', 'action' => 'update', 'field' => 'rolling_admission', 'value' => 'true',
            'url' => null, 'official' => null, 'reason' => 'Its admissions page says decisions are made on a rolling basis.', 'evidence' => null,
        ]]])), true);

        $this->assertSame('pending_approval', $result['status'], json_encode($result) ?: '');
        $proposal = Proposal::query()->findOrFail($result['proposal_id']);
        $this->assertFalse($school->fresh()->rolling_admission, 'Nothing changes before approval.');

        $this->actingAs($this->student)->postJson(route('approvals.decide', $proposal), [
            'decision' => 'approve',
            'selected_keys' => array_column($proposal->items, 'key'),
            'proposal_revision' => $proposal->revision,
            'payload_hash' => $proposal->payload_hash,
        ])->assertOk()->assertJsonPath('proposal.items.0.after', 'Rolling admission');

        $this->assertTrue($school->fresh()->rolling_admission);
    }
}
