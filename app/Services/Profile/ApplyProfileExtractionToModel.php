<?php

namespace App\Services\Profile;

use App\Models\OnboardingProfile;

/**
 * Maps ProfilePasteExtractionAgent structured output onto profile columns (best-effort).
 */
final class ApplyProfileExtractionToModel
{
    public function apply(OnboardingProfile $profile, array $structured): void
    {
        $profile->current_grade = $structured['grade_level_guess'] ?? $profile->current_grade;

        $gpaNum = $structured['gpa_numeric'] ?? null;
        if (is_string($gpaNum) && is_numeric($gpaNum)) {
            $profile->gpa_value = (string) round((float) $gpaNum, 2);
        }
        $gpaScale = $structured['gpa_scale_guess'] ?? null;
        if (is_string($gpaScale) && is_numeric($gpaScale)) {
            $profile->gpa_scale = (string) round((float) $gpaScale, 2);
        }
        $g = $structured['gpa_weighted_guess'] ?? null;
        if ($g === 'weighted' || $g === 'unweighted') {
            $profile->gpa_type = $g;
        }

        $gs = $structured['grading_system_guess'] ?? null;
        if (is_string($gs) && $gs !== '') {
            $profile->curriculum = $gs;
        }

        if (isset($structured['sat_score']) && is_int($structured['sat_score'])) {
            $profile->sat_score = $structured['sat_score'];
        }
        if (isset($structured['act_score']) && is_int($structured['act_score'])) {
            $profile->act_score = $structured['act_score'];
        }

        $t = $structured['test_optional_guess'] ?? 'unknown';
        $map = [
            'yes_have_scores' => 'yes_have_scores',
            'test_optional' => 'test_optional',
            'not_decided' => 'not_decided',
            'unknown' => null,
        ];
        $profile->test_optional_status = $map[$t] ?? null;

        if (isset($structured['intended_majors']) && is_array($structured['intended_majors'])) {
            $majors = array_values(array_filter($structured['intended_majors'], fn ($m) => is_string($m) && $m !== ''));
            $profile->intended_majors_json = array_slice($majors, 0, 8);
        }

        if (isset($structured['dream_schools_guess']) && is_array($structured['dream_schools_guess'])) {
            $schools = array_values(array_filter($structured['dream_schools_guess'], fn ($s) => is_string($s) && $s !== ''));
            $profile->dream_schools_json = array_slice($schools, 0, 5);
        }

        if (isset($structured['region_preferences_guess']) && is_array($structured['region_preferences_guess'])) {
            $regions = array_values(array_filter($structured['region_preferences_guess'], fn ($r) => is_string($r) && $r !== ''));
            $profile->region_preferences_json = array_slice($regions, 0, 8);
        }

        $campus = $structured['campus_size_pref_guess'] ?? 'unknown';
        if (in_array($campus, ['small', 'medium', 'large'], true)) {
            $profile->campus_size_pref = $campus;
        }

        $aid = $structured['financial_aid_need_percent_guess'] ?? null;
        if (is_int($aid)) {
            $profile->financial_aid_need_percent = max(0, min(100, $aid));
        }

        if (isset($structured['activities']) && is_array($structured['activities'])) {
            $acts = array_values(array_filter($structured['activities'], fn ($a) => is_string($a) && $a !== ''));
            $profile->activities_extracted_json = array_map(fn ($a) => ['label' => $a], array_slice($acts, 0, 24));
        }

        if (isset($structured['awards']) && is_array($structured['awards'])) {
            $aw = array_values(array_filter($structured['awards'], fn ($a) => is_string($a) && $a !== ''));
            $profile->awards_extracted_json = array_map(fn ($a) => ['label' => $a], array_slice($aw, 0, 24));
        }

        $profile->student_summary = $structured['goals_summary'] ?? $profile->student_summary;

        $c = $structured['citizenship_guess'] ?? 'unknown';
        if (in_array($c, ['us_citizen', 'permanent_resident', 'international'], true)) {
            $profile->citizenship_residency = $c;
        }

        $profile->location_country = $structured['location_country_guess'] ?? $profile->location_country;
        $profile->location_region = $structured['location_region_guess'] ?? $profile->location_region;

        $trend = $structured['grade_trend_guess'] ?? 'unknown';
        if (in_array($trend, ['up', 'flat', 'down', 'unknown'], true)) {
            $profile->grade_trend = $trend === 'unknown' ? null : $trend;
        }

        $profile->spike_summary = $structured['spike_hypothesis'] ?? $profile->spike_summary;

        $profile->extraction_draft_json = [
            'structured' => $structured,
            'field_confidence' => is_array($structured['field_confidence'] ?? null)
                ? $structured['field_confidence']
                : [],
        ];

        $modes = is_array($profile->profile_modes_json) ? $profile->profile_modes_json : [];
        $modes['early_student'] = (bool) ($structured['early_student'] ?? false);
        $modes['international'] = (bool) ($structured['international_candidate'] ?? false);
        $profile->profile_modes_json = $modes;
    }
}
