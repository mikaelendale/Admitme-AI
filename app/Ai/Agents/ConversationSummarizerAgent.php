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
 * Produces a derived, attributed summary of older conversation turns. Has no tools.
 */
#[Timeout(120)]
class ConversationSummarizerAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return app(InstructionLoader::class)->load('agents/summarizer.md');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->object([
                    'text' => $schema->string()->min(1)->max(500)->required(),
                    'message_ids' => $schema->array()->items($schema->string())->required(),
                ])->withoutAdditionalProperties())
                ->max(12)
                ->required(),
        ];
    }
}
