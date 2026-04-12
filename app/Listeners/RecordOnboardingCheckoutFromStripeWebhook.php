<?php

namespace App\Listeners;

use App\Actions\Billing\ApplyPlatformPassFromCheckoutSession;
use App\Actions\Billing\FinalizeOnboardingCheckoutSession;
use App\Models\OnboardingSession;
use App\Models\User;
use Laravel\Cashier\Events\WebhookReceived;

class RecordOnboardingCheckoutFromStripeWebhook
{
    public function __construct(
        private FinalizeOnboardingCheckoutSession $finalizeOnboardingCheckout,
        private ApplyPlatformPassFromCheckoutSession $applyPlatformPass,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? '') !== 'checkout.session.completed') {
            return;
        }

        $session = $event->payload['data']['object'] ?? null;
        if (! is_array($session)) {
            return;
        }

        $metadata = $session['metadata'] ?? [];
        if (! is_array($metadata)) {
            return;
        }

        $userId = $metadata['user_id'] ?? null;
        if ($userId === null) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        $metaOnboardingSessionId = $metadata['onboarding_session_id'] ?? null;

        $onboardingSession = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->when(
                $metaOnboardingSessionId !== null,
                fn ($q) => $q->where('id', $metaOnboardingSessionId)
            )
            ->latest('id')
            ->first();

        if ($onboardingSession) {
            ($this->finalizeOnboardingCheckout)($user, $onboardingSession, $session);
        }

        ($this->applyPlatformPass)($user, $session);
    }
}
