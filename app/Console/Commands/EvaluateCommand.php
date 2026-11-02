<?php

namespace App\Console\Commands;

use App\Domain\Observability\EvaluationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Runs the evaluation set against the configured models (real calls; it costs a little) and optionally
 * saves the results so a later run — after a prompt, tool or model change — can be compared with it.
 */
class EvaluateCommand extends Command
{
    protected $signature = 'admitme:eval
        {suite=all : research, writing or all}
        {--save : Keep the results under storage/app/private/evals}
        {--compare= : A saved results file (path relative to storage/app/private) to compare against}';

    protected $description = 'Run the research and writing evaluation set through the production prompts and validators';

    public function handle(EvaluationRunner $runner): int
    {
        $suite = (string) $this->argument('suite');
        $suites = $suite === 'all' ? EvaluationRunner::SUITES : [$suite];

        if (array_diff($suites, EvaluationRunner::SUITES) !== []) {
            $this->error('Unknown suite. Use research, writing or all.');

            return self::INVALID;
        }

        $results = [];

        foreach ($suites as $name) {
            $this->info("Running {$name}…");
            $result = $runner->run($name);
            $results[$name] = $result;

            $this->line("{$result['provider']} / {$result['model']} · code {$result['instruction_version']}");
            $this->table(['Case', 'Passed', 'Metrics', 'Notes'], array_map(fn (array $case) => [
                $case['id'],
                $case['passed'] ? 'yes' : 'NO',
                implode(', ', array_map(fn ($k, $v) => $k.'='.(is_bool($v) ? ($v ? 'yes' : 'no') : $v), array_keys($case['metrics']), $case['metrics'])),
                implode('; ', $case['notes']),
            ], $result['cases']));
            $this->line('Summary: '.json_encode($result['summary']));
        }

        if ($this->option('save')) {
            $path = 'evals/'.now()->format('Ymd-His').'-'.$suite.'.json';
            Storage::disk('local')->put($path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Saved: storage/app/private/{$path}");
        }

        if (is_string($this->option('compare'))) {
            $this->compare($results, (string) $this->option('compare'));
        }

        $failed = array_sum(array_map(fn (array $r) => $r['summary']['cases'] - $r['summary']['passed'], $results));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function compare(array $results, string $path): void
    {
        if (! Storage::disk('local')->exists($path)) {
            $this->error("No saved results at storage/app/private/{$path}.");

            return;
        }

        $previous = json_decode((string) Storage::disk('local')->get($path), true);

        foreach ($results as $suite => $result) {
            $before = collect($previous[$suite]['cases'] ?? [])->keyBy('id');
            $this->info("Compared with {$path} ({$suite}: ".($previous[$suite]['model'] ?? '?').' · code '.($previous[$suite]['instruction_version'] ?? '?').')');

            $this->table(['Case', 'Before', 'Now'], array_map(fn (array $case) => [
                $case['id'],
                $before->has($case['id']) ? ($before[$case['id']]['passed'] ? 'pass' : 'FAIL') : 'new',
                $case['passed'] ? 'pass' : 'FAIL',
            ], $result['cases']));
        }
    }
}
