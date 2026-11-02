<?php

namespace App\Http\Controllers;

use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\Playbooks;
use App\Domain\Tasks\TaskPresenter;
use App\Models\AgentTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The task panel's API. Everything is read from and written to the task records, so a refreshed page or a
 * closed chat stream loses nothing.
 */
class AgentTasksController extends Controller
{
    public function show(Request $request, AgentTask $task, Orchestrator $orchestrator): JsonResponse
    {
        $goal = $this->ownedGoal($request, $task);

        // Opening the panel also picks up work a dead worker left behind...
        $orchestrator->recover($request->user());

        return response()->json(['goal' => TaskPresenter::goal($goal->refresh())]);
    }

    public function cancel(Request $request, AgentTask $task, Orchestrator $orchestrator): JsonResponse
    {
        $this->ownedGoal($request, $task);
        $orchestrator->cancel($task);

        return response()->json(['goal' => TaskPresenter::goal($this->ownedGoal($request, $task->refresh()))]);
    }

    public function pause(Request $request, AgentTask $task, Orchestrator $orchestrator): JsonResponse
    {
        $this->ownedGoal($request, $task);
        $orchestrator->pause($task);

        return response()->json(['goal' => TaskPresenter::goal($this->ownedGoal($request, $task->refresh()))]);
    }

    public function resume(Request $request, AgentTask $task, Orchestrator $orchestrator): JsonResponse
    {
        $this->ownedGoal($request, $task);
        $orchestrator->resume($task);

        return response()->json(['goal' => TaskPresenter::goal($this->ownedGoal($request, $task->refresh()))]);
    }

    public function retry(Request $request, AgentTask $task, Orchestrator $orchestrator): JsonResponse
    {
        $this->ownedGoal($request, $task);
        $orchestrator->retry($task);

        return response()->json(['goal' => TaskPresenter::goal($this->ownedGoal($request, $task->refresh()))]);
    }

    /**
     * Start a ready-made plan from the panel (the same orchestrator the mentor uses).
     */
    public function startPlaybook(Request $request, string $playbook, Orchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'criteria' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $plan = Playbooks::get($playbook, $validated['criteria'] ?? null);
        abort_if($plan === null, 404);

        $goal = $orchestrator->plan(
            $request->user(),
            $plan['goal'],
            $plan['steps'],
            'student',
            null,
            isset($validated['idempotency_key']) ? 'panel:'.$request->user()->getKey().':'.$validated['idempotency_key'] : null,
        );

        return response()->json(['goal' => TaskPresenter::goal($goal)], 201);
    }

    private function ownedGoal(Request $request, AgentTask $task): AgentTask
    {
        abort_unless((int) $task->user_id === (int) $request->user()->getKey(), 404);

        return $task->isGoal() ? $task : $task->parent()->firstOrFail();
    }
}
