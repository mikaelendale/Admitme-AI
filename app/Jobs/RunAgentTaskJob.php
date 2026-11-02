<?php

namespace App\Jobs;

use App\Domain\Tasks\Orchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one claimed task step. Retries, backoff and recovery belong to the orchestrator (the task row is the
 * source of truth), so the queue job itself is never retried: if a worker dies, the expired lease brings the
 * step back exactly once.
 */
class RunAgentTaskJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Shorter than the task lease, so a live job can never outlast its claim. */
    public int $timeout = 600;

    public function __construct(public string $taskId)
    {
        $this->afterCommit();
    }

    public function handle(Orchestrator $orchestrator): void
    {
        $orchestrator->run($this->taskId);
    }
}
