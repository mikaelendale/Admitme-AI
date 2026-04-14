<?php

namespace App\Jobs;

use App\Actions\Ai\ComposeAiContextFromOnboardingData;
use App\Ai\Agents\ProfileContextAgent;
use App\Models\JobTracking;
use App\Models\OnboardingProfile;
use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use App\Models\User;
use App\Notifications\AiContextRebuildFailedNotification;
use App\Services\Ai\AiContextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class BuildUserAiContextJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public ?int $trackingId = null;

    public function __construct(
        public int $userId,
        public bool $useLlmRegenerate = false,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [45, 120];
    }

    public function handle(
        ComposeAiContextFromOnboardingData $compose,
        AiContextService $aiContextService,
    ): void {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        $tracking = $this->startTracking();

        try {
            if ($this->useLlmRegenerate) {
                $this->runLlmMerge($user, $aiContextService);
            } else {
                ($compose)($user);
            }

            $this->completeTracking($tracking);
        } catch (Throwable $e) {
            $this->failTracking($tracking, $e->getMessage());
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('BuildUserAiContextJob permanently failed', [
            'user_id' => $this->userId,
            'use_llm_regenerate' => $this->useLlmRegenerate,
            'tracking_id' => $this->trackingId,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        $tracking = $this->trackingId !== null
            ? JobTracking::query()->find($this->trackingId)
            : null;

        if ($tracking !== null && $tracking->status !== JobTracking::STATUS_FAILED) {
            $tracking->forceFill([
                'status' => JobTracking::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => mb_strimwidth($exception->getMessage(), 0, 2000, '…'),
            ])->save();
        }

        try {
            $user = User::query()->find($this->userId);
            if ($user !== null) {
                $retryUrl = $tracking !== null ? $tracking->retryUrl() : url('/profile-overview');
                $user->notify(new AiContextRebuildFailedNotification($retryUrl));
            }
        } catch (Throwable $e) {
            Log::warning('BuildUserAiContextJob: failed notification could not be sent', [
                'user_id' => $this->userId,
                'notify_error' => $e->getMessage(),
            ]);
        }
    }

    private function startTracking(): ?JobTracking
    {
        try {
            $tracking = JobTracking::query()->create([
                'user_id' => $this->userId,
                'job_type' => JobTracking::TYPE_AI_CONTEXT,
                'status' => JobTracking::STATUS_RUNNING,
                'payload' => ['user_id' => $this->userId, 'use_llm_regenerate' => $this->useLlmRegenerate],
                'started_at' => now(),
            ]);
            $this->trackingId = $tracking->id;

            return $tracking;
        } catch (Throwable $e) {
            Log::warning('BuildUserAiContextJob: could not create tracking record', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function completeTracking(?JobTracking $tracking): void
    {
        if ($tracking === null) {
            return;
        }

        try {
            $tracking->forceFill([
                'status' => JobTracking::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('BuildUserAiContextJob: could not update tracking to completed', ['error' => $e->getMessage()]);
        }
    }

    private function failTracking(?JobTracking $tracking, string $message): void
    {
        if ($tracking === null) {
            return;
        }

        try {
            $tracking->forceFill([
                'status' => JobTracking::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => mb_strimwidth($message, 0, 2000, '…'),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('BuildUserAiContextJob: could not update tracking to failed', ['error' => $e->getMessage()]);
        }
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
