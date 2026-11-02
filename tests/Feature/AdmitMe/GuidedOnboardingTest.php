<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Context\ChatMode;
use App\Ai\Context\ContextBuilder;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 gate: guided onboarding resumes after reload, accepts "don't know", and keeps the student's
 * own budget currency.
 */
class GuidedOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_resumes_at_the_next_section_after_reload(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['target_intake' => null]);

        $this->actingAs($student)
            ->post(route('profile-setup.save', 'situation'), [
                'applicant_situation' => 'gap_year',
                'first_generation' => 'yes',
                'citizenship_residency' => 'Ghanaian citizen',
                'location_country' => 'Ghana',
                'location_region' => 'Greater Accra',
                'graduation_year' => 2026,
                'target_intake' => 'Fall 2027',
            ])
            ->assertRedirect(route('profile-setup', ['section' => 'academics']));

        $this->actingAs($student)->get(route('profile-setup'))
            ->assertInertia(fn ($page) => $page
                ->component('profile-setup')
                ->where('current', 'academics')
                ->where('sections.0.completed', true)
                ->where('sections.0.fields.0.value', 'gap_year'));

        $profile = OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame('gap_year', $profile->applicant_situation);
        $this->assertSame('Fall 2027', $profile->target_intake);
        $this->assertSame('yes', $profile->first_generation);
    }

    public function test_dont_know_answers_count_as_answered_and_are_not_asked_again(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['gpa_value' => '3.20', 'gpa_scale' => '4.00', 'sat_score' => null]);

        $this->actingAs($student)
            ->post(route('profile-setup.save', 'academics'), [
                'curriculum' => 'WAEC / WASSCE',
                'current_grade' => 'SHS 3',
                'gpa_value' => '',
                'gpa_scale' => '',
                'gpa_type' => '',
                'sat_score' => '',
                'act_score' => '',
                'test_optional_status' => '',
                'unknown' => ['gpa_value', 'gpa_scale', 'gpa_type', 'sat_score', 'act_score'],
            ])
            ->assertSessionHasNoErrors();

        $profile = OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertEqualsCanonicalizing(['gpa_value', 'gpa_scale', 'gpa_type', 'sat_score', 'act_score'], $profile->unknown_fields_json);

        $brief = app(ContextBuilder::class)->forMentor($student, ChatMode::Ask)->instructions;
        $this->assertMatchesRegularExpression('/don\'t know \/ it doesn\'t apply[^\n]*\n- [^\n]*SAT score/', $brief);
        $this->assertDoesNotMatchRegularExpression('/### Not on file\n- [^\n]*SAT score/', $brief);

        // Giving a real answer later clears the "don't know"...
        $this->actingAs($student)
            ->post(route('profile-setup.save', 'academics'), ['curriculum' => 'WAEC / WASSCE', 'sat_score' => 1300, 'unknown' => ['act_score']])
            ->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame(1300, $profile->sat_score);
        $this->assertSame(['act_score'], $profile->unknown_fields_json);
    }

    public function test_budget_keeps_the_students_currency_and_readiness_reflects_it(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['budget_band' => null]);

        $this->actingAs($student)
            ->post(route('profile-setup.save', 'budget'), ['budget_amount' => 9000000, 'budget_currency' => 'ngn', 'financial_aid_need_percent' => 80])
            ->assertSessionHasNoErrors();

        $profile = OnboardingProfile::query()->where('user_id', $student->id)->firstOrFail();
        $this->assertSame(9000000, $profile->budget_amount);
        $this->assertSame('NGN', $profile->budget_currency, 'The amount is stored in the student\'s currency, never converted.');

        $this->actingAs($student)->get(route('profile-setup'))
            ->assertInertia(fn ($page) => $page->where('readiness.college_research.ready', true));
    }
}
