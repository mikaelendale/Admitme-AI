<?php

namespace Tests\Unit;

use App\Models\CollegeListSchool;
use App\Models\User;
use App\Support\DashboardHubInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardHubInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_stats_counts_active_schools_and_future_deadlines_in_window(): void
    {
        $user = User::factory()->create(['onboarded' => true]);

        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'A',
            'school_slug' => 'a',
            'tier' => 'match',
            'fit_score' => 70,
            'why_blurb' => 'x',
            'ea_deadline' => now()->addDays(10),
            'ed_deadline' => null,
            'rd_deadline' => now()->addDays(45),
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Removed',
            'school_slug' => 'removed',
            'tier' => 'safety',
            'fit_score' => 90,
            'why_blurb' => 'x',
            'application_status' => 'not_started',
            'is_removed' => true,
            'generated_at' => now(),
        ]);

        $stats = DashboardHubInsights::hubStats($user);

        $this->assertSame(1, $stats['college_list_schools_count']);
        $this->assertSame(2, $stats['upcoming_deadlines_60d']);
        $this->assertSame(1, $stats['urgent_deadlines_14d']);
        $this->assertSame(0, $stats['mentor_conversations_count']);
        $this->assertSame(['reach' => 0, 'match' => 1, 'safety' => 0], $stats['list_tiers']);
    }
}
