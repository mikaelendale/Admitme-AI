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
 * Answers one question about one college strictly from the labelled evidence it is given.
 * Evidence is gathered server-side for the authenticated student; the agent has no tools.
 */
#[Timeout(180)]
class ResearcherAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return app(InstructionLoader::class)->load('agents/researcher.md');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->description('Short answer citing evidence labels like [R1] or [S2].')->required(),
            'claims' => $schema->array()
                ->items($schema->object([
                    'text' => $schema->string()->required(),
                    'citations' => $schema->array()->items($schema->string())->required(),
                    'status' => $schema->string()->enum(['supported', 'unresolved', 'conflicting'])->required(),
                    'cycle_label' => $schema->string()->nullable()->required(),
                ])->withoutAdditionalProperties())
                ->max(12)
                ->required(),
            'unresolved' => $schema->array()->items($schema->string())->max(10)->required(),
        ];
    }
}
