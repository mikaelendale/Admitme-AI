<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Tools\UpsertStudentCollegeList;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Applications\CollegeListService;
use App\Domain\Shared\ChangeSource;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Phase 1 gate: a student can save and reopen their college list; stale edits are detected;
 * chat and manual edits share one service.
 */
class CollegeListManualTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_adds_a_college_and_sees_it_after_reopening_the_list(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->postJson(route('college-lists.schools.manual'), ['school_name' => 'Synthetic Pine College', 'tier' => 'match'])
            ->assertCreated();

        $this->actingAs($student)
            ->get(route('college-lists'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('colleges', 1)
                ->where('colleges.0.name', 'Synthetic Pine College')
                ->where('colleges.0.choiceStatus', 'shortlisted')
                ->where('colleges.0.revision', 1));
    }

    public function test_notes_save_with_the_current_revision_and_a_stale_save_is_rejected(): void
    {
        $student = User::factory()->create();
        $school = CollegeListSchool::factory()->for($student)->create();

        $this->actingAs($student)
            ->patchJson(route('college-lists.school.update', $school), ['student_notes' => 'Visit in March', 'expected_revision' => 1])
            ->assertOk()
            ->assertJson(['revision' => 2]);

        $this->actingAs($student)
            ->patchJson(route('college-lists.school.update', $school), ['student_notes' => 'Older tab text', 'expected_revision' => 1])
            ->assertStatus(409)
            ->assertJson(['error' => 'stale_revision', 'current_revision' => 2]);

        $this->assertSame('Visit in March', $school->fresh()->student_notes);

        $this->actingAs($student)
            ->get(route('college-lists.school', $school))
            ->assertInertia(fn ($page) => $page->where('school.studentNotes', 'Visit in March')->where('school.revision', 2));
    }

    public function test_duplicate_colleges_are_rejected_but_an_archived_one_is_restored(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->postJson(route('college-lists.schools.manual'), ['school_name' => 'Synthetic Oak University', 'tier' => 'reach'])
            ->assertCreated();

        $this->actingAs($student)
            ->postJson(route('college-lists.schools.manual'), ['school_name' => 'Synthetic Oak University', 'tier' => 'reach'])
            ->assertUnprocessable();

        $school = CollegeListSchool::query()->where('user_id', $student->id)->sole();

        $this->actingAs($student)->deleteJson(route('college-lists.school.destroy', $school))->assertOk();
        $this->assertSame(CollegeChoiceStatus::Archived, $school->fresh()->choice_status);
        $this->assertTrue($school->fresh()->is_removed);

        $this->actingAs($student)
            ->postJson(route('college-lists.schools.manual'), ['school_name' => 'Synthetic Oak University', 'tier' => 'match'])
            ->assertCreated();

        $this->assertSame(1, CollegeListSchool::query()->where('user_id', $student->id)->count());
        $this->assertSame(CollegeChoiceStatus::Shortlisted, $school->fresh()->choice_status);
        $this->assertFalse($school->fresh()->is_removed);
    }

    public function test_moving_a_college_to_applying_opens_exactly_one_application(): void
    {
        $student = User::factory()->create();
        $school = CollegeListSchool::factory()->for($student)->create();

        $this->actingAs($student)
            ->patchJson(route('college-lists.school.update', $school), ['choice_status' => 'applying'])
            ->assertOk()
            ->assertJsonPath('choiceStatus', 'applying');

        // A repeated request (double click, retry) must not open a second application...
        $this->actingAs($student)
            ->patchJson(route('college-lists.school.update', $school), ['choice_status' => 'applying'])
            ->assertOk();

        $this->assertSame(1, Application::query()->where('college_list_school_id', $school->id)->count());
        $this->assertSame('applying', $school->fresh()->application_status);
    }

    public function test_the_older_progress_dropdown_and_the_list_status_stay_in_step(): void
    {
        $student = User::factory()->create();
        $school = CollegeListSchool::factory()->for($student)->create();

        $this->actingAs($student)
            ->patchJson(route('college-lists.school.update', $school), ['application_status' => 'applying'])
            ->assertOk()
            ->assertJsonPath('choiceStatus', 'applying');

        $this->assertNotNull($school->fresh()->application);
    }

    public function test_invalid_list_transitions_are_rejected(): void
    {
        $student = User::factory()->create();
        $school = CollegeListSchool::factory()->for($student)->suggested()->create();

        $this->expectException(ValidationException::class);

        app(CollegeListService::class)->update($school, ['choice_status' => 'applying'], null, ChangeSource::studentManual($student, 'test'));
    }

    public function test_chat_list_edits_go_through_the_shared_service_and_are_attributed_to_the_agent(): void
    {
        $student = User::factory()->create();
        $school = CollegeListSchool::factory()->for($student)->create(['school_name' => 'Synthetic Elm College', 'school_slug' => 'synthetic-elm-college']);

        $result = (new UpsertStudentCollegeList($student))->handle(new Request([
            'school_name' => 'Synthetic Elm College',
            'list_type' => 'safety',
            'notes' => 'Added by mentor',
        ]));

        $this->assertTrue(json_decode((string) $result, true)['ok']);

        $school->refresh();
        $this->assertSame('safety', $school->tier);
        $this->assertSame(2, $school->revision, 'Agent writes bump the revision like manual ones.');

        $audit = AuditEvent::query()->where('subject_id', $school->id)->latest('id')->firstOrFail();
        $this->assertSame('agent', $audit->actor_type);
        $this->assertSame(ChangeSource::SOURCE_AGENT_TOOL, $audit->context['source_type']);
    }
}
