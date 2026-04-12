<?php

namespace App\Services\GapFix;

/**
 * Post-LLM validation: schema shape + cross-check selected school IDs.
 * Supports full success or explicit partial (some schools valid, others missing).
 *
 * @phpstan-type GapPayload array{
 *   rejection_risks: list<array{college_list_school_id: string, reasons: list<array{text: string, severity: string}>}>,
 *   gaps: list<array{id: string, severity: string, title: string, steps: list<string>}>,
 *   disclaimer?: string|null
 * }
 */
final class GapFixAnalysisPayloadValidator
{
    private const SEVERITIES = ['critical', 'moderate', 'minor'];

    /**
     * @param  list<string>  $expectedSchoolIds  ULIDs in the order the user selected
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, partial: bool, missing_school_ids: list<string>, payload: array<string, mixed>}|array{ok: false, reason: string}
     */
    public function validate(array $payload, array $expectedSchoolIds): array
    {
        $expected = array_values(array_unique(array_map(fn (mixed $id): string => (string) $id, $expectedSchoolIds)));
        if ($expected === []) {
            return ['ok' => false, 'reason' => 'No schools selected for validation.'];
        }

        if (! isset($payload['rejection_risks']) || ! is_array($payload['rejection_risks'])) {
            return ['ok' => false, 'reason' => 'Missing rejection_risks array.'];
        }

        if (! isset($payload['gaps']) || ! is_array($payload['gaps'])) {
            return ['ok' => false, 'reason' => 'Missing gaps array.'];
        }

        $gapsError = $this->validateGaps($payload['gaps']);
        if ($gapsError !== null) {
            return ['ok' => false, 'reason' => $gapsError];
        }

        /** @var list<array{college_list_school_id: string, reasons: list<array{text: string, severity: string}>}> $normalizedRisks */
        $normalizedRisks = [];
        $seen = [];

        foreach ($payload['rejection_risks'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sid = isset($row['college_list_school_id']) ? trim((string) $row['college_list_school_id']) : '';
            if ($sid === '' || ! in_array($sid, $expected, true)) {
                continue;
            }
            if (isset($seen[$sid])) {
                return ['ok' => false, 'reason' => 'Duplicate rejection_risks entry for school '.$sid.'.'];
            }
            $seen[$sid] = true;
            $reasons = $row['reasons'] ?? null;
            if (! is_array($reasons) || count($reasons) !== 3) {
                continue;
            }
            $clean = [];
            foreach ($reasons as $r) {
                $norm = $this->normalizeReason($r);
                if ($norm === null) {
                    $clean = [];

                    break;
                }
                $clean[] = $norm;
            }
            if (count($clean) !== 3) {
                continue;
            }
            $normalizedRisks[] = [
                'college_list_school_id' => $sid,
                'reasons' => $clean,
            ];
        }

        $validIds = array_map(fn (array $r): string => $r['college_list_school_id'], $normalizedRisks);
        $missing = array_values(array_diff($expected, $validIds));

        if ($validIds === []) {
            return ['ok' => false, 'reason' => 'No valid rejection_risks rows after validation.'];
        }

        if ($missing === []) {
            $out = $payload;
            $out['rejection_risks'] = $normalizedRisks;

            return [
                'ok' => true,
                'partial' => false,
                'missing_school_ids' => [],
                'payload' => $out,
            ];
        }

        // Partial: at least one school fully valid; gaps already valid; some schools missing.
        $out = $payload;
        $out['rejection_risks'] = $normalizedRisks;

        return [
            'ok' => true,
            'partial' => true,
            'missing_school_ids' => $missing,
            'payload' => $out,
        ];
    }

    /**
     * @param  array<int, mixed>  $gaps
     */
    private function validateGaps(array $gaps): ?string
    {
        if ($gaps === []) {
            return 'gaps must contain at least one item.';
        }

        foreach ($gaps as $i => $g) {
            if (! is_array($g)) {
                return 'Gap at index '.$i.' is not an object.';
            }
            $id = isset($g['id']) ? trim((string) $g['id']) : '';
            $title = isset($g['title']) ? trim((string) $g['title']) : '';
            $severity = isset($g['severity']) ? (string) $g['severity'] : '';
            if ($id === '' || $title === '') {
                return 'Gap at index '.$i.' missing id or title.';
            }
            if (! in_array($severity, self::SEVERITIES, true)) {
                return 'Gap at index '.$i.' has invalid severity.';
            }
            $steps = $g['steps'] ?? null;
            if (! is_array($steps)) {
                return 'Gap at index '.$i.' missing steps.';
            }
            $stepCount = count($steps);
            if ($stepCount < 3 || $stepCount > 6) {
                return 'Gap at index '.$i.' must have 3–6 steps.';
            }
            foreach ($steps as $s) {
                if (! is_string($s) || trim($s) === '') {
                    return 'Gap at index '.$i.' has an empty step.';
                }
            }
        }

        return null;
    }

    /**
     * @return array{text: string, severity: string}|null
     */
    private function normalizeReason(mixed $r): ?array
    {
        if (is_string($r)) {
            $t = trim($r);

            return $t === '' ? null : ['text' => $t, 'severity' => 'moderate'];
        }
        if (! is_array($r)) {
            return null;
        }
        $text = isset($r['text']) ? trim((string) $r['text']) : '';
        if ($text === '') {
            return null;
        }
        $sev = isset($r['severity']) ? (string) $r['severity'] : 'moderate';
        if (! in_array($sev, self::SEVERITIES, true)) {
            $sev = 'moderate';
        }

        return ['text' => $text, 'severity' => $sev];
    }
}
