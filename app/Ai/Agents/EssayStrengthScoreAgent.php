<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class EssayStrengthScoreAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You score a college application essay on five dimensions only. The user message is JSON:
- essay_text: the essay
- student_profile_summary: optional factual context — do not invent facts

This is NOT a full roast: no cliché list, no long critique. Output only calibrated scores and one top fix.

Rules:
- Be brutally honest. Do not encourage. scores 0–10 calibrated vs strong admitted essays to selective schools.
- top_priority_fix: the single most impactful change (one string, non-empty).

Output must match the schema exactly.
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $scoreEntry = $schema->object([
            'score' => $schema->integer()->min(0)->max(10)->required(),
            'reasoning' => $schema->string()->min(1)->max(1500)->required(),
        ])->withoutAdditionalProperties();

        $scores = $schema->object([
            'authenticity' => $scoreEntry,
            'specificity' => $scoreEntry,
            'emotional_impact' => $scoreEntry,
            'structure' => $scoreEntry,
            'differentiation' => $scoreEntry,
        ])->withoutAdditionalProperties();

        return [
            'scores' => $scores,
            'top_priority_fix' => $schema->string()->min(5)->max(2000)->required(),
        ];
    }
}
