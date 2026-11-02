<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\SubscriptionPaymentFailedNotification;
use Laravel\Cashier\Events\WebhookReceived;

class NotifyUserOfInvoicePaymentFailure
{
    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? '') !== 'invoice.payment_failed') {
            return;
        }

        $invoice = $event->payload['data']['object'] ?? null;
        if (! is_array($invoice)) {
            return;
        }

        // Since Stripe API `basil` an invoice names its subscription under `parent.subscription_details`. A
        // webhook endpoint created on an older API version still sends the top-level `subscription`.
        $subscription = $invoice['parent']['subscription_details']['subscription'] ?? $invoice['subscription'] ?? null;
        if ($subscription === null || $subscription === '') {
            return;
        }

        $customerId = $invoice['customer'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        $user = User::query()->where('stripe_id', $customerId)->first();
        if ($user === null) {
            return;
        }

        $last = $user->payment_failed_notified_at;
        if ($last !== null && $last->greaterThan(now()->subDay())) {
            return;
        }

        $user->notify(new SubscriptionPaymentFailedNotification);
        $user->forceFill(['payment_failed_notified_at' => now()])->save();
    }
}
