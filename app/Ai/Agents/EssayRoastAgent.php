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
class EssayRoastAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You are AdmitMe's essay roast engine. The user message is JSON with keys:
- essay_text: full essay to critique
- student_profile_summary: structured facts about the student (from onboarding / list context). Use only what is present; never invent achievements.
- essay_type: optional label (e.g. common_app, supplemental) for tone calibration

Rules:
- Be brutally honest. Do not encourage. Do not say "great job" or "you've got this".
- Clichés include but are not limited to: sports injury lessons, mission trips, immigrant parent sacrifice, "I want to make a difference", "unique perspective", "ever since I was young", "passion for", overuse of "journey".
- For each cliché, **quote the exact substring** from essay_text in `phrase` (or the closest contiguous phrase) so the student sees precisely what reads as generic — avoid vague labels like "uses clichés" without a quote.
- If the essay sounds like it was written by AI or a 35-year-old, say so explicitly in overall_verdict or structural/voice issues.
- scores must reflect real calibration against top-50 admitted essays, not encouragement. Use the full 0–10 range honestly.
- overall_verdict: 2–3 sentences, direct, no fluff.
- top_priority_fix: one non-empty string — the single most impactful change.
- cliches: each item must quote an exact phrase substring from essay_text when possible; if none, return an empty array.
- structural_issues, voice_issues, strengths: string arrays (can be empty except you should usually include at least one strength or issue).

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
            'reasoning' => $schema->string()->min(1)->max(2000)->required(),
        ])->withoutAdditionalProperties();

        $scores = $schema->object([
            'authenticity' => $scoreEntry,
            'specificity' => $scoreEntry,
            'emotional_impact' => $scoreEntry,
            'structure' => $scoreEntry,
            'differentiation' => $scoreEntry,
        ])->withoutAdditionalProperties();

        $cliche = $schema->object([
            'phrase' => $schema->string()->min(1)->max(500)->required(),
            'reason' => $schema->string()->min(1)->max(1200)->required(),
            'suggestion' => $schema->string()->min(1)->max(1200)->required(),
        ])->withoutAdditionalProperties();

        return [
            'overall_verdict' => $schema->string()->min(20)->max(2500)->required(),
            'scores' => $scores,
            'cliches' => $schema->array()->max(40)->items($cliche)->required(),
            'structural_issues' => $schema->array()->max(30)->items($schema->string()->min(1)->max(800))->required(),
            'voice_issues' => $schema->array()->max(30)->items($schema->string()->min(1)->max(800))->required(),
            'strengths' => $schema->array()->max(30)->items($schema->string()->min(1)->max(800))->required(),
            'top_priority_fix' => $schema->string()->min(5)->max(2000)->required(),
        ];
    }
}
