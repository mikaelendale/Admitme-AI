<?php

namespace App\Services\Essay;

use App\Ai\Agents\EssayRoastAgent;
use App\Models\EssayRoast;
use App\Models\EssayRun;
use App\Models\User;
use App\Services\CollegeListCouncil\StructuredAgentResponseMapper;
use App\Support\EssaySuite\EssayStudentContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Promptable;

final class EssayRoastService
{
    private const SCORE_KEYS = [
        'authenticity',
        'specificity',
        'emotional_impact',
        'structure',
        'differentiation',
    ];

    /**
     * @return array{id: string, payload: array<string, mixed>}
     */
    public function roast(User $user, string $essayText, ?string $essayRunId, ?string $essayTypeLabel): array
    {
        $essayText = trim($essayText);
        $len = strlen($essayText);
        if ($len < 100 || $len > 8000) {
            throw ValidationException::withMessages([
                'essay_text' => 'Essay must be between 100 and 8000 characters.',
            ]);
        }

        if ($essayRunId !== null && $essayRunId !== '') {
            $exists = EssayRun::query()
                ->where('id', $essayRunId)
                ->where('user_id', $user->id)
                ->exists();
            if (! $exists) {
                throw ValidationException::withMessages([
                    'essay_run_id' => 'Invalid essay run for this account.',
                ]);
            }
        }

        $hash = hash('sha256', $essayText);

        if (config('essay_suite.fake')) {
            $payload = $this->fakeRoastPayload();
            $err = $this->validateRoastPayload($payload);
            if ($err !== null) {
                throw new \RuntimeException('Fake roast payload failed self-check: '.$err);
            }

            return $this->persist($user, $essayRunId, $hash, $payload);
        }

        $envelope = [
            'essay_text' => $essayText,
            'student_profile_summary' => EssayStudentContext::compact($user),
            'essay_type' => $essayTypeLabel ?? '',
        ];
        $prompt = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $first = $this->invokeAgent($prompt);
        $v1 = $this->validateRoastPayload($first);
        if ($v1 === null) {
            return $this->persist($user, $essayRunId, $hash, $first);
        }

        Log::warning('EssayRoastService: first payload failed validation, retrying once.', [
            'reason' => $v1,
        ]);

        $retryPayload = json_encode([
            '_essay_roast_retry' => true,
            '_validation_error' => $v1,
            'essay_roast_request' => json_decode($prompt, true) ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $second = $this->invokeAgent($retryPayload);
        $v2 = $this->validateRoastPayload($second);
        if ($v2 !== null) {
            throw ValidationException::withMessages([
                'analysis' => 'Roast output could not be validated. Try a shorter excerpt or Regenerate.',
            ]);
        }

        return $this->persist($user, $essayRunId, $hash, $second);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeAgent(string $prompt): array
    {
        /** @var Promptable&object $agent */
        $agent = EssayRoastAgent::make();
        $response = $agent->prompt($prompt);

        return StructuredAgentResponseMapper::toArray($response);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function validateRoastPayload(array $p): ?string
    {
        if (! isset($p['overall_verdict']) || ! is_string($p['overall_verdict']) || trim($p['overall_verdict']) === '') {
            return 'Missing overall_verdict.';
        }
        if (! isset($p['scores']) || ! is_array($p['scores'])) {
            return 'Missing scores.';
        }
        $scores = $p['scores'];
        foreach (self::SCORE_KEYS as $k) {
            if (! isset($scores[$k]) || ! is_array($scores[$k])) {
                return 'Missing score key '.$k.'.';
            }
            $row = $scores[$k];
            $raw = $row['score'] ?? null;
            if (! is_int($raw) && ! is_float($raw)) {
                return 'Score '.$k.' not numeric.';
            }
            $si = (int) round((float) $raw);
            if ($si < 0 || $si > 10) {
                return 'Score '.$k.' out of range.';
            }
            if (! isset($row['reasoning']) || ! is_string($row['reasoning']) || trim($row['reasoning']) === '') {
                return 'Missing reasoning for '.$k.'.';
            }
        }
        if (! isset($p['cliches']) || ! is_array($p['cliches'])) {
            return 'Missing cliches array.';
        }
        foreach ($p['cliches'] as $i => $c) {
            if (! is_array($c)) {
                return 'Cliche '.$i.' invalid.';
            }
            foreach (['phrase', 'reason', 'suggestion'] as $f) {
                if (! isset($c[$f]) || ! is_string($c[$f]) || trim($c[$f]) === '') {
                    return 'Cliche '.$i.' missing '.$f.'.';
                }
            }
        }
        foreach (['structural_issues', 'voice_issues', 'strengths'] as $arrKey) {
            if (! isset($p[$arrKey]) || ! is_array($p[$arrKey])) {
                return 'Missing '.$arrKey.' array.';
            }
            foreach ($p[$arrKey] as $j => $line) {
                if (! is_string($line) || trim($line) === '') {
                    return 'Empty line in '.$arrKey.' at '.$j.'.';
                }
            }
        }
        if (! isset($p['top_priority_fix']) || ! is_string($p['top_priority_fix']) || trim($p['top_priority_fix']) === '') {
            return 'Missing top_priority_fix.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeRoastPayload(): array
    {
        $mk = fn (int $s, string $r) => ['score' => $s, 'reasoning' => $r];

        return [
            'overall_verdict' => 'This reads like a polished first draft with familiar beats. The opening signals empathy but leans on stock phrasing; the middle has one concrete scene worth keeping.',
            'scores' => [
                'authenticity' => $mk(4, 'Voice flattens into brochure-adjacent optimism; few idiosyncratic details.'),
                'specificity' => $mk(5, 'Some scenes; several claims lack measurable outcomes.'),
                'emotional_impact' => $mk(5, 'Earnest but predictable arc blunts punch.'),
                'structure' => $mk(6, 'Clear progression; ending restates the intro.'),
                'differentiation' => $mk(4, 'Could belong to many applicants in the same club archetype.'),
            ],
            'cliches' => [
                [
                    'phrase' => 'ever since I was young',
                    'reason' => 'Overused timeline opener in admissions essays.',
                    'suggestion' => 'Start in medias res with one specific hour and sensory detail.',
                ],
            ],
            'structural_issues' => [
                'The conclusion repeats the introduction almost verbatim.',
            ],
            'voice_issues' => [
                'Polished sentences feel uniformly mature — vary rhythm and include one imperfect, human line.',
            ],
            'strengths' => [
                'One middle paragraph shows genuine situational awareness.',
            ],
            'top_priority_fix' => 'Replace the first and last paragraphs with one new scene that only you could write, then rewrite the thesis as a question the reader needs answered.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, payload: array<string, mixed>}
     */
    private function persist(User $user, ?string $essayRunId, string $hash, array $payload): array
    {
        $normalized = $this->normalizeScoresToInt($payload);
        $row = EssayRoast::query()->create([
            'user_id' => $user->id,
            'essay_run_id' => $essayRunId !== null && $essayRunId !== '' ? $essayRunId : null,
            'essay_text_hash' => $hash,
            'payload' => $normalized,
        ]);

        return [
            'id' => (string) $row->id,
            'payload' => $normalized,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeScoresToInt(array $payload): array
    {
        $scores = $payload['scores'] ?? [];
        if (! is_array($scores)) {
            return $payload;
        }
        foreach (self::SCORE_KEYS as $k) {
            if (isset($scores[$k]) && is_array($scores[$k]) && isset($scores[$k]['score'])) {
                $scores[$k]['score'] = (int) round((float) $scores[$k]['score']);
            }
        }
        $payload['scores'] = $scores;

        return $payload;
    }
}
