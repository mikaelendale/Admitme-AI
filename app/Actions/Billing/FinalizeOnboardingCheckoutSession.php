<?php

namespace App\Actions\Billing;

use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use App\Models\User;

final class FinalizeOnboardingCheckoutSession
{
    /**
     * Persist onboarding billing verification when Stripe Checkout succeeded.
     * Does not advance onboarding step — the user taps Continue on step 4.
     *
     * @param  array<string, mixed>  $sessionData
     *                                             Stripe Checkout Session as array (API object or webhook payload).
     */
    public function __invoke(User $user, OnboardingSession $onboardingSession, array $sessionData): bool
    {
        $metadata = $sessionData['metadata'] ?? [];
        if (! is_array($metadata)) {
            return false;
        }

        $metaUserId = $metadata['user_id'] ?? null;
        if ($metaUserId === null || (string) $metaUserId !== (string) $user->id) {
            return false;
        }

        $metaSessionId = $metadata['onboarding_session_id'] ?? null;
        if ($metaSessionId !== null && (string) $metaSessionId !== (string) $onboardingSession->id) {
            return false;
        }

        $customer = $sessionData['customer'] ?? null;
        $customerId = is_string($customer) ? $customer : (is_array($customer) ? ($customer['id'] ?? null) : null);

        if ($user->stripe_id && $customerId && $customerId !== $user->stripe_id) {
            return false;
        }

        $paid = in_array($sessionData['payment_status'] ?? '', ['paid', 'no_payment_required'], true)
            || (($sessionData['status'] ?? '') === 'complete' && ($sessionData['mode'] ?? '') === 'subscription');

        if (! $paid) {
            return false;
        }

        $sessionId = $sessionData['id'] ?? '';
        if ($sessionId === '') {
            return false;
        }

        $plan = isset($metadata['plan']) && is_string($metadata['plan']) ? $metadata['plan'] : null;
        $billingCycle = isset($metadata['billing_cycle']) && is_string($metadata['billing_cycle'])
            ? $metadata['billing_cycle']
            : 'monthly';

        OnboardingResponse::updateOrCreate(
            [
                'user_id' => $user->id,
                'onboarding_session_id' => $onboardingSession->id,
                'question_key' => 'billing.checkout_verified',
            ],
            [
                'step_key' => 'plans',
                'source' => 'stripe',
                'answer_text' => (string) $sessionId,
                'answer_json' => [
                    'stripe_checkout_session_id' => (string) $sessionId,
                    'payment_status' => $sessionData['payment_status'] ?? null,
                    'mode' => $sessionData['mode'] ?? null,
                    'plan' => $plan,
                    'billing_cycle' => $billingCycle,
                ],
            ]
        );

        return true;
    }
}
