<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.prices.pro_monthly', 'price_test_monthly');
        config()->set('billing.prices.pro_annual', 'price_test_annual');
        config()->set('billing.swappable_plan_keys', ['pro_monthly', 'pro_annual']);
    }

    public function test_billing_page_requires_verified_onboarded_user(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk();
    }

    public function test_billing_page_marks_active_sprint_pass(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'platform_pass_plan' => 'sprint',
            'platform_pass_ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('has_active_sprint_pass', true));
    }

    public function test_billing_page_shows_sprint_display_price_as_29_in_plan_line(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'platform_pass_plan' => 'sprint',
            'platform_pass_ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('planLine', fn (string $line) => str_contains($line, '$29')
                    && str_contains($line, '90-day Admissions Sprint')));
    }

    public function test_billing_page_no_active_sprint_flag_for_trial_pass(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'platform_pass_plan' => 'trial',
            'platform_pass_ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('has_active_sprint_pass', false));
    }

    public function test_billing_portal_redirects_back_without_stripe_customer(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'stripe_id' => null,
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.portal'))
            ->assertRedirect(route('billing.edit'))
            ->assertSessionHasErrors('billing');
    }

    public function test_swap_validates_price_id(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.swap'), ['price_id' => 'price_not_in_catalog'])
            ->assertSessionHasErrors('price_id');
    }

    public function test_swap_requires_active_subscription(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'stripe_id' => 'cus_test',
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.swap'), ['price_id' => 'price_test_monthly'])
            ->assertRedirect(route('billing.edit'))
            ->assertSessionHasErrors('billing');
    }
}
