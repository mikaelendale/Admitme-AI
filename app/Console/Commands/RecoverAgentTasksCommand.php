<?php

namespace App\Console\Commands;

use App\Domain\Tasks\Orchestrator;
use Illuminate\Console\Command;

/**
 * Requeues task steps whose worker stopped without finishing and dispatches anything ready to run.
 */
class RecoverAgentTasksCommand extends Command
{
    protected $signature = 'admitme:tasks:recover';

    protected $description = 'Recover agent task steps abandoned by a stopped worker and dispatch ready steps';

    public function handle(Orchestrator $orchestrator): int
    {
        $recovered = $orchestrator->recover();
        $this->info("Recovered {$recovered} step(s).");

        return self::SUCCESS;
    }
}
