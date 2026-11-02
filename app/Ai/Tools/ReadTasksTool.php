<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\TaskPresenter;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The student's background tasks exactly as the task panel shows them. Read-only (available in Ask mode).
 */
class ReadTasksTool implements ReadOnlyTool, Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Read the student\'s background tasks (plans started in Agent mode or from the task panel): each plan\'s status and its steps with results, failures and any proposal waiting for approval. A step "waiting for your approval" has changed nothing yet. Starting, pausing, resuming, retrying or cancelling a plan needs Agent mode (PlanTasksTool, ManageTasksTool); in Ask mode point the student to the task panel or Agent mode.';
    }

    public function handle(Request $request): Stringable|string
    {
        $goals = app(Orchestrator::class)->goalsFor($this->user, 5);

        return json_encode([
            'tasks' => $goals->map(fn ($goal) => TaskPresenter::forMentor($goal))->values()->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
