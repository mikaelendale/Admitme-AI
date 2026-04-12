<?php

namespace Tests\Feature;

use App\Models\CollegeListSchool;
use App\Models\GapFixAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class GapFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_gap_fix(): void
    {
        $this->get(route('gap-fix'))->assertRedirect();
    }

    public function test_not_onboarded_user_cannot_view_gap_fix(): void
    {
        $user = User::factory()->create([
            'onboarded' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)->get(route('gap-fix'))->assertRedirect();
    }

    public function test_onboarded_user_can_view_gap_fix_page(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('gap-fix'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('gap-fix')
                ->has('college_schools')
                ->has('latest_analysis')
                ->has('profile_baseline'));
    }

    public function test_generate_persists_analysis_with_fake_ai(): void
    {
        Config::set('gap_fix.fake', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Test University',
            'school_slug' => 'test-university',
            'tier' => 'match',
            'fit_score' => 72,
            'why_blurb' => 'Strong STEM and location fit.',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('gap-fix.generate'), [
                'college_list_school_ids' => [(string) $school->id],
            ])
            ->assertCreated()
            ->assertJsonPath('analysis.is_partial', false)
            ->assertJsonStructure([
                'analysis' => [
                    'id',
                    'selected_school_ids',
                    'payload',
                    'is_partial',
                    'missing_school_ids',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseCount('gap_fix_analyses', 1);
        $row = GapFixAnalysis::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->user_id);
        $this->assertFalse($row->is_partial);
    }

    public function test_generate_returns_422_for_invalid_school_id(): void
    {
        Config::set('gap_fix.fake', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('gap-fix.generate'), [
                'college_list_school_ids' => ['not-a-real-id'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['college_list_school_ids.0']);
    }

    public function test_generate_returns_429_when_rate_limited(): void
    {
        Config::set('gap_fix.fake', true);
        Config::set('gap_fix.generate_per_user_per_hour', 2);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Other U',
            'school_slug' => 'other-u',
            'tier' => 'safety',
            'fit_score' => 80,
            'why_blurb' => 'Good option.',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $sid = (string) $school->id;

        $this->actingAs($user)
            ->postJson(route('gap-fix.generate'), ['college_list_school_ids' => [$sid]])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson(route('gap-fix.generate'), ['college_list_school_ids' => [$sid]])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson(route('gap-fix.generate'), ['college_list_school_ids' => [$sid]])
            ->assertStatus(429);

        $this->travel(61)->minutes();

        $this->actingAs($user)
            ->postJson(route('gap-fix.generate'), ['college_list_school_ids' => [$sid]])
            ->assertCreated();
    }

    public function test_what_if_explain_returns_markdown_when_fake(): void
    {
        Config::set('gap_fix.fake', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Mid U',
            'school_slug' => 'mid-u',
            'tier' => 'match',
            'fit_score' => 60,
            'why_blurb' => 'Balanced.',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('gap-fix.what-if-explain'), [
                'college_list_school_ids' => [(string) $school->id],
                'sat_delta' => 50,
                'gpa_delta' => 0.05,
            ])
            ->assertOk()
            ->assertJsonStructure(['narrative']);
    }
}
