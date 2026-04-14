<?php

namespace App\Jobs;

use App\Models\CollegeListRun;
use App\Models\JobTracking;
use App\Models\User;
use App\Notifications\CollegeListFailedNotification;
use App\Services\CollegeListCouncil\CollegeListCouncilRunLog;
use App\Services\CollegeListCouncil\CollegeListRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * If the student does not submit clarifications, resume the council from scouting after the wait period.
 */
class CollegeListCouncilAutoContinuePreflightJob implements ShouldQueue
{
    use Queueable;

    public ?int $trackingId = null;

    public function __construct(
        public string $runId,
    ) {}

    public function handle(): void
    {
        $run = CollegeListRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        if ($run->status !== CollegeListRun::STATUS_AWAITING_PREFLIGHT) {
            return;
        }

        $tracking = $this->startTracking((int) $run->user_id);

        $meta = array_merge($run->meta ?? [], [
            'preflight_resumed' => true,
            'preflight_auto_continued' => true,
            'preflight_student_reply' => '',
        ]);
        unset($meta['preflight_questions_pending']);

        $run->forceFill([
            'status' => CollegeListRun::STATUS_QUEUED,
            'meta' => $meta,
        ])->save();

        CollegeListCouncilRunLog::info('Council preflight auto-continuing after timeout', [
            'run_id' => $this->runId,
            'user_id' => $run->user_id,
        ]);

        $this->completeTracking($tracking);

        RunCollegeListCouncilJob::dispatch($this->runId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CollegeListCouncilAutoContinuePreflightJob permanently failed', [
            'run_id' => $this->runId,
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
            $run = CollegeListRun::query()->find($this->runId);
            if ($run !== null) {
                if (! $run->isTerminal()) {
                    try {
                        app(CollegeListRunRecorder::class)->fail(
                            $run,
                            'Your college list generation failed. Please try again.'
                        );
                    } catch (Throwable $e) {
                        Log::warning('CollegeListCouncilAutoContinuePreflightJob: could not mark run failed', [
                            'run_id' => $this->runId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $user = User::query()->find($run->user_id);
                if ($user !== null) {
                    $user->notify(new CollegeListFailedNotification);
                }
            }
        } catch (Throwable $e) {
            Log::warning('CollegeListCouncilAutoContinuePreflightJob: failed() notification error', [
                'run_id' => $this->runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function startTracking(int $userId): ?JobTracking
    {
        try {
            $tracking = JobTracking::query()->create([
                'user_id' => $userId,
                'job_type' => JobTracking::TYPE_COUNCIL_PREFLIGHT,
                'status' => JobTracking::STATUS_RUNNING,
                'payload' => ['run_id' => $this->runId],
                'started_at' => now(),
            ]);
            $this->trackingId = $tracking->id;

            return $tracking;
        } catch (Throwable $e) {
            Log::warning('CollegeListCouncilAutoContinuePreflightJob: could not create tracking record', ['error' => $e->getMessage()]);

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
            Log::warning('CollegeListCouncilAutoContinuePreflightJob: could not update tracking', ['error' => $e->getMessage()]);
        }
    }
}
