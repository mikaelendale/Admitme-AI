<?php

namespace App\Domain\Tasks;

use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\AgentTaskEvent;
use App\Models\Proposal;

/**
 * One shape for a goal and its steps, used by the task panel, the chat card and the mentor's tool.
 */
final class TaskPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function goal(AgentTask $goal): array
    {
        $steps = $goal->steps()->with(['dependencies:id,title', 'events' => fn ($q) => $q->latest('id')->limit(6), 'runs'])->get();
        $proposals = Proposal::query()->whereIn('id', $steps->pluck('proposal_id')->filter())->get(['id', 'status', 'title'])->keyBy('id');

        return [
            'id' => (string) $goal->id,
            'title' => $goal->title,
            'goal' => $goal->goal,
            'status' => $goal->status->value,
            'statusLabel' => $goal->status->label(),
            'statusReason' => $goal->status_reason,
            'createdBy' => $goal->created_by,
            'createdAt' => $goal->created_at?->toIso8601String(),
            'finishedAt' => $goal->finished_at?->toIso8601String(),
            // Keep polling while anything can still change without the student acting.
            'active' => $steps->contains(fn (AgentTask $s) => in_array($s->status, [TaskStatus::Queued, TaskStatus::Running], true)),
            'controls' => [
                'canCancel' => ! $goal->status->isTerminal(),
                'canPause' => $steps->contains(fn (AgentTask $s) => in_array($s->status, [TaskStatus::Queued, TaskStatus::Blocked], true)),
                'canResume' => $steps->contains(fn (AgentTask $s) => $s->status === TaskStatus::Paused),
                'canRetry' => $goal->status !== TaskStatus::Cancelled && $steps->contains(fn (AgentTask $s) => in_array($s->status, [TaskStatus::Failed, TaskStatus::Blocked], true)),
            ],
            'steps' => $steps->map(fn (AgentTask $step) => [
                'id' => (string) $step->id,
                'key' => $step->step_key,
                'type' => $step->type,
                'title' => $step->title,
                'status' => $step->status->value,
                'statusLabel' => $step->status->label(),
                'statusReason' => $step->status_reason,
                'summary' => $step->result['summary'] ?? null,
                'details' => array_filter([
                    'suggested' => $step->result['suggested'] ?? null,
                    'proposed' => $step->result['proposed'] ?? null,
                    'excludedOverBudget' => $step->result['excluded_over_budget'] ?? null,
                    'applied' => $step->result['applied'] ?? null,
                    'changed' => $step->result['changed'] ?? null,
                    'failed' => $step->result['failed'] ?? null,
                ], fn ($value) => ! empty($value)),
                'dependsOn' => $step->dependencies->pluck('title')->all(),
                'attempts' => (int) $step->attempts,
                'maxAttempts' => (int) $step->max_attempts,
                'usage' => $step->usage,
                'proposal' => $step->proposal_id !== null && ($proposal = $proposals->get($step->proposal_id)) !== null
                    ? ['id' => (string) $proposal->id, 'status' => $proposal->status->value, 'title' => $proposal->title]
                    : null,
                'startedAt' => $step->started_at?->toIso8601String(),
                'finishedAt' => $step->finished_at?->toIso8601String(),
                // Who produced the result: each model call with its agent, model and outcome.
                'runs' => $step->runs->sortBy('created_at')->values()->map(fn (AgentRun $run) => [
                    'agent' => $run->agent,
                    'model' => $run->model,
                    'status' => $run->status,
                    'tokens' => isset($run->usage['input_tokens']) ? (int) $run->usage['input_tokens'] + (int) ($run->usage['output_tokens'] ?? 0) : null,
                ])->all(),
                'events' => $step->events->reverse()->values()->map(fn (AgentTaskEvent $e) => [
                    'type' => $e->type,
                    'message' => $e->message,
                    'at' => $e->created_at?->toIso8601String(),
                ])->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Compact text-friendly version for the mentor.
     *
     * @return array<string, mixed>
     */
    public static function forMentor(AgentTask $goal): array
    {
        $full = self::goal($goal);

        return [
            'goal' => $full['title'],
            'status' => $full['statusLabel'].($full['statusReason'] ? " — {$full['statusReason']}" : ''),
            'steps' => array_map(fn (array $s) => [
                'step' => $s['title'],
                'status' => $s['statusLabel'],
                'result' => $s['summary'] ?? $s['statusReason'],
                'proposal' => $s['proposal'] !== null ? "{$s['proposal']['title']} ({$s['proposal']['status']})" : null,
            ], $full['steps']),
        ];
    }
}
