<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class GapFixWhatIfExplainAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You are AdmitMe's admissions coach. The user message is JSON: hypothetical SAT/GPA deltas (already applied in numbers), a compact student profile slice, and selected schools with medians/tiers.

Write 2–4 short paragraphs of markdown explaining how the hypothetical change might shift perceived academic alignment vs those schools. Stay grounded in the numbers provided; no invented statistics. No guarantees of admission.
Output must match the schema (markdown in `narrative`).
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'narrative' => $schema->string()->min(1)->max(4000)->required(),
        ];
    }
}
