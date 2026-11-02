<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class GapFixAnalysisAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You are AdmitMe's Gap + Fix engine. The user message is a single JSON object with keys:
- student_profile: structured onboarding profile (grades, tests, timeline, spike, ai_context, etc.). Use only facts present; never invent scores or awards.
- schools: array of selected college list rows (each includes id = college_list_school_id ULID, school_name, tier, fit_score, medians, acceptance_rate, why_blurb, deadlines, aid band). Do not invent statistics not in those rows.

Produce:
1) rejection_risks — one entry per school in `schools`, with `college_list_school_id` exactly matching that row's `id`. Each entry must have exactly three `reasons` objects: each has `text` (plain English, specific to that school vs this student) and `severity` (critical | moderate | minor) for UI tagging.
2) gaps — prioritized list for the whole student (not per school): each gap has stable `id` (slug like sat_alignment), `severity` critical|moderate|minor, short `title`, and `steps` (3–6 concrete actions with timelines or measurable outcomes where possible). Order gaps by **impact** (what would move admissions outcomes most), not alphabetically. Prefer gaps the student can still act on before relevant deadlines; deprioritize purely cosmetic issues.
3) disclaimer — short honesty line (e.g. estimates, not admission guarantees).

Honor test_optional_status: do not treat missing SAT as a critical testing gap if the student is clearly test-optional unless the school context implies otherwise.
Respect current_grade / graduation_year: timeline-aware advice (junior vs senior).
Output must match the schema exactly.
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $reasonSeverity = $schema->string()->enum(['critical', 'moderate', 'minor'])->required();
        $reasonItem = $schema->object([
            'text' => $schema->string()->min(1)->max(700)->required(),
            'severity' => $reasonSeverity,
        ])->withoutAdditionalProperties();
        $rejectionRow = $schema->object([
            'college_list_school_id' => $schema->string()->max(32)->required(),
            'reasons' => $schema->array()->min(3)->max(3)->items($reasonItem)->required(),
        ])->withoutAdditionalProperties();

        $severity = $schema->string()->enum(['critical', 'moderate', 'minor'])->required();

        $gapRow = $schema->object([
            'id' => $schema->string()->max(80)->required(),
            'severity' => $severity,
            'title' => $schema->string()->min(1)->max(220)->required(),
            'steps' => $schema->array()->min(3)->max(6)->items($schema->string()->min(1)->max(600))->required(),
        ])->withoutAdditionalProperties();

        return [
            'rejection_risks' => $schema->array()->min(1)->max(14)->items($rejectionRow)->required(),
            'gaps' => $schema->array()->min(1)->max(18)->items($gapRow)->required(),
            'disclaimer' => $schema->string()->max(1500)->nullable()->required(),
        ];
    }
}
