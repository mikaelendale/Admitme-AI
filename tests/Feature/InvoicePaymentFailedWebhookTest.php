<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SubscriptionPaymentFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

class InvoicePaymentFailedWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_payment_failed_sends_notification_and_records_timestamp(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'stripe_id' => 'cus_webhook_test',
        ]);

        Event::dispatch(new WebhookReceived([
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_webhook_test',
                    'subscription' => 'sub_renewal',
                ],
            ],
        ]));

        Notification::assertSentTo($user, SubscriptionPaymentFailedNotification::class);

        $user->refresh();
        $this->assertNotNull($user->payment_failed_notified_at);
    }

    public function test_invoice_payment_failed_throttled_within_24_hours(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'stripe_id' => 'cus_throttle',
            'payment_failed_notified_at' => now()->subHours(2),
        ]);

        $payload = [
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_throttle',
                    'subscription' => 'sub_x',
                ],
            ],
        ];

        Event::dispatch(new WebhookReceived($payload));
        Notification::assertNothingSent();

        $user->forceFill(['payment_failed_notified_at' => now()->subDays(2)])->save();

        Event::dispatch(new WebhookReceived($payload));

        Notification::assertSentToTimes($user, SubscriptionPaymentFailedNotification::class, 1);
    }

    public function test_non_subscription_invoice_does_not_notify(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'stripe_id' => 'cus_one_off',
        ]);

        Event::dispatch(new WebhookReceived([
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'customer' => 'cus_one_off',
                    'subscription' => null,
                ],
            ],
        ]));

        Notification::assertNothingSent();
        $this->assertNull($user->fresh()->payment_failed_notified_at);
    }
}
