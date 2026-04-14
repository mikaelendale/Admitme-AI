<?php

namespace App\Ai\Agents;

use App\Support\AdmissionsCycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Model('gpt-5-nano')]
#[Timeout(120)]
class ContextualAuditorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        $cycle = AdmissionsCycle::primaryApplyingLabel();

        return <<<TXT
You are the Contextual Auditor for a college admissions council. You ONLY analyze the student's saved profile JSON (user message) for internal strategy — not for direct student-facing shock.

Default assumption: the student is targeting first-year undergraduate entry for admissions cycle {$cycle} unless they clearly say otherwise.

Find inconsistencies between stated goals (majors, dream schools, region) and academic signals (grades, scores, trends). Flag strategic constraints the later agents must respect.

Rules:
- Be direct but professional; no insults.
- If data is missing, note "insufficient data" rather than inventing facts.
- info_gaps: concrete follow-up questions the student should answer (or that the assistant should ask in chat) for more precise lists — e.g. exact budget ceiling, test-optional choice, citizenship, term of entry, fee waiver need. Use an empty array only if the profile is already sufficient for responsible list-building.
- Output must match the schema exactly.
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $line = $schema->string()->max(500);

        return [
            'strategic_constraints' => $schema->array()->max(12)->items($line)->required(),
            'inconsistencies' => $schema->array()->max(12)->items($line)->required(),
            'non_negotiables' => $schema->array()->max(8)->items($line)->required(),
            'auditor_insights' => $schema->array()->max(6)->items($line)
                ->description('Short lines suitable for a live progress feed (no emoji).')
                ->required(),
            'info_gaps' => $schema->array()->max(8)->items($line)
                ->description('Questions to ask the student when profile data is too thin for precise lists.')
                ->required(),
        ];
    }
}
