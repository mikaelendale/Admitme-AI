<?php

namespace Tests\Unit;

use App\Actions\Billing\ApplyPlatformPassFromCheckoutSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ApplyPlatformPassFromCheckoutSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_applies_trial_pass_from_line_item_price(): void
    {
        config()->set('billing.prices.trial', 'price_trial_x');
        config()->set('billing.prices.sprint', 'price_sprint_x');
        config()->set('billing.purchase_access_days.trial', 3);

        $user = User::factory()->create();

        $action = new ApplyPlatformPassFromCheckoutSession;

        $session = [
            'id' => 'cs_test_1',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan' => 'trial',
            ],
            'line_items' => [
                'data' => [
                    [
                        'price' => 'price_trial_x',
                    ],
                ],
            ],
        ];

        $this->assertTrue($action($user, $session));

        $user->refresh();
        $this->assertSame('trial', $user->platform_pass_plan);
        $this->assertNotNull($user->platform_pass_ends_at);
        $this->assertTrue($user->platform_pass_ends_at->isFuture());
    }

    public function test_does_not_apply_for_boost_price_id(): void
    {
        config()->set('billing.prices.trial', 'price_trial_x');
        config()->set('billing.prices.sprint', 'price_sprint_x');
        config()->set('billing.prices.boost', 'price_boost_x');

        $user = User::factory()->create();

        $action = new ApplyPlatformPassFromCheckoutSession;

        $session = [
            'id' => 'cs_test_2',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
            'line_items' => [
                'data' => [
                    [
                        'price' => 'price_boost_x',
                    ],
                ],
            ],
        ];

        $this->assertFalse($action($user, $session));

        $user->refresh();
        $this->assertNull($user->platform_pass_ends_at);
    }

    public function test_same_checkout_session_is_idempotent(): void
    {
        config()->set('billing.prices.trial', 'price_trial_x');
        config()->set('billing.prices.sprint', 'price_sprint_x');
        config()->set('billing.purchase_access_days.trial', 3);

        $user = User::factory()->create();
        $action = new ApplyPlatformPassFromCheckoutSession;

        $session = [
            'id' => 'cs_test_dup',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
            'line_items' => [
                'data' => [
                    ['price' => 'price_trial_x'],
                ],
            ],
        ];

        $this->assertTrue($action($user, $session));
        $user->refresh();
        $firstEnd = $user->platform_pass_ends_at?->copy();

        $this->assertFalse($action($user, $session));
        $user->refresh();
        $this->assertTrue($firstEnd?->equalTo($user->platform_pass_ends_at));
        $this->assertSame(['cs_test_dup'], $user->platform_pass_applied_checkout_session_ids);
    }

    public function test_distinct_sessions_stack_sprint_days(): void
    {
        $this->travelTo(Carbon::parse('2025-01-10 12:00:00', 'UTC'));
        config()->set('app.timezone', 'UTC');
        config()->set('billing.prices.trial', 'price_trial_x');
        config()->set('billing.prices.sprint', 'price_sprint_x');
        config()->set('billing.purchase_access_days.sprint', 90);

        $user = User::factory()->create();
        $action = new ApplyPlatformPassFromCheckoutSession;

        $sessionA = [
            'id' => 'cs_stack_a',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'metadata' => ['user_id' => (string) $user->id],
            'line_items' => [
                'data' => [['price' => 'price_sprint_x']],
            ],
        ];
        $sessionB = [
            'id' => 'cs_stack_b',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'metadata' => ['user_id' => (string) $user->id],
            'line_items' => [
                'data' => [['price' => 'price_sprint_x']],
            ],
        ];

        $this->assertTrue($action($user, $sessionA));
        $user->refresh();
        $afterFirst = $user->platform_pass_ends_at;

        $this->assertTrue($action($user, $sessionB));
        $user->refresh();
        $afterSecond = $user->platform_pass_ends_at;

        $this->assertNotNull($afterFirst);
        $this->assertNotNull($afterSecond);
        $this->assertTrue($afterSecond->greaterThan($afterFirst));
        $this->assertSame(180, (int) Carbon::parse('2025-01-10 12:00:00')->diffInDays($afterSecond, true));
    }
}
