<?php

namespace App\Domain\Tasks;

use App\Ai\AiModelResolver;
use App\Domain\Memory\MemoryReader;
use App\Domain\Observability\RunInstrumentation;
use App\Domain\Tasks\Delegation\Handoff;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * What a running step may use: the authorized student (fixed server-side — never chosen by a model),
 * events, cancellation checkpoints, results of the steps it depends on, and budgeted model calls.
 */
final class TaskContext
{
    public function __construct(
        public readonly AgentTask $task,
        public readonly User $student,
        private readonly TaskEvents $events,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function event(string $type, string $message, array $data = []): void
    {
        $this->events->record($this->task, $type, $message, $data);
    }

    /**
     * Stop here if the student cancelled while the step was running.
     *
     * @throws TaskCancelledException
     */
    public function checkpoint(): void
    {
        if (AgentTask::query()->whereKey($this->task->getKey())->value('cancel_requested_at') !== null) {
            throw new TaskCancelledException('Cancelled by the student.');
        }
    }

    /**
     * The result of a completed step this step depends on, by its plan key.
     *
     * @return array<string, mixed>|null
     */
    public function dependencyResult(string $stepKey): ?array
    {
        return $this->task->dependencies()->where('step_key', $stepKey)->first()?->result;
    }

    public function currentProfileRevision(): int
    {
        return app(MemoryReader::class)->profileRevision($this->student);
    }

    /**
     * Make one model call within the task's budget, recorded as an agent run with its usage.
     *
     * @template T
     *
     * @param  class-string  $agent
     * @param  callable(): T  $call
     * @return T
     *
     * @throws BudgetExhaustedException
     */
    public function modelCall(string $agent, callable $call, ?Handoff $handoff = null): mixed
    {
        $this->checkpoint();

        $usage = $this->task->usage ?? [];
        $max = (int) ($this->task->budget['max_model_calls'] ?? 0);

        if ((int) ($usage['model_calls'] ?? 0) >= $max) {
            throw new BudgetExhaustedException("This step's budget of {$max} model call(s) is used up.");
        }

        $resolver = app(AiModelResolver::class);

        // The provider's shared rate limit across all students' steps: wait instead of failing.
        $limitKey = 'ai-provider-calls:'.$resolver->providerFor($agent);
        $perMinute = max(1, (int) config('admitme.tasks.provider_calls_per_minute', 30));

        if (RateLimiter::tooManyAttempts($limitKey, $perMinute)) {
            $wait = max(1, RateLimiter::availableIn($limitKey));

            throw new TaskDeferredException($wait, "The AI provider's rate limit is reached; waiting {$wait}s before trying.");
        }

        RateLimiter::hit($limitKey, 60);

        $run = AgentRun::query()->create([
            'task_id' => $this->task->getKey(),
            'user_id' => $this->student->getKey(),
            'agent' => class_basename($agent),
            'provider' => $resolver->providerFor($agent),
            'model' => $resolver->modelFor($agent),
            'instruction_version' => RunInstrumentation::codeVersion($agent),
            'status' => 'running',
            'handoff' => $handoff?->toArray(),
            'started_at' => now(),
        ]);

        $usage['model_calls'] = (int) ($usage['model_calls'] ?? 0) + 1;
        $this->task->forceFill(['usage' => $usage])->save();
        $started = hrtime(true);

        try {
            $response = $call();
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => RunInstrumentation::redact(class_basename($e).': '.mb_substr($e->getMessage(), 0, 500)), 'finished_at' => now(), 'duration_ms' => RunInstrumentation::elapsedMs($started)]);

            throw $e;
        }

        $tokens = is_object($response) && isset($response->usage) && method_exists($response->usage, 'toArray') ? $response->usage->toArray() : [];
        $run->update(['status' => 'completed', 'usage' => $tokens !== [] ? $tokens : null, 'finished_at' => now(), 'duration_ms' => RunInstrumentation::elapsedMs($started)]);

        $usage['input_tokens'] = (int) ($usage['input_tokens'] ?? 0) + (int) ($tokens['input_tokens'] ?? 0);
        $usage['output_tokens'] = (int) ($usage['output_tokens'] ?? 0) + (int) ($tokens['output_tokens'] ?? 0);
        $this->task->forceFill(['usage' => $usage])->save();

        return $response;
    }
}
