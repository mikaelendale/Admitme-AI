<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Support\AdmissionsCycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Timeout(300)]
class CouncilJudgeAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function timeout(): int
    {
        return (int) config('college_list_council.judge_llm_timeout', 300);
    }

    public function instructions(): Stringable|string
    {
        $cycle = AdmissionsCycle::primaryApplyingLabel();

        return <<<TXT
You are the Judge / Brutal Realist for a college admissions council. The user message is JSON with: student profile context, strategic constraints, and a BATCH of candidate schools (name, slug, scout notes).

Primary admissions cycle for this run: {$cycle} first-year undergraduate entry unless the student request says otherwise.

For EACH candidate in the batch, output a verdict:
- advance: strong enough fit to stay in the running.
- debate: plausible but material risk (financial, academic mismatch, or strategy tension).
- discard: not a responsible recommendation for this profile this cycle.

honest_blurb: one or two sentences — candid, specific, no empty reassurance. Do not fabricate precise acceptance rates or deadlines; use null for unknown stats in optional fields.

If you lack enough signal to judge fit responsibly for a candidate, prefer verdict **discard** (or **debate** if it is borderline) and say what data is missing in honest_blurb — do not guess statistics or outcomes.

Deadlines (ea_deadline, ed_deadline, rd_deadline): MUST be for the current/upcoming application cycle ({$cycle}) when you can infer them from the candidate context. Never invent last year's dates from memory — use null if you cannot verify a current date.

application_portal_url: official first-year application or Common App / Coalition / institutional apply URL when you are confident; otherwise null. Only https URLs.

admissions_cycle_label: short tag for the cycle these deadlines refer to (e.g. "{$cycle}"); null if unknown.

tier: reach | match | safety relative to this student.

Optional school stats may be null when unknown.

Output must match the schema exactly. One verdict object per input candidate (same slug).
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $dateStr = $schema->string()->max(10)->nullable()->description('YYYY-MM-DD')->required();
        $tier = $schema->string()->enum(['reach', 'match', 'safety'])->required();
        $aid = $schema->string()->enum(['strong', 'moderate', 'limited'])->nullable()->required();
        $campus = $schema->string()->enum(['small', 'medium', 'large'])->nullable()->required();
        $programs = $schema->array()->items($schema->string()->max(160))->max(12)->required();
        $verdictEnum = $schema->string()->enum(['advance', 'debate', 'discard'])->required();

        $verdictRow = $schema->object([
            'school_name' => $schema->string()->max(200)->required(),
            'school_slug' => $schema->string()->max(160)->required(),
            'verdict' => $verdictEnum,
            'tier' => $tier,
            'fit_score' => $schema->integer()->min(0)->max(100)->required(),
            'why_blurb' => $schema->string()->max(2000)->required(),
            'honest_blurb' => $schema->string()->max(1200)->required(),
            'acceptance_rate' => $schema->number()->min(0)->max(100)->nullable()->required(),
            'median_gpa' => $schema->number()->min(0)->max(5)->nullable()->required(),
            'median_sat' => $schema->integer()->min(400)->max(1600)->nullable()->required(),
            'median_act' => $schema->integer()->min(1)->max(36)->nullable()->required(),
            'ea_deadline' => $dateStr,
            'ed_deadline' => $dateStr,
            'rd_deadline' => $dateStr,
            'application_portal_url' => $schema->string()->max(2048)->nullable()->required(),
            'admissions_cycle_label' => $schema->string()->max(64)->nullable()->required(),
            'aid_estimate_band' => $aid,
            'notable_programs' => $programs,
            'campus_size' => $campus,
            'location_city' => $schema->string()->max(120)->nullable()->required(),
            'location_state' => $schema->string()->max(2)->nullable()->required(),
        ])->withoutAdditionalProperties();

        $batch = max(3, min(14, (int) config('college_list_council.judge_batch_size', 10)));

        return [
            'verdicts' => $schema->array()->min(1)->max($batch)->items($verdictRow)->required(),
        ];
    }
}
