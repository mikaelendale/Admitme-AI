<?php

namespace App\Support;

final class BillingPlanCatalog
{
    /**
     * @return list<string>
     */
    public static function allowedSwapPriceIds(): array
    {
        $keys = config('billing.swappable_plan_keys', []);
        $prices = config('billing.prices', []);
        $ids = [];
        foreach ($keys as $key) {
            $id = $prices[$key] ?? null;
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function tierForPriceId(?string $currentStripePriceId): ?int
    {
        if ($currentStripePriceId === null || $currentStripePriceId === '') {
            return null;
        }

        $prices = config('billing.prices', []);
        $catalog = config('billing.plan_catalog', []);

        foreach (config('billing.swappable_plan_keys', []) as $key) {
            $id = $prices[$key] ?? null;
            if (is_string($id) && $id !== '' && $id === $currentStripePriceId) {
                return (int) (($catalog[$key]['tier'] ?? 0));
            }
        }

        return null;
    }

    /**
     * When the subscription's Stripe price id does not match .env (rotated price, wrong env),
     * infer Pro monthly vs annual from Stripe recurring interval so upgrade CTAs still work.
     *
     * @param  string|null  $stripeRecurringInterval  e.g. "month", "year" from Stripe Price
     */
    public static function resolvedUserTier(
        ?string $currentStripePriceId,
        ?string $stripeRecurringInterval,
    ): ?int {
        $fromId = self::tierForPriceId($currentStripePriceId);
        if ($fromId !== null) {
            return $fromId;
        }

        return match ($stripeRecurringInterval) {
            'month' => (int) (config('billing.plan_catalog.pro_monthly.tier', 10)),
            'year' => (int) (config('billing.plan_catalog.pro_annual.tier', 20)),
            default => null,
        };
    }

    /**
     * @return list<array{
     *     key: string,
     *     price_id: string|null,
     *     price_configured: bool,
     *     headline: string,
     *     price_line: string,
     *     summary: string,
     *     badge: string|null,
     *     tier: int,
     *     is_current: bool
     * }>
     */
    public static function plansForSettings(
        ?string $currentStripePriceId,
        ?string $stripeRecurringInterval = null,
    ): array {
        $catalog = config('billing.plan_catalog', []);
        $prices = config('billing.prices', []);
        $out = [];

        $userTier = self::resolvedUserTier($currentStripePriceId, $stripeRecurringInterval);

        foreach (config('billing.swappable_plan_keys', []) as $key) {
            $raw = $prices[$key] ?? null;
            $configured = is_string($raw) && $raw !== '';
            $priceId = $configured ? $raw : null;

            $meta = $catalog[$key] ?? [];
            $planTier = (int) ($meta['tier'] ?? 0);

            $priceMatch = $configured
                && $currentStripePriceId !== null
                && $currentStripePriceId !== ''
                && $currentStripePriceId === $priceId;
            $tierMatch = $userTier !== null && $planTier === $userTier && $planTier > 0;

            $out[] = [
                'key' => $key,
                'price_id' => $priceId,
                'price_configured' => $configured,
                'headline' => $meta['headline'] ?? ($configured ? BillingDisplay::labelForPriceId($priceId) : ucfirst(str_replace('_', ' ', $key))),
                'price_line' => $meta['price_line'] ?? '',
                'summary' => $configured
                    ? ($meta['summary'] ?? '')
                    : ($meta['summary_unconfigured'] ?? 'Add the matching Stripe price ID in .env to switch here with proration, or use the billing portal.'),
                'badge' => $meta['badge'] ?? null,
                'tier' => $planTier,
                'is_current' => $priceMatch || $tierMatch,
            ];
        }

        usort($out, fn (array $a, array $b): int => $a['tier'] <=> $b['tier']);

        return $out;
    }
}
