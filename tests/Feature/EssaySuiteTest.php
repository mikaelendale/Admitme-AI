<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EssaySuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_essay_suite(): void
    {
        $this->get(route('essay-suite'))->assertRedirect();
    }

    public function test_onboarded_user_can_view_essay_suite(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('essay-suite'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('essay-suite')
                ->has('essay_schools')
                ->has('essay_active_schools')
                ->has('essay_recent_runs')
                ->has('essay_why_school_drafts')
                ->has('supplements_nav_badge_count')
                ->has('essay_defaults')
                ->where('essay_defaults.mentor_tone', 'balanced'));
    }

    public function test_not_onboarded_user_cannot_view_essay_suite(): void
    {
        $user = User::factory()->create([
            'onboarded' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('essay-suite'))
            ->assertRedirect();
    }
}
