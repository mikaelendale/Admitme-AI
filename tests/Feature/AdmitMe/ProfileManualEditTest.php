<?php

namespace Tests\Feature\AdmitMe;

use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentProfileService;
use App\Models\AuditEvent;
use App\Models\OnboardingProfile;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 1 gate: a student can save and reopen their profile; stale conflicting edits are detected.
 */
class ProfileManualEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_saves_profile_fields_and_sees_them_after_reopening(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['sat_score' => 1400]);

        $this->actingAs($student)
            ->post('/profile-completion/clarify', [
                'sat_score' => 1510,
                'location_country' => 'Canada',
                'expected_revision' => 1,
            ])
            ->assertRedirect(route('profile-overview'))
            ->assertSessionHasNoErrors();

        $this->actingAs($student)
            ->get(route('profile-overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('profile-overview')
                ->where('profile.revision', 2)
                ->where('profile.academics.sat_score', 1510)
                ->where('profile.academics.location_country', 'Canada'));
    }

    public function test_stale_manual_edit_is_rejected_and_changes_nothing(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['sat_score' => 1400]);

        // Two tabs opened at revision 1; the first save wins...
        $this->actingAs($student)
            ->post('/profile-completion/clarify', ['sat_score' => 1450, 'expected_revision' => 1])
            ->assertSessionHasNoErrors();

        $this->actingAs($student)
            ->from(route('profile-overview'))
            ->post('/profile-completion/clarify', ['sat_score' => 1300, 'expected_revision' => 1])
            ->assertRedirect(route('profile-overview'))
            ->assertSessionHasErrors('expected_revision');

        $profile = OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame(1450, $profile->sat_score);
        $this->assertSame(2, $profile->revision);
    }

    public function test_writes_from_older_flows_bump_the_revision_so_a_stale_manual_edit_is_caught(): void
    {
        $student = User::factory()->create();
        $profile = OnboardingProfile::factory()->for($student)->create(['sat_score' => 1400]);

        // e.g. AI extraction saving the model directly, outside the profile service...
        $profile->forceFill(['sat_score' => 1350])->save();
        $this->assertSame(2, $profile->fresh()->revision);

        $this->actingAs($student)
            ->from(route('profile-overview'))
            ->post('/profile-completion/clarify', ['sat_score' => 1600, 'expected_revision' => 1])
            ->assertSessionHasErrors('expected_revision');

        $this->assertSame(1350, $profile->fresh()->sat_score);

        $legacyFact = StudentFact::query()->where('user_id', $student->id)->where('field_key', 'sat_score')->current()->firstOrFail();
        $this->assertSame(ChangeSource::SOURCE_LEGACY_FLOW, $legacyFact->source_type);
        $this->assertSame(FactStatus::Inferred, $legacyFact->status, 'A system write is not student-confirmed.');
    }

    public function test_manual_save_records_provenance_and_an_audit_event(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['sat_score' => 1400]);

        $this->actingAs($student)
            ->post('/profile-completion/clarify', ['sat_score' => 1480, 'expected_revision' => 1])
            ->assertSessionHasNoErrors();

        $facts = StudentFact::query()->where('user_id', $student->id)->where('field_key', 'sat_score')->orderBy('id')->get();
        $current = $facts->last();

        $this->assertSame(1480, $current->value);
        $this->assertSame(FactStatus::Confirmed, $current->status);
        $this->assertSame(ChangeSource::SOURCE_MANUAL, $current->source_type);
        $this->assertSame(2, $current->profile_revision);
        $this->assertNull($current->superseded_at);
        $this->assertNotNull($facts->first()->superseded_at, 'The earlier value is kept but superseded.');

        $audit = AuditEvent::query()->where('user_id', $student->id)->where('action', 'profile.updated')->latest('id')->firstOrFail();
        $this->assertSame(['before' => 1400, 'after' => 1480], $audit->changes['sat_score']);
        $this->assertSame(1, $audit->revision_before);
        $this->assertSame(2, $audit->revision_after);
        $this->assertSame('student', $audit->actor_type);
    }

    public function test_saving_identical_values_does_not_create_a_new_revision(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['sat_score' => 1400]);

        $result = app(StudentProfileService::class)->update($student, ['sat_score' => 1400], 1, ChangeSource::studentManual($student, 'test'));

        $this->assertFalse($result->changed());
        $this->assertSame(1, OnboardingProfile::query()->where('user_id', $student->id)->value('revision'));
    }

    public function test_derived_and_unknown_fields_cannot_be_written_through_the_profile_service(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create();

        try {
            app(StudentProfileService::class)->update($student, ['gpa_normalized_4' => 4.0, 'sat_score' => 1600], null, ChangeSource::studentManual($student, 'test'));
            $this->fail('Unknown field was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fields', $e->errors());
        }

        $this->assertNotSame(1600, OnboardingProfile::query()->where('user_id', $student->id)->value('sat_score'));
    }
}
