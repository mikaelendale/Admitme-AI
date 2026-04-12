<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAccessEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.enforcement.enabled', true);
    }

    public function test_dashboard_redirects_to_billing_when_no_subscription_or_pass(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'platform_pass_ends_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('billing.edit', ['access_required' => 1]));
    }

    public function test_dashboard_ok_when_platform_pass_in_future(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'platform_pass_plan' => 'sprint',
            'platform_pass_ends_at' => now()->addDays(7),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_billing_page_reachable_without_pass(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'platform_pass_ends_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk();
    }

    public function test_billing_checkout_validates_plan(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.checkout'), ['plan' => 'invalid'])
            ->assertSessionHasErrors('plan');
    }

    public function test_billing_checkout_rejects_trial_plan(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.checkout'), ['plan' => 'trial'])
            ->assertSessionHasErrors('plan');
    }

    public function test_billing_subscribe_validates_billing_cycle(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.subscribe'), [])
            ->assertSessionHasErrors('billing_cycle');
    }
}
