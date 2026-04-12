<?php

namespace App\Services\Profile;

use App\Models\OnboardingProfile;

final class ProfileModesResolver
{
    /**
     * @return array<string, bool>
     */
    public function resolve(OnboardingProfile $profile): array
    {
        $grade = strtolower((string) ($profile->current_grade ?? ''));
        $early = str_contains($grade, '9') || str_contains($grade, '10')
            || str_contains($grade, '9th') || str_contains($grade, '10th');

        $cit = strtolower((string) ($profile->citizenship_residency ?? ''));
        $international = $cit === 'international';

        $aid = (int) ($profile->financial_aid_need_percent ?? 0);
        $highNeedAid = $aid >= 70;

        $noTest = ($profile->test_optional_status === 'test_optional')
            && ($profile->sat_score === null || $profile->sat_score === 0)
            && ($profile->act_score === null || $profile->act_score === 0);

        $completeness = (int) $profile->profile_completeness_score;
        $lowCompleteness = $completeness < 40;

        $spike = $profile->spike_summary !== null && trim((string) $profile->spike_summary) !== '';

        return [
            'early_student' => $early,
            'international' => $international,
            'high_need_aid' => $highNeedAid,
            'no_test_score' => $noTest,
            'low_completeness' => $lowCompleteness,
            'spike_detected' => $spike,
        ];
    }
}
