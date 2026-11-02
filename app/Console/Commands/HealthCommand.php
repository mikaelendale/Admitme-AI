<?php

namespace App\Console\Commands;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\AiModelResolver;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentTask;
use App\Models\DataExport;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operational checks: is background work moving, are provider keys present (never printed), can private
 * files be written, and how much shared knowledge is past its re-check date. Exits non-zero on a failure.
 */
class HealthCommand extends Command
{
    protected $signature = 'admitme:health {--log : Also write warnings and failures to the application log}';

    protected $description = 'Check queue backlog, stuck tasks and exports, stale sources, provider configuration and private storage';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(AiModelResolver $models): int
    {
        $this->queue();
        $this->tasks();
        $this->exports();
        $this->sources();
        $this->provider($models);
        $this->storage();

        $this->table(['Check', 'Status', 'Detail'], $this->rows);

        $problems = array_filter($this->rows, fn (array $row) => $row[1] !== 'ok');

        if ($this->option('log') && $problems !== []) {
            foreach ($problems as [$check, $status, $detail]) {
                Log::log($status === 'FAIL' ? 'error' : 'warning', "AdmitMe health: {$check} {$status}", ['detail' => $detail]);
            }
        }

        return array_filter($this->rows, fn (array $row) => $row[1] === 'FAIL') === [] ? self::SUCCESS : self::FAILURE;
    }

    private function queue(): void
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            $this->add('queue backlog', 'ok', 'Queue driver "'.config('queue.default').'": monitor it with its own tools.');
        } else {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('available_at');
            $age = $oldest !== null ? now()->getTimestamp() - (int) $oldest : 0;
            $this->add('queue backlog', $age > 600 ? 'WARN' : 'ok', "{$pending} job(s) waiting".($age > 0 ? ', oldest '.intdiv($age, 60).' min' : '').($age > 600 ? ' — is a queue worker running?' : ''));
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            $this->add('failed jobs (24 h)', $failed > 0 ? 'WARN' : 'ok', $failed > 0 ? "{$failed} failed job(s): php artisan queue:failed" : 'none');
        }
    }

    private function tasks(): void
    {
        $expired = AgentTask::query()->where('status', TaskStatus::Running->value)->where('lease_expires_at', '<', now())->count();
        $waiting = AgentTask::query()->whereNotNull('parent_id')->where('status', TaskStatus::Queued->value)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<', now()->subMinutes(15)))
            ->where('updated_at', '<', now()->subMinutes(15))
            ->count();

        $this->add('task steps', $expired + $waiting > 0 ? 'WARN' : 'ok', $expired + $waiting > 0
            ? "{$expired} running past their lease (worker stopped), {$waiting} queued over 15 min — admitme:tasks:recover runs every minute; check the worker"
            : 'nothing stuck');
    }

    private function exports(): void
    {
        $stuck = DataExport::query()->whereIn('status', ['queued', 'building'])->where('created_at', '<', now()->subHour())->count();
        $this->add('exports', $stuck > 0 ? 'WARN' : 'ok', $stuck > 0 ? "{$stuck} export(s) unfinished after an hour" : 'nothing stuck');
    }

    private function sources(): void
    {
        $days = (int) config('admitme.knowledge.stale_after_days', 30);
        $shared = KnowledgeSource::query()->whereNull('owner_user_id')->where('status', 'active');
        $total = (clone $shared)->count();
        $stale = (clone $shared)->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subDays($days)))->count();

        $this->add('shared sources', $stale > 0 ? 'WARN' : 'ok', "{$stale} of {$total} not re-checked in {$days} days".($stale > 0 ? ' (shown as stale to students; refresh them)' : ''));
    }

    private function provider(AiModelResolver $models): void
    {
        try {
            $provider = $models->providerFor(AdmitMeGeneralAgent::class);
            $model = $models->modelFor(AdmitMeGeneralAgent::class);
            $hasKey = filled(config("ai.providers.{$provider}.key"));
            $this->add('AI provider', $hasKey ? 'ok' : 'FAIL', "mentor uses {$provider} / {$model}; API key ".($hasKey ? 'present' : 'MISSING').' (keys are never printed; a present key can still be rejected — see the logs)');
        } catch (Throwable $e) {
            $this->add('AI provider', 'FAIL', Str::limit($e->getMessage(), 200));
        }
    }

    private function storage(): void
    {
        $path = 'health/'.Str::random(12).'.txt';

        try {
            $disk = Storage::disk('local');
            $ok = $disk->put($path, 'ok') && $disk->get($path) === 'ok';
            $disk->delete($path);
            $this->add('private storage', $ok ? 'ok' : 'FAIL', $ok ? 'writable' : 'could not write and read back a file');
        } catch (Throwable $e) {
            $this->add('private storage', 'FAIL', Str::limit($e->getMessage(), 200));
        }
    }

    private function add(string $check, string $status, string $detail): void
    {
        $this->rows[] = [$check, $status, $detail];
    }
}
