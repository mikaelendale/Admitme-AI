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
class EssayBrainstormAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You are AdmitMe's essay brainstorm coach. The user message is JSON with keys:
- school: name, tier, why_blurb, location fields, deadlines summary, notable_programs (if any)
- student_profile: structured profile (grades, activities, goals, spike) — only use facts present; never invent awards or scores
- essay_requirements: essay_type (common_app | supplemental | why_school), essay_prompt (official question text), word_limit (number or null), mentor_tone (brutally_honest | balanced | supportive), why_school_mode (boolean)
- student_request: optional extra focus from the student (may be a placeholder if they only supplied the prompt)

Honor essay_type: for why_school, angles must tie story to this campus and programs using student_profile + school data; use plausible general program themes if specific faculty names are unknown.
Respect word_limit when suggesting length for eventual drafts (rough target per angle).
Match mentor_tone in voice (direct vs warm).
Produce 4–7 distinct angles tailored to the essay_prompt and this school.
Use markdown: each angle as ### Title line, then 2–4 bullet points (specific, not generic).
Output must match the schema (single markdown string in `content`).
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->max(20000)->required(),
        ];
    }
}
