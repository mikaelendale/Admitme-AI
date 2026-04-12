<?php

namespace App\Services\Profile;

final class GpaNormalizer
{
    /**
     * @return array{gpa_normalized_4: float|null, gpa_value: string|null, gpa_scale: string|null}
     */
    public function toUsFourPointScale(
        ?float $gpaValue,
        ?float $gpaScale,
        ?string $curriculum,
    ): array {
        if ($gpaValue === null || $gpaScale === null || $gpaScale <= 0) {
            return ['gpa_normalized_4' => null, 'gpa_value' => null, 'gpa_scale' => null];
        }

        $curriculumLower = $curriculum !== null ? strtolower($curriculum) : '';

        if (str_contains($curriculumLower, 'ib')) {
            $on4 = min(4.0, max(0.0, ($gpaValue / 7.0) * 4.0));

            return [
                'gpa_normalized_4' => round($on4, 2),
                'gpa_value' => (string) $gpaValue,
                'gpa_scale' => '7.0',
            ];
        }

        $on4 = min(4.0, max(0.0, ($gpaValue / $gpaScale) * 4.0));

        return [
            'gpa_normalized_4' => round($on4, 2),
            'gpa_value' => (string) round($gpaValue, 2),
            'gpa_scale' => (string) round($gpaScale, 2),
        ];
    }
}
