<?php

namespace Tests\Feature;

use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CollegeListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_college_lists(): void
    {
        $this->get(route('college-lists'))->assertRedirect();
    }

    public function test_onboarded_user_can_view_college_lists(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('college-lists'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('college-lists')
                ->where('colleges', [])
                ->where('councilRunId', null));
    }

    public function test_college_lists_includes_saved_rows_for_user(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Test University',
            'school_slug' => 'test-university',
            'tier' => 'match',
            'fit_score' => 70,
            'why_blurb' => 'Test row.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('college-lists'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('college-lists')
                ->has('colleges', 1)
                ->where('colleges.0.name', 'Test University')
                ->where('colleges.0.tier', 'match'));
    }

    public function test_college_lists_shows_council_school_rows_when_present(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Other School',
            'school_slug' => 'other-school',
            'tier' => 'safety',
            'fit_score' => 90,
            'why_blurb' => 'Lower priority.',
            'is_removed' => true,
            'generated_at' => now(),
        ]);

        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Council Pipeline College',
            'school_slug' => 'council-pipeline-college',
            'tier' => 'match',
            'fit_score' => 75,
            'why_blurb' => 'Saved by the council assembly step.',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('college-lists'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('college-lists')
                ->has('colleges', 1)
                ->where('colleges.0.name', 'Council Pipeline College')
                ->where('colleges.0.tier', 'match'));
    }

    public function test_not_onboarded_user_cannot_view_college_lists(): void
    {
        $user = User::factory()->create([
            'onboarded' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('college-lists'))
            ->assertRedirect();
    }

    public function test_college_lists_passes_council_run_query_param_when_council_enabled(): void
    {
        Config::set('college_list_council.enabled', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('college-lists', ['run' => '01hqxyzexample123456789012']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('college-lists')
                ->where('councilRunId', '01hqxyzexample123456789012'));
    }

    public function test_owner_can_view_college_list_school_detail_page(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Detail Test U',
            'school_slug' => 'detail-test-u',
            'tier' => 'match',
            'fit_score' => 72,
            'why_blurb' => 'Great fit for CS.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('college-lists.school', ['collegeListSchool' => $school->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('college-lists/school')
                ->where('school.name', 'Detail Test U')
                ->where('school.tier', 'match')
                ->where('school.whyFit', 'Great fit for CS.'));
    }

    public function test_other_user_cannot_view_foreign_college_list_school(): void
    {
        $owner = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);
        $intruder = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $owner->id,
            'school_name' => 'Private U',
            'school_slug' => 'private-u',
            'tier' => 'safety',
            'fit_score' => 80,
            'why_blurb' => '—',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($intruder)
            ->get(route('college-lists.school', ['collegeListSchool' => $school->id]))
            ->assertNotFound();
    }

    public function test_removed_school_detail_returns_not_found_for_owner(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Removed U',
            'school_slug' => 'removed-u',
            'tier' => 'reach',
            'fit_score' => 60,
            'why_blurb' => '—',
            'application_status' => 'not_started',
            'is_removed' => true,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('college-lists.school', ['collegeListSchool' => $school->id]))
            ->assertNotFound();
    }
}
