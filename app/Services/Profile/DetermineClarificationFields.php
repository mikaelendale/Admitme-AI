<?php

namespace App\Services\Profile;

use App\Models\OnboardingProfile;

/**
 * Builds a minimal list of clarification fields (max 6) from extraction confidence + DB.
 *
 * @return list<array{key: string, type: string, label: string, options?: list<string>}>
 */
final class DetermineClarificationFields
{
    public function forProfile(OnboardingProfile $profile): array
    {
        $extraction = is_array($profile->extraction_draft_json) ? $profile->extraction_draft_json : [];
        $confidence = [];
        if (isset($extraction['field_confidence']) && is_array($extraction['field_confidence'])) {
            $confidence = $extraction['field_confidence'];
        }

        $out = [];

        $need = function (string $key) use ($confidence): bool {
            $c = $confidence[$key] ?? 'low';

            return in_array($c, ['low', 'missing', 'ambiguous'], true);
        };

        if ($need('grade_level') || $profile->current_grade === null) {
            $out[] = [
                'key' => 'current_grade',
                'type' => 'select',
                'label' => 'Grade / year',
                'options' => ['9th', '10th', '11th', '12th', 'Gap year'],
            ];
        }

        if ($need('gpa') || $profile->gpa_value === null) {
            $out[] = [
                'key' => 'gpa_value',
                'type' => 'number',
                'label' => 'GPA',
            ];
            $out[] = [
                'key' => 'gpa_scale',
                'type' => 'number',
                'label' => 'GPA scale (e.g. 4.0)',
            ];
            $out[] = [
                'key' => 'gpa_type',
                'type' => 'toggle',
                'label' => 'Weighted or unweighted',
                'options' => ['weighted', 'unweighted'],
            ];
        }

        if ($need('curriculum') || $profile->curriculum === null) {
            $out[] = [
                'key' => 'curriculum',
                'type' => 'select',
                'label' => 'Grading system',
                'options' => ['US 4.0', 'IB', 'A-Levels', 'CBSE', 'ICSE', 'Other'],
            ];
        }

        if ($need('sat_act') && $profile->sat_score === null && $profile->act_score === null) {
            $out[] = [
                'key' => 'sat_score',
                'type' => 'number',
                'label' => 'SAT (optional)',
            ];
            $out[] = [
                'key' => 'act_score',
                'type' => 'number',
                'label' => 'ACT (optional)',
            ];
        }

        if ($need('test_optional')) {
            $out[] = [
                'key' => 'test_optional_status',
                'type' => 'select',
                'label' => 'Tests',
                'options' => ['yes_have_scores', 'test_optional', 'not_decided'],
            ];
        }

        if ($need('citizenship') || $profile->citizenship_residency === null) {
            $out[] = [
                'key' => 'citizenship_residency',
                'type' => 'select',
                'label' => 'Citizenship / residency',
                'options' => ['us_citizen', 'permanent_resident', 'international'],
            ];
        }

        if ($need('location')) {
            $out[] = [
                'key' => 'location_country',
                'type' => 'text',
                'label' => 'Country',
            ];
            $out[] = [
                'key' => 'location_region',
                'type' => 'text',
                'label' => 'State / province',
            ];
        }

        return array_slice($out, 0, 6);
    }
}
