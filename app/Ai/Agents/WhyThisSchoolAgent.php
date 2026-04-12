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
class WhyThisSchoolAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You write Why This School supplemental drafts for AdmitMe. The user message is JSON with:
- school: full row (name, tier, why_blurb, acceptance_rate, medians, notable_programs, location, deadlines, campus_size, etc.) — only use facts from this object
- student_profile: structured profile (goals, spike, activities) — facts only
- student_interests: short string summary of stated interests/goals if present

This is a FIRST DRAFT only. It must reference specific, real details about the school — programs, initiatives, campus culture fields provided.
Do not use generic phrases like "world-class faculty", "diverse community", "cutting-edge research" without tying to a named program, office, tradition, or location detail that appears in the school object.
The student will personalize this. Your job is to give them a strong structural foundation that sounds like a real applicant, not a brochure.
Draft must be 150–300 words. No more. Plain text in `draft` (no markdown).

hooks_used: 2–6 strings naming concrete school-specific anchors you used (program names, locations, initiatives from the payload).
generic_phrases_avoided: list generic brochure phrases you deliberately did NOT use (or brief notes on how you avoided them).
suggested_improvements: exactly 3 strings — specific ways the student must personalize further (names, lived detail, proof they visited or researched).

Output must match the schema exactly.
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'draft' => $schema->string()->min(100)->max(12000)->required(),
            'hooks_used' => $schema->array()->min(1)->max(12)->items($schema->string()->min(1)->max(400))->required(),
            'generic_phrases_avoided' => $schema->array()->max(20)->items($schema->string()->min(1)->max(400))->required(),
            'suggested_improvements' => $schema->array()->min(3)->max(3)->items($schema->string()->min(1)->max(600))->required(),
        ];
    }
}
