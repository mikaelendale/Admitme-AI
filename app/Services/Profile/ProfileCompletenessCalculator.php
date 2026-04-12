<?php

namespace App\Services\Profile;

use App\Models\OnboardingProfile;

final class ProfileCompletenessCalculator
{
    /**
     * @return array{score: int, callouts: list<string>}
     */
    public function calculate(OnboardingProfile $profile): array
    {
        $score = 0;
        $callouts = [];

        if ($profile->gpa_value !== null && (float) $profile->gpa_value > 0) {
            $score += 15;
        } else {
            $callouts[] = 'Add your GPA (weighted or unweighted).';
        }

        $hasTest = ($profile->sat_score !== null && $profile->sat_score > 0)
            || ($profile->act_score !== null && $profile->act_score > 0);
        if ($hasTest || $profile->test_optional_status !== null) {
            $score += 15;
        } else {
            $callouts[] = 'Add SAT/ACT scores or set test-optional status.';
        }

        $activities = is_array($profile->activities_extracted_json) ? $profile->activities_extracted_json : [];
        if (count($activities) >= 3) {
            $score += 20;
        } else {
            $callouts[] = 'List at least three activities or roles.';
        }

        $awards = is_array($profile->awards_extracted_json) ? $profile->awards_extracted_json : [];
        if (count($awards) >= 1) {
            $score += 10;
        } else {
            $callouts[] = 'Add an honor, award, or competition.';
        }

        $majors = is_array($profile->intended_majors_json) ? $profile->intended_majors_json : [];
        $dreams = is_array($profile->dream_schools_json) ? $profile->dream_schools_json : [];
        $regions = is_array($profile->region_preferences_json) ? $profile->region_preferences_json : [];
        $hasGoals = count(array_filter($majors, fn ($m) => is_string($m) && $m !== '')) > 0
            || count(array_filter($dreams, fn ($d) => is_string($d) && $d !== '')) > 0
            || count(array_filter($regions, fn ($r) => is_string($r) && $r !== '')) > 0;
        if ($hasGoals) {
            $score += 20;
        } else {
            $callouts[] = 'Set intended major, dream schools, or region preferences.';
        }

        $dump = $profile->profile_dump_text ?? '';
        $summary = $profile->student_summary ?? '';
        if (strlen(trim($dump)) > 80 || strlen(trim($summary)) > 20) {
            $score += 10;
        } else {
            $callouts[] = 'Add a bit more context (background, goals, or constraints).';
        }

        if ($profile->current_grade !== null && $profile->current_grade !== '') {
            $score += 10;
        } else {
            $callouts[] = 'Confirm your grade level.';
        }

        $score = (int) min(100, max(0, $score));

        return ['score' => $score, 'callouts' => array_values(array_unique(array_slice($callouts, 0, 4)))];
    }
}
