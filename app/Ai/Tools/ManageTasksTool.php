<?php

namespace App\Ai\Tools;

use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\TaskPresenter;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentTask;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: the task panel's pause / resume / retry / cancel buttons for the student's own background plans,
 * through the same Orchestrator methods and availability rules the panel uses. Only on the student's request;
 * a cancel needs their explicit confirmation because it cannot be undone.
 */
class ManageTasksTool implements Tool
{
    /** @var list<string> */
    public const ACTIONS = ['pause', 'resume', 'retry', 'cancel'];

    /** Plans searched when one is named by title (ReadTasksTool shows the newest five). */
    private const SEARCH_LIMIT = 20;

    public function __construct(
        protected User $student,
        protected ?string $conversationId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Pause, resume, retry or cancel one of the student\'s background plans (as ReadTasksTool shows them) — the same as the buttons in their task panel. Use it ONLY when the student asked for it. '
            .'pause: steps that have not started wait (a step already running finishes its current attempt); resume: paused steps continue; retry: failed or blocked steps run again (fix what blocked them first, e.g. a missing budget); '
            .'cancel: stops the plan for good and CANNOT be undone (finished steps keep their results; a proposal it made stays in Approvals). Before cancelling, ask the student to confirm in plain words, and call with confirmed: true only after they say yes. '
            .'Name the plan by its title, or by the id PlanTasksTool returned. Afterwards tell the student the plan\'s new status.';
    }

    public function handle(Request $request): Stringable|string
    {
        $action = is_string($request['action'] ?? null) ? strtolower(trim($request['action'])) : '';
        $name = is_string($request['task'] ?? null) ? trim($request['task']) : '';
        $confirmed = filter_var($request['confirmed'] ?? false, FILTER_VALIDATE_BOOL);

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->result('not_changed', 'action must be one of: '.implode(', ', self::ACTIONS).'. Nothing was changed.');
        }

        $goal = $this->goal($name);

        if (! $goal instanceof AgentTask) {
            return $this->result('not_found', (is_array($goal)
                ? "\"{$name}\" matches several plans (".implode('; ', $goal).'). Use the exact title.'
                : "\"{$name}\" is not one of the student's plans.").' Nothing was changed.', ['plans' => $this->plans()]);
        }

        $controls = TaskPresenter::goal($goal)['controls'];

        $unavailable = match ($action) {
            'pause' => $controls['canPause'] ? null : 'Nothing to pause: no step of this plan is waiting to start (a step already running finishes its current attempt; only cancelling stops it).',
            'resume' => $controls['canResume'] ? null : 'Nothing to resume: no step of this plan is paused.',
            'retry' => $controls['canRetry'] ? null : ($goal->status === TaskStatus::Cancelled
                ? 'This plan was cancelled, so it cannot be retried. Start a new plan instead.'
                : 'Nothing to retry: no step of this plan failed or is blocked.'),
            'cancel' => $controls['canCancel'] ? null : "This plan is already {$goal->status->label()}; there is nothing to cancel.",
        };

        if ($unavailable !== null) {
            return $this->result('not_changed', $unavailable, $this->view($goal));
        }

        if ($action === 'cancel' && ! $confirmed) {
            return $this->result('confirmation_needed', "Cancelling cannot be undone. Ask the student to confirm they want to cancel \"{$goal->title}\", then call again with confirmed: true. Nothing was changed.", $this->view($goal));
        }

        $orchestrator = app(Orchestrator::class);

        try {
            match ($action) {
                'pause' => $orchestrator->pause($goal),
                'resume' => $orchestrator->resume($goal),
                'retry' => $orchestrator->retry($goal),
                'cancel' => $orchestrator->cancel($goal),
            };
        } catch (ValidationException $e) {
            return $this->result('not_changed', implode(' ', array_merge(...array_values($e->errors()))), $this->view($goal->refresh()));
        }

        return $this->result('task_updated', match ($action) {
            'pause' => 'Paused: steps that had not started wait until the student resumes them; a step already running finishes its current attempt.',
            'resume' => 'Resumed: the paused steps are queued again and start when they can.',
            'retry' => 'Queued again: the failed or blocked steps run again.',
            'cancel' => 'Cancelled for good. Finished steps keep their results; a proposal it made stays in Approvals until the student decides.',
        }, ['action' => $action, ...$this->view($goal->refresh())]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(self::ACTIONS)
                ->description('pause = steps not yet started wait; resume = paused steps continue; retry = failed or blocked steps run again; cancel = stop the plan for good (cannot be undone).')
                ->required(),
            'task' => $schema->string()->description('The plan\'s title as ReadTasksTool shows it, or the id PlanTasksTool returned.')->required(),
            'confirmed' => $schema->boolean()
                ->description('cancel only: true once the student has confirmed they want it cancelled. Null otherwise.')
                ->nullable()
                ->required(),
        ];
    }

    /**
     * The student's plan named by id (a step's id finds its plan) or by title — exact first, then partial;
     * among several, the one still in progress, then the newest.
     *
     * @return AgentTask|list<string>|null A plan, the titles of several different matching plans, or null.
     */
    private function goal(string $name): AgentTask|array|null
    {
        if ($name === '') {
            return null;
        }

        $byId = Str::isUlid($name) ? AgentTask::query()->where('user_id', $this->student->getKey())->whereKey($name)->first() : null;

        if ($byId !== null) {
            return $byId->isGoal() ? $byId : $byId->parent()->where('user_id', $this->student->getKey())->first();
        }

        $goals = app(Orchestrator::class)->goalsFor($this->student, self::SEARCH_LIMIT);
        $needle = mb_strtolower($name);

        $matches = $goals->filter(fn (AgentTask $goal) => mb_strtolower(trim((string) $goal->title)) === $needle);

        if ($matches->isEmpty()) {
            $matches = $goals->filter(fn (AgentTask $goal) => str_contains(mb_strtolower((string) $goal->title), $needle) || str_contains(mb_strtolower((string) $goal->goal), $needle));
        }

        $open = $matches->reject(fn (AgentTask $goal) => $goal->status->isTerminal());
        $candidates = $open->isNotEmpty() ? $open : $matches;

        if ($candidates->pluck('title')->unique()->count() > 1) {
            return $candidates->map(fn (AgentTask $goal) => "{$goal->title} ({$goal->status->label()})")->values()->all();
        }

        // Newest first, as the task panel lists them...
        return $candidates->first();
    }

    /**
     * @return list<string>
     */
    private function plans(): array
    {
        return app(Orchestrator::class)->goalsFor($this->student, 10)
            ->map(fn (AgentTask $goal) => "{$goal->title} ({$goal->status->label()})")
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function view(AgentTask $goal): array
    {
        $view = TaskPresenter::forMentor($goal);

        // goal_task_id lets the chat show the plan's live card under the reply (never shown to the student as text).
        return ['plan' => $view['goal'], 'goal_task_id' => (string) $goal->getKey(), 'plan_status' => $view['status'], 'steps' => $view['steps']];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function result(string $status, string $message, array $extra = []): string
    {
        return json_encode(['status' => $status, ...$extra, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
