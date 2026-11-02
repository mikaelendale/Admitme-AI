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
 * Comments on the student's own draft, anchored to verbatim quotes. No tools, no rewrites.
 */
#[Timeout(120)]
class WritingCoachAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return app(InstructionLoader::class)->load('agents/writing-coach.md');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->max(800)->required(),
            'comments' => $schema->array()->items($schema->object([
                'quote' => $schema->string()->max(400)->required(),
                'comment' => $schema->string()->max(600)->required(),
                'kind' => $schema->string()->enum(['structure', 'clarity', 'specificity', 'voice', 'prompt_fit', 'length', 'language'])->required(),
                'severity' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
                'fix' => $schema->string()->max(160)->nullable()->required(),
            ])->withoutAdditionalProperties())->max(15)->required(),
        ];
    }
}
