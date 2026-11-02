<?php

namespace Tests\Feature\AdmitMe;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\SubmissionEvidence;
use App\Models\Application;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Applications record real progress only: "submitted" needs evidence, and stale edits are rejected.
 */
class ApplicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function applicationFor(User $student, ApplicationStatus $status = ApplicationStatus::ReadyToSubmit): Application
    {
        $school = CollegeListSchool::factory()->for($student)->create(['choice_status' => 'applying']);

        return Application::factory()->create([
            'user_id' => $student->id,
            'college_list_school_id' => $school->id,
            'status' => $status,
        ]);
    }

    public function test_student_starts_an_application_from_a_shortlisted_college(): void
    {
        $student = User::factory()->create();
        $school = CollegeListSchool::factory()->for($student)->create();

        $this->actingAs($student)
            ->post(route('applications.store'), ['college_list_school_id' => $school->id, 'expected_revision' => 1])
            ->assertRedirect(route('applications.index'));

        $this->actingAs($student)
            ->get(route('applications.index'))
            ->assertInertia(fn ($page) => $page
                ->component('applications')
                ->has('applications', 1)
                ->where('applications.0.college.name', $school->school_name)
                ->where('applications.0.status', 'preparing')
                ->has('startable', 0));
    }

    public function test_an_application_cannot_be_marked_submitted_without_evidence(): void
    {
        $student = User::factory()->create();
        $application = $this->applicationFor($student);

        $this->actingAs($student)
            ->from(route('applications.index'))
            ->patch(route('applications.update', $application), ['status' => 'submitted', 'expected_revision' => 1])
            ->assertSessionHasErrors('submission_evidence_type');

        $application->refresh();
        $this->assertSame(ApplicationStatus::ReadyToSubmit, $application->status);
        $this->assertNull($application->submitted_at);
    }

    public function test_submission_with_student_confirmation_is_recorded_with_its_evidence_type(): void
    {
        $student = User::factory()->create();
        $application = $this->applicationFor($student);

        $this->actingAs($student)
            ->patch(route('applications.update', $application), [
                'status' => 'submitted',
                'submission_evidence_type' => 'student_confirmation',
                'submission_evidence_note' => 'Submitted from my laptop on Friday',
                'expected_revision' => 1,
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);
        $this->assertSame(SubmissionEvidence::StudentConfirmation, $application->submission_evidence_type);
        $this->assertNotNull($application->submitted_at);
        $this->assertSame(2, $application->revision);
    }

    public function test_impossible_status_jumps_and_stale_edits_are_rejected(): void
    {
        $student = User::factory()->create();
        $application = $this->applicationFor($student, ApplicationStatus::Preparing);

        $this->actingAs($student)
            ->from(route('applications.index'))
            ->patch(route('applications.update', $application), ['status' => 'decision_received'])
            ->assertSessionHasErrors('status');

        $this->actingAs($student)
            ->patch(route('applications.update', $application), ['notes' => 'first', 'expected_revision' => 1])
            ->assertSessionHasNoErrors();

        $this->actingAs($student)
            ->from(route('applications.index'))
            ->patch(route('applications.update', $application), ['notes' => 'second', 'expected_revision' => 1])
            ->assertSessionHasErrors('expected_revision');

        $this->assertSame('first', $application->fresh()->notes);
        $this->assertSame(ApplicationStatus::Preparing, $application->fresh()->status);
    }
}
