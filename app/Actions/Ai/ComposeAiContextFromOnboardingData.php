<?php

namespace App\Actions\Ai;

use App\Models\OnboardingProfile;
use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use App\Models\User;
use App\Services\Ai\AiContextService;

/**
 * Builds compact ai_context from stored profile paste + No Cap structured JSON (no extra LLM call).
 */
final class ComposeAiContextFromOnboardingData
{
    public function __construct(
        private AiContextService $aiContextService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(User $user): ?array
    {
        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if (! $profile) {
            return null;
        }

        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->latest('id')
            ->first();

        if (! $session) {
            return null;
        }

        $pasteRow = OnboardingResponse::query()
            ->where('user_id', $user->id)
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'profile.freeform_text')
            ->first();

        $reportRow = OnboardingResponse::query()
            ->where('user_id', $user->id)
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'report.no_cap_markdown')
            ->first();

        $paste = $pasteRow?->answer_text ?? '';
        $structured = data_get($reportRow?->answer_json, 'structured');

        if (! is_array($structured) && trim($paste) === '') {
            return null;
        }

        $incoming = $this->mapToContextShape(
            is_array($structured) ? $structured : [],
            $paste,
            $profile,
        );

        $existing = is_array($profile->ai_context) ? $profile->ai_context : [];

        $merged = $this->aiContextService->mergeAndCap($existing, $incoming);
        $merged = $this->aiContextService->setLastUpdated($merged);

        $profile->forceFill([
            'ai_context' => $merged,
            'last_ai_context_at' => now(),
        ])->save();

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function mapToContextShape(array $structured, string $paste, OnboardingProfile $profile): array
    {
        $excerpt = mb_substr(trim($paste), 0, 500);

        $dimensions = [];
        if (isset($structured['dimensions']) && is_array($structured['dimensions'])) {
            foreach ($structured['dimensions'] as $d) {
                if (! is_array($d)) {
                    continue;
                }
                $key = $d['key'] ?? null;
                if (! is_string($key)) {
                    continue;
                }
                $dimensions[$key] = [
                    'score' => $d['score'] ?? null,
                    'verdict' => $d['verdict'] ?? null,
                    'label' => $d['label'] ?? null,
                ];
            }
        }

        $chips = [];
        if (isset($structured['insight_tokens']) && is_array($structured['insight_tokens'])) {
            foreach ($structured['insight_tokens'] as $t) {
                if (is_array($t) && isset($t['text']) && is_string($t['text'])) {
                    $chips[] = $t['text'];
                }
            }
        }

        $takeaways = [];
        if (isset($structured['takeaways']) && is_array($structured['takeaways'])) {
            foreach ($structured['takeaways'] as $line) {
                if (is_string($line)) {
                    $takeaways[] = $line;
                }
            }
        }

        return [
            'headline' => isset($structured['headline']) && is_string($structured['headline'])
                ? $structured['headline']
                : null,
            'profile' => [
                'excerpt' => $excerpt !== '' ? $excerpt : null,
                'grade' => $profile->current_grade,
                'graduation_year' => $profile->graduation_year,
            ],
            'radar' => $dimensions !== [] ? $dimensions : null,
            'signals' => $chips !== [] ? array_values(array_slice($chips, 0, 12)) : null,
            'takeaways' => $takeaways !== [] ? array_values(array_slice($takeaways, 0, 8)) : null,
            'structured_fields' => [
                'gpa_value' => $profile->gpa_value,
                'sat_score' => $profile->sat_score,
                'act_score' => $profile->act_score,
            ],
        ];
    }
}
