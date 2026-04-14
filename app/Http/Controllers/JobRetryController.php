<?php

namespace App\Http\Controllers;

use App\Jobs\BuildUserAiContextJob;
use App\Jobs\CollegeListCouncilAutoContinuePreflightJob;
use App\Jobs\ProcessEssayRunJob;
use App\Jobs\RunCollegeListCouncilJob;
use App\Models\JobTracking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JobRetryController extends Controller
{
    public function __invoke(Request $request, int $jobTrackingId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tracking = JobTracking::query()
            ->where('id', $jobTrackingId)
            ->where('user_id', $user->id)
            ->first();

        if ($tracking === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        if ($tracking->status === JobTracking::STATUS_RUNNING) {
            return response()->json(['message' => 'Job is still running.'], 409);
        }

        $payload = is_array($tracking->payload) ? $tracking->payload : [];

        try {
            $this->dispatchRetry($tracking->job_type, $payload, (int) $user->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tracking->forceFill([
            'status' => JobTracking::STATUS_PENDING,
            'failed_at' => null,
            'error_message' => null,
            'completed_at' => null,
            'started_at' => null,
        ])->save();

        Log::info('JobRetryController: job re-queued by user', [
            'job_tracking_id' => $tracking->id,
            'job_type' => $tracking->job_type,
            'user_id' => $user->id,
        ]);

        return response()->json(['message' => 'Job re-queued successfully.', 'tracking_id' => $tracking->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchRetry(string $jobType, array $payload, int $userId): void
    {
        match ($jobType) {
            JobTracking::TYPE_AI_CONTEXT => BuildUserAiContextJob::dispatch(
                $payload['user_id'] ?? $userId,
                (bool) ($payload['use_llm_regenerate'] ?? false),
            ),
            JobTracking::TYPE_ESSAY_RUN => ProcessEssayRunJob::dispatch(
                (string) ($payload['essay_run_id'] ?? throw new \InvalidArgumentException('Missing essay_run_id in payload.')),
            ),
            JobTracking::TYPE_COLLEGE_LIST_COUNCIL => RunCollegeListCouncilJob::dispatch(
                (string) ($payload['run_id'] ?? throw new \InvalidArgumentException('Missing run_id in payload.')),
            ),
            JobTracking::TYPE_COUNCIL_PREFLIGHT => CollegeListCouncilAutoContinuePreflightJob::dispatch(
                (string) ($payload['run_id'] ?? throw new \InvalidArgumentException('Missing run_id in payload.')),
            ),
            default => throw new \InvalidArgumentException("Unknown job type: {$jobType}"),
        };
    }
}
