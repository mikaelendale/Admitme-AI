<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Tools\ReadApplicationTool;
use App\Domain\Applications\ChecklistStatus;
use App\Domain\Applications\CompletionBasis;
use App\Models\AdmissionRequirement;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\Document;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request;
use Tests\Support\KnowledgeFixtures;
use Tests\TestCase;

/**
 * Phase 6 gate: opening an application shows real requirements (with sources) as a checklist with
 * deadlines, blockers and next actions; third-party work is never complete because it was requested; and
 * the mentor reads exactly the state the screen shows.
 */
class ApplicationWorkspaceTest extends TestCase
{
    use KnowledgeFixtures;
    use RefreshDatabase;

    private User $student;

    private CollegeListSchool $school;

    private KnowledgeSourceVersion $official;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create();
        $this->school = CollegeListSchool::factory()->for($this->student)->create([
            'school_name' => 'Synthetic Lakeside University',
            'school_slug' => 'synthetic-lakeside-university',
            'admissions_cycle_label' => 'Fall 2027 entry',
            'application_portal_url' => 'https://apply.synthetic-lakeside.edu',
        ]);

        [, $this->official] = $this->sourceFor($this->institutionFor($this->school), 'https://admissions.synthetic-lakeside.edu/apply');
        $this->requirementFrom($this->official, ['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'deadline_date' => '2027-01-01', 'deadline_time' => '23:59', 'timezone' => 'America/New_York', 'cycle_label' => 'Fall 2027 entry', 'quote' => 'Regular Decision applications are due January 1, 2027']);
        $this->requirementFrom($this->official, ['requirement_type' => 'fee', 'label' => 'Application fee', 'amount' => 75, 'currency' => 'USD', 'conditions' => 'Waivers for financial need', 'quote' => 'The application fee is USD 75.']);
        $this->requirementFrom($this->official, ['requirement_type' => 'test_policy', 'label' => 'Testing policy', 'value_text' => 'Test-optional', 'quote' => 'We are test-optional.']);
        $this->requirementFrom($this->official, ['requirement_type' => 'english_test', 'label' => 'English proficiency', 'value_text' => 'TOEFL iBT 100 or IELTS 7.0', 'quote' => 'TOEFL iBT 100 or IELTS 7.0']);
        $this->requirementFrom($this->official, ['requirement_type' => 'recommendation', 'label' => 'Teacher recommendation', 'value_text' => 'One letter from a teacher of an academic subject', 'quote' => 'One teacher recommendation']);
        $this->requirementFrom($this->official, ['requirement_type' => 'essay', 'label' => 'Why Lakeside essay', 'value_text' => 'Why do you want to study at Lakeside? (250 words)', 'quote' => 'Why do you want to study at Lakeside?']);
        $this->requirementFrom($this->official, ['requirement_type' => 'document', 'label' => 'Official transcript', 'value_text' => 'Sent by your school counselor', 'quote' => 'Official transcripts must be sent by your school counselor']);
        $this->requirementFrom($this->official, ['requirement_type' => 'cost', 'label' => 'Cost of attendance', 'amount' => 82500, 'currency' => 'USD', 'quote' => 'Cost of attendance USD 82,500']);
    }

    private function open(): Application
    {
        $this->actingAs($this->student)
            ->patchJson(route('college-lists.school.update', $this->school), ['choice_status' => 'applying'])
            ->assertOk();

        return Application::query()->where('college_list_school_id', $this->school->id)->sole();
    }

    private function item(Application $application, string $kind, ?string $titleContains = null): ApplicationItem
    {
        return $application->items()->get()->first(fn (ApplicationItem $i) => $i->kind === $kind && ($titleContains === null || str_contains($i->title, $titleContains)))
            ?? $this->fail("No {$kind} item".($titleContains ? " containing \"{$titleContains}\"" : ''));
    }

    private function patchItem(ApplicationItem $item, array $data)
    {
        return $this->actingAs($this->student)->patch(route('applications.items.update', [$item->application_id, $item->id]), $data);
    }

    public function test_opening_an_application_builds_a_checklist_from_sourced_requirements(): void
    {
        $application = $this->open();
        $items = $application->items()->get();

        $deadline = $this->item($application, 'deadline');
        $this->assertSame('Regular Decision deadline: 1 Jan 2027 23:59 (America/New_York)', $deadline->title);
        $this->assertSame('2027-01-01', $deadline->due_date->toDateString());
        $this->assertSame('America/New_York', $deadline->timezone);
        $this->assertNotNull($deadline->admission_requirement_id);

        $this->assertSame('Pay the application fee (USD 75)', $this->item($application, 'fee')->title);
        $this->assertStringContainsString('Waivers for financial need', $this->item($application, 'fee')->details);
        $this->assertSame('student', $this->item($application, 'test')->owner_type->value, 'Test-optional means a decision for the student.');

        $english = $this->item($application, 'english_test');
        $this->assertSame(['third_party', 'Testing agency (ETS / IELTS)', ChecklistStatus::NotRequested], [$english->owner_type->value, $english->owner_label, $english->status]);
        $this->assertSame('Teacher', $this->item($application, 'recommendation')->owner_label);
        $this->assertStringContainsString('Why do you want to study at Lakeside?', $this->item($application, 'essay')->details);

        // The transcript requirement enriches the standard "school documents" step instead of duplicating it...
        $schoolDocuments = $items->where('item_key', 'default:school_documents')->sole();
        $this->assertSame(['Official transcript', 'requirement', 'School counselor'], [$schoolDocuments->title, $schoolDocuments->origin, $schoolDocuments->owner_label]);
        $this->assertSame(1, $items->where('kind', 'document')->count());

        $this->assertTrue($items->contains('item_key', 'default:portal_account'));
        $this->assertTrue($items->contains('item_key', 'default:application_form'));
        $this->assertFalse($items->contains('kind', 'cost'), 'Costs inform affordability; they are not application steps.');
        $this->assertNotNull($application->checklist_built_at);
    }

    public function test_a_fee_waiver_is_its_own_step_and_never_replaces_the_fee(): void
    {
        // Found end-to-end: the live extractor listed the waiver as a second "fee" and it took over the fee step.
        $this->requirementFrom($this->official, ['requirement_type' => 'fee', 'label' => 'Application fee waiver', 'value_text' => 'Fee waivers are available for students with demonstrated financial need.', 'quote' => 'Fee waivers are available']);

        $application = $this->open();
        $fees = $application->items()->where('kind', 'fee')->get();

        $this->assertCount(2, $fees);
        $this->assertContains('Pay the application fee (USD 75)', $fees->pluck('title'));
        $this->assertContains('Application fee waiver', $fees->pluck('title'));
    }

    public function test_third_party_work_is_not_complete_when_requested(): void
    {
        $recommendation = $this->item($this->open(), 'recommendation');

        $this->patchItem($recommendation, ['status' => 'requested', 'expected_revision' => 1])->assertSessionHasNoErrors();
        $recommendation->refresh();
        $this->assertSame(ChecklistStatus::Requested, $recommendation->status);
        $this->assertFalse($recommendation->status->isComplete());
        $this->assertNull($recommendation->completed_at);

        $this->patchItem($recommendation, ['status' => 'done'])->assertSessionHasErrors('status');
        $this->patchItem($recommendation, ['status' => 'received'])->assertSessionHasErrors('completion_basis');
        $this->patchItem($recommendation, ['status' => 'received', 'completion_basis' => 'student_reported'])->assertSessionHasErrors('completion_basis');
        $this->assertSame(ChecklistStatus::Requested, $recommendation->fresh()->status);

        $this->patchItem($recommendation, ['status' => 'received', 'completion_basis' => 'portal_shows_received'])->assertSessionHasNoErrors();
        $recommendation->refresh();
        $this->assertSame(ChecklistStatus::Received, $recommendation->status);
        $this->assertSame(CompletionBasis::PortalShowsReceived, $recommendation->completion_basis);
        $this->assertNotNull($recommendation->completed_at);
    }

    public function test_student_steps_are_self_reported_and_need_a_reason_when_blocked_or_not_needed(): void
    {
        $application = $this->open();
        $fee = $this->item($application, 'fee');
        $test = $this->item($application, 'test');
        $deadline = $this->item($application, 'deadline');

        $this->patchItem($fee, ['status' => 'done'])->assertSessionHasNoErrors();
        $this->assertSame(CompletionBasis::StudentReported, $fee->fresh()->completion_basis);

        $this->patchItem($test, ['status' => 'blocked'])->assertSessionHasErrors('status_note');
        $this->patchItem($test, ['status' => 'blocked', 'status_note' => 'Waiting for my SAT score to decide'])->assertSessionHasNoErrors();
        $this->patchItem($deadline, ['due_date' => '2027-02-01'])->assertSessionHasErrors('due_date');
        $this->patchItem($deadline, ['status' => 'not_applicable', 'status_note' => 'Applying Early Action instead'])->assertSessionHasNoErrors();

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->where('workspace.progress.yoursDone', 1)
                ->where('workspace.blockers', fn ($blockers) => collect($blockers)->contains(fn ($b) => str_contains($b['text'], 'Waiting for my SAT score')))
                ->where('workspace.checklist', fn ($items) => collect($items)->firstWhere('kind', 'fee')['completionBasis'] === 'Marked done by you'));
    }

    public function test_rebuilding_keeps_progress_updates_open_steps_and_flags_requirements_that_disappeared(): void
    {
        $application = $this->open();
        $fee = $this->item($application, 'fee');
        $this->patchItem($fee, ['status' => 'done'])->assertSessionHasNoErrors();
        $count = $application->items()->count();

        // The page changed: a new deadline, a new fee, and the essay is gone...
        AdmissionRequirement::query()->update(['retired_at' => now()]);
        $this->requirementFrom($this->official, ['requirement_type' => 'deadline', 'label' => 'RD application deadline', 'deadline_date' => '2027-01-05', 'cycle_label' => 'Fall 2027 entry', 'quote' => 'Applications are due January 5, 2027']);
        $this->requirementFrom($this->official, ['requirement_type' => 'fee', 'label' => 'Application fee', 'amount' => 90, 'currency' => 'USD', 'quote' => 'The application fee is USD 90.']);

        $this->actingAs($this->student)->post(route('applications.checklist.rebuild', $application))->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame($count, $application->items()->count(), 'Rebuilding never duplicates steps.');
        $this->assertSame('2027-01-05', $this->item($application, 'deadline')->due_date->toDateString(), 'Differently worded, same requirement: the open step is updated.');
        $this->assertSame('Pay the application fee (USD 75)', $this->item($application, 'fee')->title, 'A finished step keeps what the student finished.');
        $this->assertSame(ChecklistStatus::Done, $this->item($application, 'fee')->status);
        $this->assertNotNull($this->item($application, 'essay')->source_missing_at);
    }

    public function test_next_actions_put_disagreeing_sources_and_third_party_requests_first(): void
    {
        [, $reference] = $this->sourceFor($this->institutionFor($this->school), 'https://www.commonapp.org/explore/synthetic-lakeside', KnowledgeSource::TYPE_REFERENCE);
        $this->requirementFrom($reference, ['requirement_type' => 'deadline', 'label' => 'Regular Decision', 'deadline_date' => '2027-01-15', 'quote' => 'Regular Decision: January 15, 2027']);

        $application = $this->open();
        $deadline = $this->item($application, 'deadline');
        $this->assertSame('2027-01-01', $deadline->due_date->toDateString(), 'The official source wins the date…');
        $this->assertStringContainsString('Sources disagree', $deadline->details, '…and the disagreement is spelled out.');

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertInertia(function (Assert $page) {
                $actions = array_column($page->toArray()['props']['workspace']['nextActions'], 'text');

                $this->assertStringStartsWith('Check the official site: Sources disagree on Regular Decision deadline', $actions[0]);
                // Then every request to someone else, in checklist order, before the student's own steps...
                $this->assertSame([
                    'Ask School counselor for: Official transcript',
                    'Ask Testing agency (ETS / IELTS) for: Official English test score report (TOEFL iBT 100 or IELTS 7.0)',
                    'Ask Teacher for: Teacher recommendation',
                ], array_slice($actions, 1, 3));
                $this->assertCount(5, $actions);
            });
    }

    public function test_the_mentor_reads_exactly_what_the_screen_shows(): void
    {
        $application = $this->open();
        $this->patchItem($this->item($application, 'recommendation'), ['status' => 'requested'])->assertSessionHasNoErrors();

        $screen = null;
        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertInertia(function (Assert $page) use (&$screen) {
                $screen = $page->toArray()['props']['workspace'];
            });

        $chat = json_decode((string) (new ReadApplicationTool($this->student))->handle(new Request(['application_id' => $application->id])), true);

        $this->assertSame(array_column($screen['nextActions'], 'text'), $chat['next_actions']);
        $this->assertSame(array_column($screen['blockers'], 'text'), $chat['blockers']);
        $this->assertSame(array_column($screen['checklist'], 'title'), array_column($chat['checklist'], 'title'));
        $this->assertSame(array_column($screen['checklist'], 'statusLabel'), array_column($chat['checklist'], 'status'));
        $this->assertSame($screen['deadline'], $chat['deadline']);
        $this->assertSame('Requested — waiting for them', collect($chat['checklist'])->firstWhere('title', 'Teacher recommendation')['status']);
        $this->assertFalse(collect($chat['checklist'])->firstWhere('title', 'Teacher recommendation')['complete']);

        $all = json_decode((string) (new ReadApplicationTool($this->student))->handle(new Request(['application_id' => null])), true);
        $this->assertSame($screen['nextActions'][0]['text'], $all['applications'][0]['nextAction']);
    }

    public function test_documents_must_be_the_students_own_and_workspaces_are_private(): void
    {
        $application = $this->open();
        $step = $application->items()->create([
            'user_id' => $this->student->id, 'item_key' => 'manual:passport', 'kind' => 'document', 'title' => 'Passport copy',
            'origin' => ApplicationItem::ORIGIN_MANUAL, 'owner_type' => 'student', 'status' => 'todo', 'position' => 1000,
        ]);
        $other = User::factory()->create();
        $theirs = Document::query()->create(['user_id' => $other->id, 'kind' => 'other', 'title' => 'Not yours']);
        $mine = Document::query()->create(['user_id' => $this->student->id, 'kind' => 'other', 'title' => 'Passport scan']);

        $this->patchItem($step, ['document_id' => $theirs->id])->assertSessionHasErrors('document_id');
        $this->patchItem($step, ['document_id' => $mine->id])->assertSessionHasNoErrors();
        $this->assertSame($mine->id, $step->fresh()->document_id);

        $this->actingAs($other)->get(route('applications.show', $application))->assertNotFound();
        $this->actingAs($other)->patch(route('applications.items.update', [$application->id, $step->id]), ['status' => 'done'])->assertNotFound();
        $this->actingAs($other)->post(route('applications.items.store', $application), ['title' => 'Sneaky'])->assertNotFound();
        $this->actingAs($other)->post(route('applications.checklist.rebuild', $application))->assertNotFound();

        // An item can only be changed through its own application...
        $otherSchool = CollegeListSchool::factory()->for($this->student)->create();
        $this->actingAs($this->student)->patchJson(route('college-lists.school.update', $otherSchool), ['choice_status' => 'applying'])->assertOk();
        $otherApplication = Application::query()->where('college_list_school_id', $otherSchool->id)->sole();
        $this->actingAs($this->student)->patch(route('applications.items.update', [$otherApplication->id, $step->id]), ['status' => 'done'])->assertNotFound();
    }

    public function test_students_add_their_own_steps_including_third_party_ones(): void
    {
        $application = $this->open();

        $this->actingAs($this->student)->post(route('applications.items.store', $application), ['title' => 'Ask for a second letter', 'owner_type' => 'third_party'])->assertSessionHasErrors('owner_label');
        $this->actingAs($this->student)->post(route('applications.items.store', $application), ['title' => 'Ask for a second letter', 'owner_type' => 'third_party', 'owner_label' => 'Physics teacher', 'due_date' => '2026-12-01'])->assertSessionHasNoErrors();

        $added = $application->items()->where('origin', ApplicationItem::ORIGIN_MANUAL)->sole();
        $this->assertSame([ChecklistStatus::NotRequested, 'Physics teacher', '2026-12-01'], [$added->status, $added->owner_label, $added->due_date->toDateString()]);

        // Rebuilding leaves the student's own steps alone...
        $this->actingAs($this->student)->post(route('applications.checklist.rebuild', $application));
        $this->assertNull($added->fresh()->source_missing_at);
        $this->assertSame(1, $application->items()->where('origin', ApplicationItem::ORIGIN_MANUAL)->count());
    }

    public function test_applications_opened_before_checklists_existed_get_one_on_first_view(): void
    {
        $application = Application::factory()->for($this->student)->create(['college_list_school_id' => $this->school->id]);
        $this->assertSame(0, $application->items()->count());

        $this->actingAs($this->student)->get(route('applications.show', $application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workspace.checklist', 9)->where('workspace.requirements.changedSinceBuilt', false));
    }
}
