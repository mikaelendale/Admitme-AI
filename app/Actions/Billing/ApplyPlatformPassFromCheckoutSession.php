<?php

namespace App\Actions\Billing;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;

final class ApplyPlatformPassFromCheckoutSession
{
    /**
     * Extend platform pass from a paid one-time Checkout session (trial or sprint price only).
     * Each Stripe Checkout session is applied at most once (webhook + return URL safe).
     *
     * @param  array<string, mixed>  $sessionData  Stripe Checkout Session as array
     */
    public function __invoke(User $user, array $sessionData): bool
    {
        if (($sessionData['mode'] ?? '') !== 'payment') {
            return false;
        }

        $paid = in_array($sessionData['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
        if (! $paid) {
            return false;
        }

        $metadata = $sessionData['metadata'] ?? [];
        if (! is_array($metadata)) {
            return false;
        }

        $metaUserId = $metadata['user_id'] ?? null;
        if ($metaUserId === null || (string) $metaUserId !== (string) $user->id) {
            return false;
        }

        $sessionId = isset($sessionData['id']) && is_string($sessionData['id']) ? $sessionData['id'] : '';
        if ($sessionId === '') {
            return false;
        }

        $sessionData = $this->ensureLineItems($sessionData);

        $priceId = $this->firstLineItemPriceId($sessionData);
        if ($priceId === null || $priceId === '') {
            return false;
        }

        $prices = config('billing.prices', []);
        $trialPrice = is_string($prices['trial'] ?? null) ? $prices['trial'] : null;
        $sprintPrice = is_string($prices['sprint'] ?? null) ? $prices['sprint'] : null;

        $planKey = match ($priceId) {
            $trialPrice => 'trial',
            $sprintPrice => 'sprint',
            default => null,
        };

        if ($planKey === null) {
            return false;
        }

        $days = (int) config("billing.purchase_access_days.{$planKey}", 0);
        if ($days <= 0) {
            return false;
        }

        return DB::transaction(function () use ($user, $sessionId, $planKey, $days): bool {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $applied = $locked->platform_pass_applied_checkout_session_ids ?? [];
            if (! is_array($applied)) {
                $applied = [];
            }

            if (in_array($sessionId, $applied, true)) {
                return false;
            }

            $base = now();
            $currentEnd = $locked->platform_pass_ends_at;
            if ($currentEnd !== null && Carbon::parse($currentEnd)->isFuture()) {
                $base = Carbon::parse($currentEnd)->copy();
            }

            $applied[] = $sessionId;
            if (count($applied) > 100) {
                $applied = array_slice($applied, -100);
            }

            $locked->forceFill([
                'platform_pass_plan' => $planKey,
                'platform_pass_ends_at' => $base->copy()->addDays($days),
                'platform_pass_applied_checkout_session_ids' => array_values(array_unique($applied)),
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $sessionData
     * @return array<string, mixed>
     */
    private function ensureLineItems(array $sessionData): array
    {
        if ($this->firstLineItemPriceId($sessionData) !== null) {
            return $sessionData;
        }

        $sessionId = $sessionData['id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            return $sessionData;
        }

        try {
            $full = Cashier::stripe()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['line_items.data.price'],
            ]);

            return $full->toArray();
        } catch (\Throwable) {
            return $sessionData;
        }
    }

    /**
     * @param  array<string, mixed>  $sessionData
     */
    private function firstLineItemPriceId(array $sessionData): ?string
    {
        $lineItems = $sessionData['line_items'] ?? null;
        if (! is_array($lineItems)) {
            return null;
        }

        $data = $lineItems['data'] ?? null;
        if (! is_array($data) || $data === []) {
            return null;
        }

        $first = $data[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        $price = $first['price'] ?? null;
        if (is_string($price) && $price !== '') {
            return $price;
        }

        if (is_array($price) && isset($price['id']) && is_string($price['id'])) {
            return $price['id'];
        }

        return null;
    }
}
