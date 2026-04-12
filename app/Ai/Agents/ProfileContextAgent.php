<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Model('gpt-5-nano')]
class ProfileContextAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You build a compact JSON knowledge base for an admissions AI mentor. The student profile paste is below your user message.

Rules:
- Only facts supported by the paste (or clearly implied). Do not invent scores or schools.
- Prefer short strings. Arrays: max 5 items each where applicable.
- Output must match the schema exactly. Use null for unknown fields.
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $str = $schema->string()->nullable();
        $strList = $schema->array()->max(5)->items($schema->string()->max(200));

        $academic = $schema->object([
            'gpa' => $str->description('e.g. 3.7 UW'),
            'sat' => $schema->integer()->min(400)->max(1600)->nullable(),
            'act' => $schema->integer()->min(1)->max(36)->nullable(),
            'ap_classes' => $strList->nullable(),
            'trend' => $str,
            'strength' => $str,
        ])->withoutAdditionalProperties();

        $activities = $schema->object([
            'summary' => $str,
            'leadership' => $schema->boolean()->nullable(),
            'themes' => $strList->nullable(),
            'strongest' => $str,
        ])->withoutAdditionalProperties();

        $demographics = $schema->object([
            'grade' => $str,
            'graduation_year' => $schema->integer()->min(2024)->max(2035)->nullable(),
            'location' => $str,
            'first_gen' => $schema->boolean()->nullable(),
        ])->withoutAdditionalProperties();

        $goals = $schema->object([
            'intended_majors' => $strList->nullable(),
            'target_countries' => $strList->nullable(),
            'budget_band' => $str,
            'dream_schools' => $strList->nullable(),
            'preferences' => $str,
        ])->withoutAdditionalProperties();

        $gaps = $schema->object([
            'critical' => $strList->nullable(),
            'moderate' => $strList->nullable(),
            'minor' => $strList->nullable(),
        ])->withoutAdditionalProperties();

        $strengths = $schema->object([
            'academic' => $str,
            'character' => $str,
            'unusual' => $str,
        ])->withoutAdditionalProperties();

        return [
            'academic' => $academic->nullable(),
            'activities' => $activities->nullable(),
            'demographics' => $demographics->nullable(),
            'goals' => $goals->nullable(),
            'gaps' => $gaps->nullable(),
            'strengths' => $strengths->nullable(),
            'essay_ideas' => $strList->nullable(),
            'tone_preference' => $schema->string()->nullable(),
        ];
    }
}
