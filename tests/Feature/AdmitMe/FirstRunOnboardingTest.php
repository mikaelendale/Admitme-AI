<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\DocumentFactExtractorAgent;
use App\Jobs\BuildUserAiContextJob;
use App\Models\Document;
use App\Models\OnboardingProfile;
use App\Models\OnboardingResponse;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SyntheticPdf;
use Tests\TestCase;

/**
 * First-run onboarding follows the build plan's Phase 4 order and saves every answer through the
 * shared profile service, so it is resumable and audited like any other edit.
 */
class FirstRunOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::factory()->create(['onboarded' => false, 'email_verified_at' => now()]);
    }

    private function consent(User $student): void
    {
        $this->actingAs($student)->post(route('onboarding.consent'), ['age_range' => '17_18', 'legal_accepted' => '1'])->assertRedirect(route('onboarding'));
    }

    public function test_steps_follow_the_plan_order_and_consent_comes_first(): void
    {
        $student = $this->student();

        $this->actingAs($student)->get(route('onboarding'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding')
                ->where('current', 'consent')
                ->where('steps.0.key', 'consent')
                ->where('steps.1.key', 'situation')
                ->where('steps.2.key', 'academics')
                ->where('steps.3.key', 'interests')
                ->where('steps.4.key', 'destinations')
                ->where('steps.5.key', 'budget')
                ->where('steps.6.key', 'documents')
                ->where('steps.7.key', 'review')
                ->missing('steps.8'));

        // Asking for a later step before consent still shows consent, and nothing can be saved yet.
        $this->actingAs($student)->get(route('onboarding', ['step' => 'budget']))->assertInertia(fn (Assert $page) => $page->where('current', 'consent'));
        $this->actingAs($student)->post(route('onboarding.sections.save', 'situation'), ['location_country' => 'Ghana'])->assertRedirect(route('onboarding'));
        $this->assertNull(OnboardingProfile::query()->where('user_id', $student->id)->value('location_country'));

        // Workspace pages send an un-onboarded student back here.
        $this->actingAs($student)->get(route('dashboard'))->assertRedirect(route('onboarding'));
    }

    public function test_consent_requires_an_age_range_and_the_terms(): void
    {
        $student = $this->student();

        $this->actingAs($student)->post(route('onboarding.consent'), ['age_range' => '10_12'])->assertSessionHasErrors(['age_range', 'legal_accepted']);

        $this->consent($student);
        $profile = OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertTrue((bool) $profile->is_age_13_or_older_confirmed);
        $this->assertNotNull($profile->legal_accepted_at);
    }

    public function test_sections_save_through_the_profile_service_and_resume_at_the_next_step(): void
    {
        $student = $this->student();
        $this->consent($student);

        $this->actingAs($student)
            ->post(route('onboarding.sections.save', 'situation'), [
                'applicant_situation' => 'gap_year',
                'citizenship_residency' => 'Kenyan citizen',
                'location_country' => 'Kenya',
                'graduation_year' => 2026,
                'target_intake' => 'Fall 2027',
                'unknown' => ['first_generation', 'location_region'],
            ])
            ->assertRedirect(route('onboarding', ['step' => 'academics']));

        $profile = OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('Kenya', $profile->location_country);
        $this->assertSame('gap_year', $profile->applicant_situation);
        $this->assertContains('first_generation', (array) $profile->unknown_fields_json);

        $fact = StudentFact::query()->where('user_id', $student->id)->where('field_key', 'location_country')->whereNull('superseded_at')->firstOrFail();
        $this->assertSame('confirmed', $fact->status->value);
        $this->assertSame('student', $fact->actor_type);
        $this->assertSame('onboarding.situation', $fact->source_ref['via'] ?? null);

        // A reload resumes where the student left off.
        $this->actingAs($student)->get(route('onboarding'))->assertInertia(fn (Assert $page) => $page->where('current', 'academics')->where('steps.1.completed', true));

        // Lists typed as text become lists.
        $this->actingAs($student)->post(route('onboarding.sections.save', 'interests'), ['intended_majors' => 'Economics, Computer Science'])->assertRedirect();
        $this->assertSame(['Economics', 'Computer Science'], OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail()->intended_majors_json);
    }

    public function test_review_lists_only_open_questions_and_answers_save_without_finishing_a_section(): void
    {
        $student = $this->student();
        $this->consent($student);

        $this->actingAs($student)->get(route('onboarding', ['step' => 'review']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('current', 'review')
                ->where('readiness.college_research.ready', false)
                ->has('missingFields'));

        $this->actingAs($student)
            ->post(route('onboarding.answers'), ['keys' => ['curriculum', 'location_country'], 'curriculum' => 'A-Levels', 'location_country' => 'Ghana'])
            ->assertRedirect(route('onboarding', ['step' => 'review']));

        $profile = OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('A-Levels', $profile->curriculum);
        $this->assertSame('Ghana', $profile->location_country);

        $this->actingAs($student)->post(route('onboarding.answers'), ['keys' => ['not_a_field']])->assertStatus(422);
    }

    public function test_a_document_uploaded_during_onboarding_goes_through_the_document_pipeline(): void
    {
        Storage::fake('local');
        DocumentFactExtractorAgent::fake([['candidates' => []]]);
        $student = $this->student();
        $this->consent($student);

        $file = UploadedFile::fake()->createWithContent('transcript.pdf', SyntheticPdf::make([['Synthetic Academy - Official Transcript', 'Cumulative GPA: 3.70 (Scale: 4.0)']]));

        $this->actingAs($student)->post(route('onboarding.documents.store'), ['file' => $file, 'kind' => 'transcript'])
            ->assertRedirect(route('onboarding', ['step' => 'documents']));

        $document = Document::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('transcript', $document->kind);
        $this->assertNotNull($document->currentVersion);

        // Nothing read from it reaches the profile on its own.
        $this->assertNull(OnboardingProfile::query()->where('user_id', $student->id)->value('gpa_value'));

        $this->actingAs($student)->get(route('onboarding', ['step' => 'documents']))->assertInertia(fn (Assert $page) => $page->where('documents.0.id', (string) $document->id));
    }

    public function test_finishing_requires_consent_and_marks_the_student_onboarded(): void
    {
        Queue::fake([BuildUserAiContextJob::class]);
        $student = $this->student();

        $this->actingAs($student)->post(route('onboarding.complete'))->assertSessionHasErrors('onboarding');
        $this->assertFalse((bool) $student->fresh()->onboarded);

        $this->consent($student);
        $this->actingAs($student)->post(route('onboarding.complete'))->assertRedirect(route('dashboard'));

        $this->assertTrue((bool) $student->fresh()->onboarded);
        Queue::assertPushed(BuildUserAiContextJob::class);
        $this->actingAs($student)->get(route('onboarding'))->assertRedirect(route('dashboard'));
    }

    public function test_the_plan_step_appears_only_when_billing_applies(): void
    {
        $student = $this->student();
        $this->consent($student);

        $this->actingAs($student)->get(route('onboarding'))->assertInertia(fn (Assert $page) => $page->where('billing.needed', false)->missing('steps.8'));

        config(['billing.enforcement.enabled' => true]);
        $this->actingAs($student)->get(route('onboarding', ['step' => 'plan']))
            ->assertInertia(fn (Assert $page) => $page->where('billing.needed', true)->where('steps.8.key', 'plan')->where('current', 'plan'));

        // Skipping the plan finishes onboarding and is recorded for the billing page.
        Queue::fake([BuildUserAiContextJob::class]);
        $this->actingAs($student)->post(route('onboarding.skip-billing'))->assertRedirect(route('dashboard'));
        $this->assertTrue((bool) $student->fresh()->onboarded);
        $this->assertTrue(OnboardingResponse::query()->where('user_id', $student->id)->where('question_key', 'billing.skipped')->exists());
    }
}
