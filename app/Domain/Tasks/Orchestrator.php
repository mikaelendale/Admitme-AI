<?php

namespace App\Domain\Tasks;

use App\Domain\Approvals\ProposalStatus;
use App\Domain\Memory\MemoryReader;
use App\Jobs\RunAgentTaskJob;
use App\Models\AgentTask;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Owns task state: plans, dependency-aware dispatch within limits, leases and recovery, retries with
 * backoff, budgets, blocked/paused states, cancellation, and the link between proposals and the steps that
 * made them. Every transition is stored with an event, so nothing depends on a worker's memory or on the
 * chat stream that started the work.
 */
final class Orchestrator
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly TaskPlanValidator $validator,
        private readonly TaskEvents $events,
        private readonly MemoryReader $memory,
    ) {}

    /**
     * Create a goal and its validated steps, then start what can start. The same idempotency key returns
     * the existing goal, so a refreshed page or a retried request never creates or runs the plan twice.
     *
     * @param  list<mixed>  $steps
     *
     * @throws ValidationException
     */
    public function plan(User $student, string $goal, array $steps, string $createdBy, ?string $conversationId = null, ?string $idempotencyKey = null): AgentTask
    {
        if ($idempotencyKey !== null && ($existing = $this->byIdempotencyKey($student, $idempotencyKey)) !== null) {
            return $existing;
        }

        $validated = $this->validator->validate($goal, $steps);
        $revisions = ['profile' => $this->memory->profileRevision($student)];

        try {
            $goalTask = DB::transaction(function () use ($student, $goal, $validated, $createdBy, $conversationId, $idempotencyKey, $revisions) {
                $goalTask = AgentTask::query()->create([
                    'user_id' => $student->getKey(),
                    'conversation_id' => $conversationId,
                    'type' => AgentTask::TYPE_GOAL,
                    'title' => Str::limit(trim($goal), 200, '…'),
                    'goal' => trim($goal),
                    'status' => TaskStatus::Queued,
                    'input_revisions' => $revisions,
                    'max_attempts' => 1,
                    'created_by' => $createdBy,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->events->record($goalTask, 'planned', 'Planned '.count($validated).' step(s): '.implode(', ', array_column($validated, 'title')).'.', ['created_by' => $createdBy]);

                $byKey = [];

                foreach ($validated as $step) {
                    $budget = $this->registry->handlerClass($step['type'])::budget();

                    $task = AgentTask::query()->create([
                        'user_id' => $student->getKey(),
                        'conversation_id' => $conversationId,
                        'parent_id' => $goalTask->getKey(),
                        'step_key' => $step['key'],
                        'type' => $step['type'],
                        'title' => Str::limit($step['title'], 200, '…'),
                        'status' => TaskStatus::Queued,
                        'input' => $step['input'],
                        'input_revisions' => $revisions,
                        'max_attempts' => $budget['max_attempts'],
                        'budget' => ['max_model_calls' => $budget['max_model_calls']],
                        'usage' => ['model_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0],
                        'created_by' => $createdBy,
                    ]);

                    foreach ($step['depends_on'] as $dependency) {
                        $task->dependencies()->attach($byKey[$dependency]->getKey());
                    }

                    $byKey[$step['key']] = $task;

                    $this->events->record($task, 'queued', $step['depends_on'] === []
                        ? 'Queued.'
                        : 'Queued; starts after: '.implode(', ', array_map(fn (string $key) => $byKey[$key]->title, $step['depends_on'])).'.');
                }

                return $goalTask;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same request arrived twice at once; the first one created the plan.
            return $this->byIdempotencyKey($student, (string) $idempotencyKey) ?? throw $e;
        }

        $this->dispatchReady($student);

        return $goalTask->refresh();
    }

    /**
     * Start every step whose dependencies are complete, within the student's concurrency limit. A step is
     * claimed with a compare-and-set (queued → running), so concurrent dispatchers never start it twice.
     */
    public function dispatchReady(User $student): int
    {
        $limit = max(1, (int) config('admitme.tasks.max_running_per_student', 3));
        $globalLimit = max(1, (int) config('admitme.tasks.max_running_global', 20));
        $lease = (int) config('admitme.tasks.lease_seconds', 900);
        $dispatched = [];

        $running = AgentTask::query()->where('user_id', $student->getKey())->whereNotNull('parent_id')->where('status', TaskStatus::Running)->count();
        $runningGlobal = AgentTask::query()->whereNotNull('parent_id')->where('status', TaskStatus::Running)->count();

        $queued = AgentTask::query()
            ->where('user_id', $student->getKey())
            ->whereNotNull('parent_id')
            ->where('status', TaskStatus::Queued)
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->with('dependencies:id,status,title', 'parent:id,status')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($queued as $step) {
            if ($step->parent?->status === TaskStatus::Cancelled) {
                continue;
            }

            $stopped = $step->dependencies->first(fn (AgentTask $d) => in_array($d->status, [TaskStatus::Failed, TaskStatus::Cancelled], true));

            if ($stopped !== null) {
                $this->transition($step, TaskStatus::Queued, ['status' => TaskStatus::Blocked, 'status_reason' => "Waiting on “{$stopped->title}”, which {$stopped->status->value}. Retry that step to continue."], 'blocked');

                continue;
            }

            if ($step->dependencies->contains(fn (AgentTask $d) => $d->status !== TaskStatus::Completed)) {
                continue;
            }

            // Limits leave the step queued; it starts when a running step finishes (or on recovery).
            if ($running >= $limit || $runningGlobal >= $globalLimit) {
                break;
            }

            if ($this->transition($step, TaskStatus::Queued, ['status' => TaskStatus::Running, 'status_reason' => null, 'lease_expires_at' => now()->addSeconds($lease)], 'dispatched', 'Started.')) {
                $running++;
                $runningGlobal++;
                $dispatched[] = $step;
            }
        }

        foreach ($dispatched as $step) {
            $this->refreshGoal($step->parent()->first());
            RunAgentTaskJob::dispatch((string) $step->getKey());
        }

        return count($dispatched);
    }

    /**
     * Execute one claimed step (called by the queue job).
     */
    public function run(string $taskId): void
    {
        $task = AgentTask::query()->with('user')->find($taskId);

        if ($task === null || $task->status !== TaskStatus::Running) {
            return;
        }

        if ($task->cancel_requested_at !== null) {
            $this->finishCancelled($task, 'Cancelled before it started.');
            $this->afterStep($task);

            return;
        }

        $task->forceFill([
            'attempts' => $task->attempts + 1,
            'started_at' => $task->started_at ?? now(),
            'lease_expires_at' => now()->addSeconds((int) config('admitme.tasks.lease_seconds', 900)),
        ])->save();

        $this->events->record($task, 'started', "Attempt {$task->attempts} of {$task->max_attempts}.");

        try {
            $outcome = $this->registry->handler($task->type)->handle($task, new TaskContext($task, $task->user, $this->events));
        } catch (TaskCancelledException) {
            $this->finishCancelled($task->refresh(), 'Cancelled while running; nothing after that point was done.');
            $this->afterStep($task);

            return;
        } catch (TaskDeferredException $e) {
            // Not the step's fault (e.g. the provider rate limit): wait, without using up an attempt.
            $this->transition($task->refresh(), TaskStatus::Running, [
                'status' => TaskStatus::Queued,
                'status_reason' => $e->getMessage(),
                'attempts' => max(0, $task->attempts - 1),
                'lease_expires_at' => null,
                'available_at' => now()->addSeconds($e->seconds),
            ], 'deferred', $e->getMessage());
            $this->afterStep($task);

            return;
        } catch (BudgetExhaustedException $e) {
            $outcome = TaskOutcome::failed($e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $outcome = TaskOutcome::failed('Stopped by an error ('.class_basename($e).'): '.Str::limit($e->getMessage(), 300), true);
        }

        $this->apply($task->refresh(), $outcome);
        $this->afterStep($task);
    }

    /**
     * A proposal made by a step was decided (or went stale/expired): move the step on.
     */
    public function proposalDecided(Proposal $proposal): void
    {
        $task = $proposal->agent_task_id !== null ? AgentTask::query()->find($proposal->agent_task_id) : null;

        if ($task === null) {
            return;
        }

        if ($task->status !== TaskStatus::AwaitingApproval) {
            $this->events->record($task, 'approval_decided', "Proposal {$proposal->status->value} after this step was {$task->status->value}; the step was not changed.");

            return;
        }

        $receipt = $proposal->decision?->receipt ?? [];
        $applied = array_keys(array_filter($receipt, fn ($outcome) => $outcome === 'applied'));
        $labels = collect($proposal->items)->pluck('label', 'key');
        $names = array_map(fn ($key) => $labels[$key] ?? $key, $applied);

        [$status, $reason, $event] = match ($proposal->status) {
            ProposalStatus::Applied => [TaskStatus::Completed, null, 'You approved '.count($applied).' of '.count($proposal->items).($names !== [] ? ': '.implode(', ', $names) : '').'.'],
            ProposalStatus::Rejected => [TaskStatus::Completed, null, 'You declined the proposal; nothing was changed.'],
            ProposalStatus::Stale, ProposalStatus::Expired => [TaskStatus::Blocked, 'The proposal could not be used ('.$proposal->status->value.'). Retry this step to make a fresh one.', 'Proposal '.$proposal->status->value.'.'],
            ProposalStatus::Failed => [TaskStatus::Failed, 'Applying the approved changes failed.', 'Applying the approved changes failed.'],
            default => [null, null, null],
        };

        if ($status === null) {
            return;
        }

        $this->transition($task, TaskStatus::AwaitingApproval, [
            'status' => $status,
            'status_reason' => $reason,
            // The summary now reports the decision, not the old "waiting for your approval".
            'result' => [...($task->result ?? []), 'summary' => $event, 'decision' => $proposal->status->value, 'applied' => $names],
            'finished_at' => $status->isTerminal() ? now() : null,
        ], 'approval_decided', $event);

        $this->afterStep($task);
    }

    /**
     * The student changed something a plan depends on (budget, aid need, destinations, majors). Affordability
     * results and shortlist proposals made with the old values are no longer trustworthy: a pending proposal is
     * marked stale (so it cannot be approved by mistake) and the affordability and proposal steps run again
     * with the new values. Queued and running steps already read current data when they run.
     *
     * @param  list<string>  $changedKeys  Student field keys that changed.
     * @return int Steps sent back for re-evaluation.
     */
    public function profileChanged(User $student, array $changedKeys, int $newRevision): int
    {
        if (array_intersect($changedKeys, self::AFFORDABILITY_INPUTS) === []) {
            return 0;
        }

        $requeued = 0;
        $proposalSteps = AgentTask::query()
            ->where('user_id', $student->getKey())
            ->where('type', 'propose_shortlist')
            ->where('status', TaskStatus::AwaitingApproval)
            ->with('parent', 'dependencies')
            ->get()
            ->filter(fn (AgentTask $step) => $step->parent?->status !== TaskStatus::Cancelled && (int) ($step->result['profile_revision'] ?? 0) < $newRevision);

        foreach ($proposalSteps as $step) {
            $proposal = $step->proposal_id !== null ? Proposal::query()->find($step->proposal_id) : null;

            if ($proposal !== null && $proposal->status === ProposalStatus::Pending) {
                $proposal->update(['status' => ProposalStatus::Stale]);
            }

            $reason = 'Your '.implode(', ', array_map(fn (string $key) => str_replace('_', ' ', $key), array_values(array_intersect($changedKeys, self::AFFORDABILITY_INPUTS)))).' changed, so this shortlist is being re-checked; the earlier proposal can no longer be approved.';

            foreach ($step->dependencies->where('type', 'affordability_check') as $check) {
                if ($this->transition($check, TaskStatus::Completed, ['status' => TaskStatus::Queued, 'status_reason' => null, 'attempts' => 0, 'finished_at' => null, 'available_at' => null], 'reevaluating', $reason)) {
                    $requeued++;
                }
            }

            if ($this->transition($step, TaskStatus::AwaitingApproval, ['status' => TaskStatus::Queued, 'status_reason' => 'Re-checking with your new details.', 'proposal_id' => null, 'attempts' => 0, 'available_at' => null], 'reevaluating', $reason)) {
                $requeued++;
            }

            $this->refreshGoal($step->parent);
        }

        if ($requeued > 0) {
            $this->dispatchReady($student);
        }

        return $requeued;
    }

    /** Student fields whose change invalidates affordability results. */
    private const AFFORDABILITY_INPUTS = ['budget_amount', 'budget_currency', 'financial_aid_need_percent', 'target_countries', 'intended_majors'];

    /**
     * Cancel a goal (all its unfinished steps) or one step. Queued and waiting steps stop at once; a running
     * step stops at its next checkpoint. Nothing already done is undone, and a pending proposal stays in
     * Approvals — it changes nothing unless the student approves it.
     */
    public function cancel(AgentTask $task): AgentTask
    {
        DB::transaction(function () use ($task) {
            $targets = $task->isGoal() ? $task->steps()->get() : collect([$task]);

            foreach ($targets->reject(fn (AgentTask $t) => $t->status->isTerminal()) as $step) {
                if ($step->status === TaskStatus::Running) {
                    $step->forceFill(['cancel_requested_at' => now()])->save();
                    $this->events->record($step, 'cancel_requested', 'Cancel requested; it stops at its next checkpoint.');
                } else {
                    $note = $step->status === TaskStatus::AwaitingApproval ? ' Its proposal is still in Approvals and changes nothing unless you approve it.' : '';
                    $step->forceFill(['status' => TaskStatus::Cancelled, 'status_reason' => 'Cancelled by you.', 'finished_at' => now()])->save();
                    $this->events->record($step, 'cancelled', 'Cancelled by you.'.$note);
                }
            }

            if ($task->isGoal() && ! $task->status->isTerminal()) {
                $task->forceFill(['status' => TaskStatus::Cancelled, 'status_reason' => 'Cancelled by you.', 'cancel_requested_at' => now(), 'finished_at' => now()])->save();
                $this->events->record($task, 'cancelled', 'Cancelled by you. Steps already finished keep their results.');
            }
        });

        $this->refreshGoal($task->isGoal() ? null : $task->parent()->first());

        return $task->refresh();
    }

    /**
     * Pause: nothing new starts. A step that is already running finishes its current attempt.
     */
    public function pause(AgentTask $task): AgentTask
    {
        $targets = $task->isGoal() ? $task->steps()->get() : collect([$task]);

        foreach ($targets as $step) {
            if (in_array($step->status, [TaskStatus::Queued, TaskStatus::Blocked], true)) {
                $this->transition($step, $step->status, ['status' => TaskStatus::Paused, 'status_reason' => 'Paused by you.'], 'paused', 'Paused by you.');
            }
        }

        $this->refreshGoal($task->isGoal() ? $task : $task->parent()->first());

        return $task->refresh();
    }

    public function resume(AgentTask $task): AgentTask
    {
        $targets = $task->isGoal() ? $task->steps()->get() : collect([$task]);

        foreach ($targets->where('status', TaskStatus::Paused) as $step) {
            $this->transition($step, TaskStatus::Paused, ['status' => TaskStatus::Queued, 'status_reason' => null], 'resumed', 'Resumed by you.');
        }

        $this->dispatchReady($task->user()->firstOrFail());
        $this->refreshGoal($task->isGoal() ? $task : $task->parent()->first());

        return $task->refresh();
    }

    /**
     * Run a failed or blocked step again (and anything waiting on it). Steps of a cancelled goal stay cancelled.
     *
     * @throws ValidationException
     */
    public function retry(AgentTask $task): AgentTask
    {
        $goal = $task->isGoal() ? $task : $task->parent()->first();

        if ($goal?->status === TaskStatus::Cancelled) {
            throw ValidationException::withMessages(['task' => 'This plan was cancelled. Ask the mentor to start it again.']);
        }

        $targets = $task->isGoal() ? $task->steps()->get() : collect([$task]);

        foreach ($targets->filter(fn (AgentTask $t) => in_array($t->status, [TaskStatus::Failed, TaskStatus::Blocked], true)) as $step) {
            $this->transition($step, $step->status, [
                'status' => TaskStatus::Queued,
                'status_reason' => null,
                'attempts' => 0,
                'available_at' => null,
                'finished_at' => null,
                'cancel_requested_at' => null,
            ], 'retried', 'Queued again by you.');
        }

        // Steps that were blocked only because of this one can go again too...
        foreach ($targets as $step) {
            foreach ($step->dependents()->where('status', TaskStatus::Blocked)->get() as $dependent) {
                $this->transition($dependent, TaskStatus::Blocked, ['status' => TaskStatus::Queued, 'status_reason' => null], 'unblocked', 'Unblocked: the step it waits on is running again.');
            }
        }

        $this->dispatchReady($task->user()->firstOrFail());
        $this->refreshGoal($goal);

        return $task->refresh();
    }

    /**
     * Requeue steps whose worker stopped without finishing (expired lease), then dispatch what is ready.
     * Runs on a schedule and whenever a student opens their tasks.
     */
    public function recover(?User $student = null): int
    {
        $recovered = 0;

        $stale = AgentTask::query()
            ->when($student !== null, fn ($q) => $q->where('user_id', $student->getKey()))
            ->whereNotNull('parent_id')
            ->where('status', TaskStatus::Running)
            ->where('lease_expires_at', '<', now())
            ->get();

        foreach ($stale as $step) {
            $exhausted = $step->attempts >= $step->max_attempts;

            $changed = $this->transition($step, TaskStatus::Running, $exhausted
                ? ['status' => TaskStatus::Failed, 'status_reason' => 'Stopped before finishing on every attempt.', 'lease_expires_at' => null, 'finished_at' => now()]
                : ['status' => TaskStatus::Queued, 'status_reason' => null, 'lease_expires_at' => null, 'available_at' => null],
                $exhausted ? 'failed' : 'recovered',
                $exhausted ? 'Stopped before finishing on every attempt.' : 'The worker stopped before finishing; queued again.');

            if ($changed) {
                $recovered++;
                $this->refreshGoal($step->parent()->first());
            }
        }

        $students = $student !== null
            ? collect([$student])
            : User::query()->whereIn('id', AgentTask::query()->whereNotNull('parent_id')->where('status', TaskStatus::Queued)->distinct()->pluck('user_id'))->get();

        foreach ($students as $owner) {
            $this->dispatchReady($owner);
        }

        return $recovered;
    }

    /**
     * Derive a goal's status from its steps, recording a change as an event.
     */
    public function refreshGoal(?AgentTask $goal): void
    {
        if ($goal === null || ! $goal->isGoal() || $goal->status === TaskStatus::Cancelled) {
            return;
        }

        $steps = $goal->steps()->with('dependencies:id')->get()->keyBy('id');
        $has = fn (TaskStatus $status) => $steps->contains(fn (AgentTask $s) => $s->status === $status);
        $failed = $steps->filter(fn (AgentTask $s) => $s->status === TaskStatus::Failed);
        $blocked = $steps->first(fn (AgentTask $s) => $s->status === TaskStatus::Blocked);

        // A queued step only counts as progress if nothing it waits on (directly or not) is stuck.
        $canProgress = function (AgentTask $step) use (&$canProgress, $steps): bool {
            if ($step->status === TaskStatus::Running) {
                return true;
            }

            if ($step->status !== TaskStatus::Queued) {
                return false;
            }

            foreach ($step->dependencies as $dependency) {
                $current = $steps->get($dependency->id);

                if ($current !== null && $current->status !== TaskStatus::Completed && ! $canProgress($current)) {
                    return false;
                }
            }

            return true;
        };

        $progressing = $steps->contains(fn (AgentTask $s) => $canProgress($s));
        $unfinished = $steps->contains(fn (AgentTask $s) => ! $s->status->isTerminal());

        [$status, $reason] = match (true) {
            $progressing => [$steps->contains(fn (AgentTask $s) => $s->started_at !== null || $s->status === TaskStatus::Running) ? TaskStatus::Running : TaskStatus::Queued, null],
            $has(TaskStatus::AwaitingApproval) => [TaskStatus::AwaitingApproval, 'nothing changes until you approve the proposal.'],
            $blocked !== null => [TaskStatus::Blocked, "{$blocked->title}: {$blocked->status_reason}"],
            $has(TaskStatus::Paused) => [TaskStatus::Paused, 'Paused by you.'],
            $unfinished => [TaskStatus::Blocked, 'Some steps are waiting on steps that cannot continue.'],
            $failed->isNotEmpty() => [TaskStatus::Failed, $failed->count().' of '.$steps->count().' step(s) failed: '.$failed->pluck('title')->implode(', ').'.'],
            default => [TaskStatus::Completed, $has(TaskStatus::Cancelled) ? 'Finished; some steps were cancelled.' : null],
        };

        if ($goal->status === $status && $goal->status_reason === $reason) {
            return;
        }

        $goal->forceFill([
            'status' => $status,
            'status_reason' => $reason,
            'started_at' => $goal->started_at ?? ($status !== TaskStatus::Queued ? now() : null),
            'finished_at' => $status->isTerminal() ? now() : null,
        ])->save();

        $this->events->record($goal, 'status', "{$status->label()}".($reason ? ": {$reason}" : '.'));
    }

    private function apply(AgentTask $task, TaskOutcome $outcome): void
    {
        $result = [...$outcome->result, 'summary' => $outcome->summary];

        // The student cancelled while the step was finishing: report what it did, accurately.
        if ($task->cancel_requested_at !== null) {
            $this->transition($task, TaskStatus::Running, ['status' => TaskStatus::Cancelled, 'status_reason' => 'Cancelled by you after it had finished its work.', 'result' => $result, 'lease_expires_at' => null, 'finished_at' => now()], 'cancelled', "Cancelled after it had already finished: {$outcome->summary}");

            return;
        }

        if ($outcome->status === TaskStatus::Failed && $outcome->retryable && $task->attempts < $task->max_attempts) {
            $delay = (int) config('admitme.tasks.retry_backoff_seconds', 20) * $task->attempts;
            $this->transition($task, TaskStatus::Running, ['status' => TaskStatus::Queued, 'status_reason' => "Retrying soon: {$outcome->summary}", 'lease_expires_at' => null, 'available_at' => now()->addSeconds($delay)], 'retry_scheduled', "Will retry in {$delay}s: {$outcome->summary}");

            return;
        }

        $changes = ['status' => $outcome->status, 'result' => $result, 'lease_expires_at' => null];

        match ($outcome->status) {
            TaskStatus::Completed => $changes += ['status_reason' => null, 'finished_at' => now()],
            TaskStatus::AwaitingApproval => $changes += ['status_reason' => $outcome->summary, 'proposal_id' => $outcome->proposal?->getKey()],
            TaskStatus::Blocked => $changes += ['status_reason' => $outcome->summary],
            default => $changes += ['status_reason' => $outcome->summary, 'finished_at' => now()],
        };

        if ($outcome->proposal !== null) {
            $outcome->proposal->forceFill(['agent_task_id' => $task->getKey()])->save();
        }

        $this->transition($task, TaskStatus::Running, $changes, match ($outcome->status) {
            TaskStatus::Completed => 'completed',
            TaskStatus::AwaitingApproval => 'awaiting_approval',
            TaskStatus::Blocked => 'blocked',
            default => 'failed',
        }, $outcome->summary);
    }

    private function finishCancelled(AgentTask $task, string $message): void
    {
        $this->transition($task, TaskStatus::Running, ['status' => TaskStatus::Cancelled, 'status_reason' => $message, 'lease_expires_at' => null, 'finished_at' => now()], 'cancelled', $message);
    }

    private function afterStep(AgentTask $task): void
    {
        $this->refreshGoal($task->parent()->first());

        if (($owner = User::query()->find($task->user_id)) !== null) {
            $this->dispatchReady($owner);
        }
    }

    /**
     * Compare-and-set: change the task only if it is still in the expected state. Returns whether it changed.
     *
     * @param  array<string, mixed>  $changes
     */
    private function transition(AgentTask $task, TaskStatus $expected, array $changes, string $event, ?string $message = null): bool
    {
        $values = collect($changes)->map(fn ($value) => match (true) {
            $value instanceof TaskStatus => $value->value,
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            default => $value,
        })->all();

        $updated = AgentTask::query()
            ->whereKey($task->getKey())
            ->where('status', $expected->value)
            ->update([...$values, 'updated_at' => now()]);

        if ($updated !== 1) {
            return false;
        }

        $task->refresh();
        $this->events->record($task, $event, $message ?? ($changes['status_reason'] ?? $task->status->label()));

        return true;
    }

    private function byIdempotencyKey(User $student, string $key): ?AgentTask
    {
        return AgentTask::query()->where('user_id', $student->getKey())->where('idempotency_key', $key)->first();
    }

    /**
     * @return Collection<int, AgentTask>
     */
    public function goalsFor(User $student, int $limit = 10): Collection
    {
        return AgentTask::query()
            ->where('user_id', $student->getKey())
            ->whereNull('parent_id')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }
}
