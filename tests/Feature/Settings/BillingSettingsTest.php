<?php

namespace Tests\Feature\Settings;

use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_trial_only_mode_empties_pro_plans_for_non_subscribers(): void
    {
        config()->set('billing.trial_only_mode', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('trial_only_mode', true)
                ->where('plans', [])
                ->where('sprint_checkout_configured', false)
                ->where('planLine', 'No paid plan — new checkouts paused')
                ->where('billing_skipped_onboarding', false));
    }

    public function test_trial_only_mode_shows_billing_skipped_when_onboarding_skip_recorded(): void
    {
        config()->set('billing.trial_only_mode', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $session = OnboardingSession::query()->create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'status' => 'completed',
            'started_step_key' => '1',
            'last_step_key' => '5',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        OnboardingResponse::query()->create([
            'user_id' => $user->id,
            'onboarding_session_id' => $session->id,
            'step_key' => 'plans',
            'question_key' => 'billing.skipped',
            'source' => 'form',
            'answer_text' => '1',
            'answer_json' => ['skipped' => true],
        ]);

        $this->actingAs($user)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing_skipped_onboarding', true)
                ->where('planLine', 'No paid plan — new checkouts paused'));
    }

    public function test_trial_only_mode_blocks_new_subscription_checkout(): void
    {
        config()->set('billing.trial_only_mode', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.subscribe'), ['billing_cycle' => 'monthly'])
            ->assertRedirect(route('billing.edit'))
            ->assertSessionHasErrors('billing');
    }

    public function test_trial_only_mode_blocks_sprint_checkout(): void
    {
        config()->set('billing.trial_only_mode', true);
        config()->set('billing.prices.sprint', 'price_sprint_test');

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('billing.edit'))
            ->post(route('billing.checkout'), ['plan' => 'sprint'])
            ->assertRedirect(route('billing.edit'))
            ->assertSessionHasErrors('billing');
    }

    public function test_onboarding_checkout_rejects_sprint_in_trial_only_mode(): void
    {
        config()->set('billing.trial_only_mode', true);

        $user = User::factory()->create([
            'onboarded' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('onboarding'))
            ->post(route('onboarding.checkout'), [
                'plan' => 'sprint',
                'billing_cycle' => 'monthly',
            ])
            ->assertSessionHasErrors('plan');
    }
}
