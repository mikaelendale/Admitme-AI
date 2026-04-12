<?php

namespace App\Jobs;

use App\Actions\Ai\ComposeAiContextFromOnboardingData;
use App\Ai\Agents\ProfileContextAgent;
use App\Models\OnboardingProfile;
use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use App\Models\User;
use App\Services\Ai\AiContextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class BuildUserAiContextJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $userId,
        public bool $useLlmRegenerate = false,
    ) {}

    public function handle(
        ComposeAiContextFromOnboardingData $compose,
        AiContextService $aiContextService,
    ): void {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        if ($this->useLlmRegenerate) {
            $this->runLlmMerge($user, $aiContextService);

            return;
        }

        ($compose)($user);
    }

    private function runLlmMerge(User $user, AiContextService $aiContextService): void
    {
        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->latest('id')
            ->first();

        if (! $session) {
            return;
        }

        $pasteRow = OnboardingResponse::query()
            ->where('user_id', $user->id)
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'profile.freeform_text')
            ->first();

        $paste = $pasteRow?->answer_text ?? '';
        if (trim($paste) === '') {
            return;
        }

        $prefs = is_array($user->ai_preferences) ? $user->ai_preferences : [];
        $tone = is_string($prefs['mentor_tone'] ?? null) ? $prefs['mentor_tone'] : 'direct';

        $prompt = "Mentor tone preference: {$tone}\n\nStudent profile paste:\n\n---\n{$paste}\n---";

        try {
            $response = ProfileContextAgent::make()->prompt($prompt);
            if (! $response instanceof StructuredAgentResponse) {
                Log::warning('BuildUserAiContextJob: LLM merge returned non-structured response, profile context not updated', ['user_id' => $user->id]);

                return;
            }

            $structured = $response->structured;
            if (! is_array($structured)) {
                Log::warning('BuildUserAiContextJob: LLM merge returned non-structured response, profile context not updated', ['user_id' => $user->id]);

                return;
            }

            $profile = OnboardingProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['status' => 'completed']
            );

            $existing = is_array($profile->ai_context) ? $profile->ai_context : [];
            $merged = $aiContextService->mergeAndCap($existing, $structured);
            $merged = $aiContextService->setLastUpdated($merged);

            $profile->forceFill([
                'ai_context' => $merged,
                'last_ai_context_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('ProfileContextAgent failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
