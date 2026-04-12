<?php

namespace App\Services\CollegeListCouncil;

use Illuminate\Support\Facades\Log;

/**
 * Structured council pipeline logging (milestones + warnings/errors on the same optional channel).
 */
final class CollegeListCouncilRunLog
{
    public static function info(string $message, array $context = []): void
    {
        if (! config('college_list_council.milestone_logging', true)) {
            return;
        }

        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /**
     * @param  'info'|'warning'|'error'  $level
     */
    private static function write(string $level, string $message, array $context): void
    {
        $payload = array_merge(['council_run' => true], $context);
        $channel = config('college_list_council.milestone_log_channel');

        if (is_string($channel) && $channel !== '') {
            Log::channel($channel)->{$level}($message, $payload);

            return;
        }

        Log::{$level}($message, $payload);
    }
}
