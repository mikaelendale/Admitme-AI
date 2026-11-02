<?php

namespace App\Domain\Writing;

use App\Ai\Agents\WritingCoachAgent;
use App\Ai\AiModelResolver;
use App\Models\User;
use App\Models\WritingFeedback;
use App\Models\WritingPiece;
use App\Models\WritingVersion;
use Illuminate\Validation\ValidationException;

/**
 * Coach feedback on one immutable version. The policy is enforced here, not left to the model: no call at
 * all under "no AI assistance"; replacement text is kept only for small language fixes when the policy
 * allows them. Every comment must quote the student's own words verbatim, so feedback always points at
 * the source text and never stands in for it.
 */
final class WritingCoach
{
    private const MAX_FIX_WORDS = 12;

    /**
     * @throws ValidationException
     */
    public function feedback(WritingPiece $piece, WritingVersion $version, User $student): WritingFeedback
    {
        if ($version->writing_piece_id !== $piece->getKey()) {
            throw ValidationException::withMessages(['version' => 'That version belongs to a different piece.']);
        }

        $review = $this->review($version->body, $piece->assistance_policy, $piece->prompt_text, $piece->word_limit, (int) $version->word_count, (int) $version->number);

        return WritingFeedback::query()->create([
            'writing_piece_id' => $piece->getKey(),
            'writing_version_id' => $version->getKey(),
            'user_id' => $student->getKey(),
            'assistance_policy' => $piece->assistance_policy->value,
            'comments' => $review['comments'],
            'summary' => $review['summary'],
            'dropped_comments' => $review['dropped'],
            'model' => app(AiModelResolver::class)->modelFor(WritingCoachAgent::class),
            'usage' => $review['usage'],
        ]);
    }

    /**
     * The coach's checked comments on a draft, without storing anything (shared with the evaluation set).
     * Under "no AI assistance" the model is never called.
     *
     * @return array{comments: list<array{quote: string, comment: string, kind: string, severity: string, fix: string|null}>, summary: string|null, dropped: int, usage: array<string, int>|null}
     *
     * @throws ValidationException
     */
    public function review(string $body, WritingPolicy $policy, ?string $promptText, ?int $wordLimit, int $wordCount, int $versionNumber): array
    {
        if (! $policy->allowsFeedback()) {
            throw ValidationException::withMessages(['policy' => $policy->description()]);
        }

        $prompt = implode("\n", array_filter([
            'Essay prompt: '.($promptText ?: 'not given'),
            $wordLimit ? "Word limit: {$wordLimit} (draft has {$wordCount} words)" : "Draft has {$wordCount} words",
            "Assistance policy: {$policy->label()} — {$policy->description()}",
            '',
            "Student's draft (version {$versionNumber}):",
            '"""',
            $body,
            '"""',
        ], fn ($line) => $line !== null));

        $response = WritingCoachAgent::make()->prompt($prompt);

        $normalizedBody = $this->normalize($body);
        $kept = [];
        $dropped = 0;

        foreach ((array) ($response['comments'] ?? []) as $comment) {
            $quote = is_array($comment) ? trim((string) ($comment['quote'] ?? '')) : '';

            if ($quote === '' || ! str_contains($normalizedBody, $this->normalize($quote))) {
                $dropped++;

                continue;
            }

            $fix = is_string($comment['fix'] ?? null) ? trim($comment['fix']) : null;
            $isLanguage = ($comment['kind'] ?? null) === 'language';

            // Replacement words only for small language fixes, and only where the college allows them...
            if ($fix !== null && (! $policy->allowsLanguageFixes() || ! $isLanguage || WritingService::wordCount($fix) > self::MAX_FIX_WORDS)) {
                $fix = null;
            }

            $kept[] = [
                'quote' => $quote,
                'comment' => trim((string) ($comment['comment'] ?? '')),
                'kind' => (string) ($comment['kind'] ?? 'clarity'),
                'severity' => (string) ($comment['severity'] ?? 'medium'),
                'fix' => $fix ?: null,
            ];

            if (count($kept) >= (int) config('admitme.writing.max_feedback_comments', 12)) {
                break;
            }
        }

        return [
            'comments' => $kept,
            'summary' => is_string($response['summary'] ?? null) ? trim($response['summary']) : null,
            'dropped' => $dropped,
            'usage' => isset($response->usage) && method_exists($response->usage, 'toArray') ? $response->usage->toArray() : null,
        ];
    }

    private function normalize(string $text): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', str_replace(['’', '‘', '“', '”'], ["'", "'", '"', '"'], trim($text))));
    }
}
