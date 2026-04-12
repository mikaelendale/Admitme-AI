<?php

namespace App\Support;

/**
 * Human-readable label for the undergraduate application cycle counselors
 * usually mean when discussing “this year’s” deadlines (not prior admission years).
 */
final class AdmissionsCycle
{
    public static function primaryApplyingLabel(): string
    {
        $custom = config('admissions.applying_cycle_label');
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        $y = (int) now()->year;

        return $y.'–'.substr((string) ($y + 1), -2);
    }
}
