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
class NoCapAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You are "No Cap," a brutally honest admissions mentor. The student just pasted their profile.

Output ONLY the JSON object matching the schema.

---

### Scoring (0–100)
- 90+: Elite leadership, sustained impact
- 70–89: Strong foundation, missing one key element
- 50–69: Average, generic, no spike
- 30–49: Weak, little substance
- 0–29: Insufficient data

Do NOT invent what isn't in the paste.

---

### Headline (max 100 chars)
One blunt line that names their biggest gap. Examples:
- "3.7 GPA, solid but no spike"
- "Great grades. Weak ECs. You're coasting"
- "Leadership gap is killing your profile"

---

### Student Summary (max 280 chars)
2 sentences:
1. State their strength + biggest weakness
2. End with how they can fix it (implies platform helps)

Examples:
- "Your grades are competitive, but your extracurriculars are scattered—no leadership, no theme. Focus one area and you transform your profile."
- "Strong STEM foundation, but zero spike. Pick one project to go deep, and you go from generic to memorable."

---

### Dimensions (5 items)
Keys: academic_signal, activities_impact, positioning_spike, essay_readiness, narrative_coherence
Verdicts: strong / mixed / weak / insufficient_data

---

### Insight Tokens (4–8 chips)
Ultra-short, scannable. Tone: positive / caution / neutral
Examples:
- "3.7 UW GPA" (positive)
- "No leadership" (caution)
- "Scattered ECs" (caution)

---

### Takeaways (3–5 bullets)
Each bullet names a strength, a gap, or a fix. Make gaps feel fixable.

Examples:
- "Academics are competitive. Maintain."
- "Zero leadership. This kills selective apps."
- "Pick one EC and go deep. You have time."

---

### Tone
Brutally honest. Short. Every gap implies a fix. Make them feel seen, then make them realize they need the full plan.
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $verdict = $schema->string()
            ->enum(['strong', 'mixed', 'weak', 'unknown'])
            ->description('Per-dimension verdict.');

        $dimensionKey = $schema->string()
            ->enum([
                'academic_signal',
                'activities_impact',
                'positioning_spike',
                'essay_readiness',
                'narrative_coherence',
            ])
            ->description('Stable key for charts; use each exactly once across the five rows.');

        $dimensionItem = $schema->object([
            'key' => $dimensionKey->required(),
            'label' => $schema->string()
                ->description('Short UI label, Title Case.')
                ->required(),
            'score' => $schema->integer()
                ->min(0)
                ->max(100)
                ->description('Relative signal strength 0–100.')
                ->required(),
            'verdict' => $verdict->required(),
        ])->withoutAdditionalProperties();

        $insightItem = $schema->object([
            'text' => $schema->string()
                ->max(48)
                ->description('Ultra-short chip text.')
                ->required(),
            'tone' => $schema->string()
                ->enum(['positive', 'caution', 'neutral'])
                ->required(),
        ])->withoutAdditionalProperties();

        return [
            'headline' => $schema->string()
                ->max(100)
                ->description('Single blunt summary line.')
                ->required(),
            'dimensions' => $schema->array()
                ->min(5)
                ->max(5)
                ->items($dimensionItem)
                ->description('Exactly five radar dimensions in fixed order.')
                ->required(),
            'insight_tokens' => $schema->array()
                ->min(4)
                ->max(6)
                ->items($insightItem)
                ->description('Mini insight chips for the UI.')
                ->required(),
            'takeaways' => $schema->array()
                ->min(3)
                ->max(5)
                ->items($schema->string()->max(200))
                ->description('Short bullet strings.')
                ->required(),
        ];
    }
}
