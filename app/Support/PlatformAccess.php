<?php

namespace App\Support;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final class PlatformAccess
{
    public static function enforcementEnabled(): bool
    {
        return (bool) config('billing.enforcement.enabled', true);
    }

    public static function hasFullAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! self::enforcementEnabled()) {
            return true;
        }

        if ($user->subscribed('default')) {
            return true;
        }

        $ends = $user->platform_pass_ends_at;

        return self::activePlatformPass($ends);
    }

    private static function activePlatformPass(mixed $ends): bool
    {
        if (! $ends instanceof DateTimeInterface) {
            return false;
        }

        return Carbon::parse($ends)->isFuture();
    }

    /**
     * Whole days left until pass expiry: floor of (end − now) in seconds / 86400, in app timezone.
     * Avoids Carbon diffInDays rounding quirks; matches "N day(s) remaining" with wall-clock elapsed time.
     */
    public static function passFullDaysRemaining(DateTimeInterface $endsAt): int
    {
        $tz = (string) config('app.timezone', 'UTC');
        $end = Carbon::parse($endsAt)->timezone($tz);
        $start = now()->timezone($tz);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        return (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 86400);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function sharePayload(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $enforcement = self::enforcementEnabled();
        $subscribed = $user->subscribed('default');
        $ends = $user->platform_pass_ends_at;
        $passActive = self::activePlatformPass($ends);

        $hasAccess = ! $enforcement || $subscribed || $passActive;

        $source = 'none';
        if ($subscribed) {
            $source = 'subscription';
        } elseif ($passActive) {
            $source = 'pass';
        }

        $daysRemaining = null;
        if ($passActive && $ends instanceof DateTimeInterface) {
            $daysRemaining = self::passFullDaysRemaining($ends);
        }

        $plan = $user->platform_pass_plan;
        $display = is_string($plan) ? config("billing.pass_display.{$plan}") : null;

        return [
            'has_access' => $hasAccess,
            'source' => $source,
            'pass_plan' => is_string($plan) ? $plan : null,
            'ends_at' => $ends instanceof DateTimeInterface
                ? Carbon::parse($ends)->toIso8601String()
                : null,
            'days_remaining' => $daysRemaining,
            'enforcement_enabled' => $enforcement,
            'display' => is_array($display) ? $display : null,
        ];
    }
}
