<?php

namespace App\Services\Essay;

use App\Ai\Agents\EssayStrengthScoreAgent;
use App\Models\User;
use App\Services\CollegeListCouncil\StructuredAgentResponseMapper;
use App\Support\EssaySuite\EssayStudentContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Promptable;

final class EssayStrengthScoreService
{
    private const SCORE_KEYS = [
        'authenticity',
        'specificity',
        'emotional_impact',
        'structure',
        'differentiation',
    ];

    /**
     * @return array<string, mixed>
     */
    public function score(User $user, string $essayText): array
    {
        $essayText = trim($essayText);
        $len = strlen($essayText);
        if ($len < 100 || $len > 8000) {
            throw ValidationException::withMessages([
                'essay_text' => 'Essay must be between 100 and 8000 characters.',
            ]);
        }

        if (config('essay_suite.fake')) {
            $payload = $this->fakeScorePayload();
            $err = $this->validateScorePayload($payload);
            if ($err !== null) {
                throw new \RuntimeException('Fake score payload failed self-check: '.$err);
            }

            return $this->normalizeScoresToInt($payload);
        }

        $envelope = [
            'essay_text' => $essayText,
            'student_profile_summary' => EssayStudentContext::compact($user),
        ];
        $prompt = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $first = $this->invokeAgent($prompt);
        $v1 = $this->validateScorePayload($first);
        if ($v1 === null) {
            return $this->normalizeScoresToInt($first);
        }

        Log::warning('EssayStrengthScoreService: first payload failed validation, retrying once.', [
            'reason' => $v1,
        ]);

        $retryPayload = json_encode([
            '_essay_score_retry' => true,
            '_validation_error' => $v1,
            'essay_score_request' => json_decode($prompt, true) ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $second = $this->invokeAgent($retryPayload);
        $v2 = $this->validateScorePayload($second);
        if ($v2 !== null) {
            throw ValidationException::withMessages([
                'analysis' => 'Score output could not be validated. Try again with a shorter excerpt.',
            ]);
        }

        return $this->normalizeScoresToInt($second);
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeAgent(string $prompt): array
    {
        /** @var Promptable&object $agent */
        $agent = EssayStrengthScoreAgent::make();
        $response = $agent->prompt($prompt);

        return StructuredAgentResponseMapper::toArray($response);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function validateScorePayload(array $p): ?string
    {
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
        if (! isset($p['top_priority_fix']) || ! is_string($p['top_priority_fix']) || trim($p['top_priority_fix']) === '') {
            return 'Missing top_priority_fix.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeScorePayload(): array
    {
        $mk = fn (int $s, string $r) => ['score' => $s, 'reasoning' => $r];

        return [
            'scores' => [
                'authenticity' => $mk(5, 'Readable but safe; few risky personal specifics.'),
                'specificity' => $mk(6, 'Some concrete nouns; outcomes still under-quantified.'),
                'emotional_impact' => $mk(5, 'Steady tone; lacks a sharp hinge moment.'),
                'structure' => $mk(7, 'Clear beats; transitions are functional.'),
                'differentiation' => $mk(4, 'Arc resembles common templates for this activity type.'),
            ],
            'top_priority_fix' => 'Swap one generic paragraph for a single scene with dialogue, time stamp, and a cost/tradeoff you actually paid.',
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
