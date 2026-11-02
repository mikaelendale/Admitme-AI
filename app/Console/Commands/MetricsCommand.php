<?php

namespace App\Console\Commands;

use App\Domain\Observability\QualityMetrics;
use Illuminate\Console\Command;

/**
 * Operator report of reliability, quality, latency and usage from stored run records. Read-only.
 */
class MetricsCommand extends Command
{
    protected $signature = 'admitme:metrics
        {--days=30 : How far back to look}
        {--json : Print JSON instead of tables}';

    protected $description = 'Report answer support, extraction corrections, fills, task completion, latency, usage and integrity checks';

    public function handle(QualityMetrics $metrics): int
    {
        $report = $metrics->report(now()->subDays(max(1, (int) $this->option('days'))));

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($report as $section => $measures) {
            $this->info(str_replace('_', ' ', ucfirst($section)));
            $this->table(['Measure', 'Value'], array_map(fn ($key, $value) => [str_replace('_', ' ', $key), $value ?? '—'], array_keys($measures), $measures));
        }

        $broken = array_filter($report['integrity']);

        if ($broken !== []) {
            $this->error('Integrity checks failed: '.implode(', ', array_keys($broken)).'. These states should be impossible; investigate before release.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
