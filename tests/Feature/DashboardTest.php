<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard includes real hub stats and nudges payload', function () {
    $user = User::factory()->create([
        'onboarded' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('hub_stats')
            ->where('hub_stats.college_list_schools_count', 0)
            ->where('hub_stats.upcoming_deadlines_60d', 0)
            ->where('hub_stats.mentor_conversations_count', 0)
            ->has('dashboard_nudges'));
});
