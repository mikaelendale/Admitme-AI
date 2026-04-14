<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddOnsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_ons_page_requires_verified_onboarded_user(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('addons'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/add-ons')
                ->has('boost')
                ->where('trial_only_mode', false));
    }

    public function test_boost_checkout_rejects_unconfigured_price(): void
    {
        config()->set('billing.prices.boost', '');

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('addons'))
            ->post(route('billing.checkout'), ['plan' => 'boost'])
            ->assertRedirect(route('addons'))
            ->assertSessionHasErrors('billing');
    }

    public function test_checkout_validates_plan(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('addons'))
            ->post(route('billing.checkout'), ['plan' => 'invalid'])
            ->assertSessionHasErrors('plan');
    }

    public function test_boost_checkout_blocked_in_trial_only_mode_even_when_configured(): void
    {
        config()->set('billing.trial_only_mode', true);
        config()->set('billing.prices.boost', 'price_boost_test');

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('addons'))
            ->post(route('billing.checkout'), ['plan' => 'boost'])
            ->assertRedirect(route('addons'))
            ->assertSessionHasErrors('billing');
    }
}
