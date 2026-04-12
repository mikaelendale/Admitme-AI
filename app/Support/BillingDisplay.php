<?php

namespace App\Support;

final class BillingDisplay
{
    /**
     * Human label for a Stripe price ID using config/billing.php.
     */
    public static function labelForPriceId(?string $priceId): string
    {
        if ($priceId === null || $priceId === '') {
            return 'Plan';
        }

        $prices = config('billing.prices', []);

        foreach ($prices as $key => $id) {
            if ($id !== null && $id !== '' && $id === $priceId) {
                return match ($key) {
                    'trial' => '$1 Trial',
                    'sprint' => 'Admissions Sprint',
                    'boost' => 'Boost Pack',
                    'pro_monthly' => 'Pro • $15/month',
                    'pro_annual' => 'Pro • $150/year',
                    default => 'Subscription',
                };
            }
        }

        return 'Subscription';
    }

    /**
     * Shorter line for settings summary.
     */
    public static function shortPlanLine(?string $priceId, bool $isAnnual): string
    {
        if ($priceId === null || $priceId === '') {
            return 'No active subscription';
        }

        $prices = config('billing.prices', []);
        if (($prices['pro_annual'] ?? null) === $priceId || (($prices['pro_monthly'] ?? null) === $priceId && $isAnnual)) {
            return 'Pro • $150/year';
        }
        if (($prices['pro_monthly'] ?? null) === $priceId) {
            return 'Pro • $15/month';
        }

        return self::labelForPriceId($priceId);
    }
}
