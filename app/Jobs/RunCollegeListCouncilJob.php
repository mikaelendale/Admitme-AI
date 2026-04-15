<?php

namespace App\Jobs;

use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use App\Models\JobTracking;
use App\Models\User;
use App\Notifications\CollegeListFailedNotification;
use App\Services\CollegeListCouncil\CollegeListCouncilOrchestratorContract;
use App\Services\CollegeListCouncil\CollegeListCouncilRunLog;
use App\Services\CollegeListCouncil\CollegeListRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunCollegeListCouncilJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public ?int $trackingId = null;

    public function __construct(
        public string $runId,
    ) {
        $this->timeout = max(120, (int) config('college_list_council.job_timeout', 600));
    }

    public function tries(): int
    {
        return max(1, (int) config('college_list_council.job_tries', 1));
    }

    public function handle(
        CollegeListCouncilOrchestratorContract $orchestrator,
        CollegeListRunRecorder $recorder,
    ): void {
        $run = CollegeListRun::query()->find($this->runId);
        if ($run === null) {
            CollegeListCouncilRunLog::warning('Council job skipped: run not found', [
                'run_id' => $this->runId,
            ]);

            return;
        }

        if ($run->isTerminal()) {
            CollegeListCouncilRunLog::info('Council job skipped: run already terminal', [
                'run_id' => $this->runId,
                'status' => $run->status,
            ]);

            return;
        }

        /** @var User|null $user */
        $user = User::query()->find($run->user_id);
        if ($user === null) {
            CollegeListCouncilRunLog::warning('Council job failed: user missing', [
                'run_id' => $this->runId,
                'user_id' => $run->user_id,
            ]);
            $recorder->fail($run, 'User not found.');

            return;
        }

        $tracking = null;
        $startedNs = hrtime(true);

        try {
            $run->forceFill(['started_at' => $run->started_at ?? now()])->save();

            $tracking = $this->startTracking((int) $user->id);

            CollegeListCouncilRunLog::info('Council job started', [
                'run_id' => $this->runId,
                'user_id' => $user->id,
                'intent' => $run->intent,
            ]);

            $orchestrator->execute($run->fresh(), $user);

            $finished = $run->fresh();
            $durationMs = (int) ((hrtime(true) - $startedNs) / 1_000_000);
            $schoolsForRun = $finished !== null
                ? CollegeListSchool::query()->where('college_list_run_id', $finished->id)->count()
                : 0;

            CollegeListCouncilRunLog::info('Council job finished', [
                'run_id' => $this->runId,
                'user_id' => $user->id,
                'status' => $finished?->status,
                'duration_ms' => $durationMs,
                'schools_persisted_for_run' => $schoolsForRun,
            ]);

            $this->completeTracking($tracking);
        } catch (Throwable $e) {
            CollegeListCouncilRunLog::error('RunCollegeListCouncilJob failed', [
                'run_id' => $this->runId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->failTrackingRecord($tracking, $e->getMessage());

            $fresh = $run->fresh();
            if ($fresh !== null && ! $fresh->isTerminal()) {
                $recorder->fail($fresh, 'Something went wrong while building your college list. Please try again.');
            }

            // Do not rethrow: mark the run failed above so the UI can recover; avoid queue-level
            // "permanently failed" / retry storms for LLM timeouts and DB hiccups.
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('RunCollegeListCouncilJob permanently failed', [
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
                // Ensure the run doesn't get stuck "active" forever (blocks future runs).
                if (! $run->isTerminal()) {
                    try {
                        app(CollegeListRunRecorder::class)->fail(
                            $run,
                            'Your college list generation failed. Please try again.'
                        );
                    } catch (Throwable $e) {
                        Log::warning('RunCollegeListCouncilJob: could not mark run failed', [
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
            Log::warning('RunCollegeListCouncilJob: failed() notification error', [
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
                'job_type' => JobTracking::TYPE_COLLEGE_LIST_COUNCIL,
                'status' => JobTracking::STATUS_RUNNING,
                'payload' => ['run_id' => $this->runId],
                'started_at' => now(),
            ]);
            $this->trackingId = $tracking->id;

            return $tracking;
        } catch (Throwable $e) {
            Log::warning('RunCollegeListCouncilJob: could not create tracking record', ['error' => $e->getMessage()]);

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
            Log::warning('RunCollegeListCouncilJob: could not update tracking to completed', ['error' => $e->getMessage()]);
        }
    }

    private function failTrackingRecord(?JobTracking $tracking, string $message): void
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
            Log::warning('RunCollegeListCouncilJob: could not update tracking to failed', ['error' => $e->getMessage()]);
        }
    }
}
