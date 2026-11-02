<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Ai\Context\InstructionLoader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Judgments and one optional suggestion for manually preparing a form. No tools; evidence comes in a
 * server-built handoff, and its output is labelled as judgment/suggestion — never as the student's data.
 */
#[Timeout(120)]
class ApplicationPlannerAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return app(InstructionLoader::class)->load('agents/application-planner.md');
    }

    public function schema(JsonSchema $schema): array
    {
        $judgment = $schema->object([
            'judgment' => $schema->string()->max(400)->required(),
            'based_on' => $schema->array()->items($schema->string())->max(8)->required(),
        ])->withoutAdditionalProperties();

        return [
            'test_plan' => $judgment->required(),
            'activity_order' => $judgment->required(),
            'additional_info' => $schema->string()->max(400)->nullable()->required(),
        ];
    }
}
