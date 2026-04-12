<?php

namespace App\Jobs;

use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use App\Models\User;
use App\Services\CollegeListCouncil\CollegeListCouncilOrchestratorContract;
use App\Services\CollegeListCouncil\CollegeListCouncilRunLog;
use App\Services\CollegeListCouncil\CollegeListRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunCollegeListCouncilJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

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

        $run->forceFill(['started_at' => $run->started_at ?? now()])->save();

        $startedNs = hrtime(true);

        CollegeListCouncilRunLog::info('Council job started', [
            'run_id' => $this->runId,
            'user_id' => $user->id,
            'intent' => $run->intent,
        ]);

        try {
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
        } catch (Throwable $e) {
            CollegeListCouncilRunLog::error('RunCollegeListCouncilJob failed', [
                'run_id' => $this->runId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $fresh = $run->fresh();
            if ($fresh !== null && ! $fresh->isTerminal()) {
                $recorder->fail($fresh, 'Something went wrong while building your college list. Please try again.');
            }
        }
    }
}
