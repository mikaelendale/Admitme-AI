<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Models\Application;
use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use App\Models\JobTracking;
use App\Models\SupplementalEssay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1 gate: two students cannot read or change each other's records.
 */
class StudentDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    public function test_college_choices_are_invisible_and_immutable_to_other_students(): void
    {
        $school = CollegeListSchool::factory()->for($this->owner)->create(['student_notes' => 'mine']);

        $this->actingAs($this->other)->get(route('college-lists.school', $school))->assertNotFound();
        $this->actingAs($this->other)->patchJson(route('college-lists.school.update', $school), ['student_notes' => 'hijacked'])->assertNotFound();
        $this->actingAs($this->other)->deleteJson(route('college-lists.school.destroy', $school))->assertNotFound();
        $this->actingAs($this->other)->post(route('applications.store'), ['college_list_school_id' => $school->id])->assertNotFound();

        $this->actingAs($this->other)
            ->get(route('college-lists'))
            ->assertInertia(fn ($page) => $page->has('colleges', 0));

        $school->refresh();
        $this->assertSame('mine', $school->student_notes);
        $this->assertFalse($school->is_removed);
        $this->assertNull($school->application);
    }

    public function test_applications_are_invisible_and_immutable_to_other_students(): void
    {
        $application = Application::factory()->create(['user_id' => $this->owner->id, 'notes' => 'mine']);
        $application->collegeListSchool->update(['user_id' => $this->owner->id]);

        $this->actingAs($this->other)
            ->patch(route('applications.update', $application), ['notes' => 'hijacked'])
            ->assertNotFound();

        $this->actingAs($this->other)
            ->get(route('applications.index'))
            ->assertInertia(fn ($page) => $page->has('applications', 0)->has('startable', 0));

        $this->assertSame('mine', $application->fresh()->notes);
    }

    public function test_conversations_cannot_be_opened_or_continued_by_other_students(): void
    {
        $conversationId = (string) Str::uuid();

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'participant_type' => $this->owner->getMorphClass(),
            'participant_id' => $this->owner->id,
            'title' => 'Private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AdmitMeGeneralAgent::fake(['should never run']);

        $this->actingAs($this->other)->get(route('dashboard.chat.show', ['conversation' => $conversationId]))->assertForbidden();
        $this->actingAs($this->other)
            ->postJson(route('dashboard.chat'), ['message' => 'show me their data', 'conversation_id' => $conversationId])
            ->assertForbidden();

        $this->assertSame(0, DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count());
        AdmitMeGeneralAgent::assertNeverPrompted();
    }

    public function test_background_runs_essays_and_tasks_stay_with_their_owner(): void
    {
        $run = CollegeListRun::factory()->create(['user_id' => $this->owner->id]);
        $supplement = SupplementalEssay::query()->create([
            'user_id' => $this->owner->id,
            'school_name' => 'Synthetic Ash College',
            'prompt_text' => 'Why us?',
            'essay_type' => SupplementalEssay::TYPE_WHY_SCHOOL,
            'status' => SupplementalEssay::STATUS_NOT_STARTED,
        ]);
        JobTracking::query()->create([
            'user_id' => $this->owner->id,
            'job_type' => JobTracking::TYPE_COLLEGE_LIST_COUNCIL,
            'status' => JobTracking::STATUS_COMPLETED,
            'payload' => ['run_id' => $run->id],
        ]);

        $this->actingAs($this->other)->getJson(route('college-lists.council-runs.show', $run->id))->assertForbidden();
        $this->actingAs($this->other)->patchJson(route('essay-suite.supplements.update', $supplement), ['notes' => 'x'])->assertForbidden();
        $this->actingAs($this->other)->deleteJson(route('essay-suite.supplements.destroy', $supplement))->assertForbidden();

        $this->actingAs($this->other)->get(route('tasks.index'))->assertInertia(fn ($page) => $page->has('tasks', 0));
        $this->actingAs($this->owner)->get(route('tasks.index'))->assertInertia(fn ($page) => $page->has('tasks', 1));

        $this->assertNotNull($supplement->fresh());
    }
}
