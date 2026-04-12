<?php

namespace Tests\Feature;

use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_profile_overview(): void
    {
        $this->get(route('profile-overview'))->assertRedirect();
    }

    public function test_onboarded_user_can_view_profile_overview(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('profile-overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('profile-overview')
                ->where('has_profile', true)
                ->has('resume_pdf_import'));
    }

    public function test_profile_payload_includes_onboarding_row_when_present(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        OnboardingProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'current_grade' => '11',
            'graduation_year' => 2027,
            'profile_completeness_score' => 72,
            'student_summary' => 'Test summary line.',
            'intended_majors_json' => ['Computer Science'],
            'target_countries_json' => ['United States'],
            'ai_context' => [
                'headline' => 'Strong STEM narrative',
                'radar' => [
                    'rigor' => [
                        'label' => 'Academic rigor',
                        'score' => 78,
                        'verdict' => 'Solid course load.',
                    ],
                ],
                'signals' => ['Research interest'],
                'takeaways' => ['Lead with one flagship project.'],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('profile-overview'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('profile-overview')
                ->where('has_profile', true)
                ->has('profile.demographics')
                ->has('profile.academics')
                ->where('profile.completeness_score', 72)
                ->where('profile.student_summary', 'Test summary line.')
                ->where('profile.ai.headline', 'Strong STEM narrative'));
    }

    public function test_not_onboarded_user_cannot_view_profile_overview(): void
    {
        $user = User::factory()->create([
            'onboarded' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('profile-overview'))
            ->assertRedirect();
    }
}
