<?php

namespace App\Jobs;

use App\Models\CollegeListRun;
use App\Services\CollegeListCouncil\CollegeListCouncilRunLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * If the student does not submit clarifications, resume the council from scouting after the wait period.
 */
class CollegeListCouncilAutoContinuePreflightJob implements ShouldQueue
{
    use Queueable;

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

        RunCollegeListCouncilJob::dispatch($this->runId);
    }
}
