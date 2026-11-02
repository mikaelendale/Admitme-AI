<?php

namespace App\Ai\Tools;

use App\Domain\Tasks\Orchestrator;
use App\Domain\Tasks\TaskRegistry;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode only. The mentor proposes a short plan of typed steps; Laravel validates and schedules it
 * (the model never runs arbitrary instructions, and the student scope is fixed server-side).
 */
class PlanTasksTool implements Tool
{
    public function __construct(protected User $user, protected ?string $conversationId = null) {}

    public function description(): Stringable|string
    {
        return "Start background work as a short plan of typed steps. The steps run after this reply and the student watches (and can pause or cancel) them in the task panel. Use it for multi-step work — for example \"research affordable options and prepare a shortlist\" is: college_research → affordability_check (depends on it) → propose_shortlist (depends on that), plus refresh_sources on its own. Do not do long research in the chat instead.\nStep types:\n"
            .app(TaskRegistry::class)->describeAll()
            ."\nRules: at most 6 steps; give each a short lowercase key; depends_on lists keys of EARLIER steps only. Any change to the saved list comes out as a proposal the student approves — say so. After calling, tell the student briefly what will happen.";
    }

    public function handle(Request $request): Stringable|string
    {
        $goal = trim((string) ($request['goal'] ?? ''));
        $steps = array_map(fn ($step) => is_array($step) ? [
            'key' => $step['key'] ?? null,
            'type' => $step['type'] ?? null,
            'title' => $step['title'] ?? null,
            'depends_on' => $step['depends_on'] ?? [],
            'input' => array_filter([
                'criteria' => $step['criteria'] ?? null,
                'count' => $step['count'] ?? null,
                'max_items' => $step['max_items'] ?? null,
                'max_sources' => $step['max_sources'] ?? null,
                'application_id' => $step['application_id'] ?? null,
            ], fn ($value) => $value !== null),
        ] : $step, (array) ($request['steps'] ?? []));

        // The same plan sent twice in one turn (or a retried request) is created once.
        $key = $this->conversationId !== null ? 'chat:'.$this->conversationId.':'.sha1(json_encode([$goal, $steps]).now()->format('YmdHi')) : null;

        try {
            $task = app(Orchestrator::class)->plan($this->user, $goal, $steps, 'mentor', $this->conversationId, $key);
        } catch (ValidationException $e) {
            return json_encode(['status' => 'invalid_plan', 'errors' => $e->errors(), 'note' => 'Nothing was started. Fix the plan and try again.'], JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return json_encode([
            'status' => 'tasks_started',
            'goal_task_id' => (string) $task->id,
            'title' => $task->title,
            'steps' => $task->steps()->get()->map(fn ($s) => ['key' => $s->step_key, 'title' => $s->title, 'status' => $s->status->value])->all(),
            'note' => 'The steps run in the background and appear in the task panel. Saved-list changes arrive as a proposal for the student to approve; nothing is saved without that.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->description('The student\'s goal in a short sentence.')->required(),
            'steps' => $schema->array()->items($schema->object([
                'key' => $schema->string()->description('Short lowercase key, e.g. "research".')->required(),
                'type' => $schema->string()->enum(app(TaskRegistry::class)->types())->required(),
                'title' => $schema->string()->description('Short title shown to the student, or null for the default.')->nullable()->required(),
                'depends_on' => $schema->array()->items($schema->string())->description('Keys of earlier steps this one waits for.')->required(),
                'criteria' => $schema->string()->description('college_research: what to look for.')->nullable()->required(),
                'count' => $schema->integer()->description('college_research: how many to suggest (1–8).')->nullable()->required(),
                'max_items' => $schema->integer()->description('propose_shortlist: most colleges to propose (1–10).')->nullable()->required(),
                'max_sources' => $schema->integer()->description('refresh_sources: most pages to re-check (1–10).')->nullable()->required(),
                'application_id' => $schema->string()->description('application_plan: the application id from ReadApplicationTool.')->nullable()->required(),
            ])->withoutAdditionalProperties())->max(6)->required(),
        ];
    }
}
