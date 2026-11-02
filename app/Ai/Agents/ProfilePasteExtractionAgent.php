<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class ProfilePasteExtractionAgent implements Agent, HasStructuredOutput
{
    use Promptable, UsesConfiguredModel;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
You extract structured admissions profile data from a student paste. Output ONLY JSON matching the schema.

Rules:
- Do NOT invent schools, scores, or awards not hinted at in the text.
- If unknown, use null or empty arrays and set field_confidence to "missing" or "low".
- Guess grade level from context (9th–12th, gap year) when possible.
- Per-field confidence: high | medium | low | missing | ambiguous.
- early_student: true if grade is 9th or 10th (or clearly underclass).
- international_candidate: true if non-US curriculum, citizenship, or location outside US is clear.
- spike_hypothesis: one short phrase if a clear theme exists, else null.
- dream_schools_guess: school names explicitly mentioned as targets (max 5), else empty.
- region_preferences_guess: US regions or "Outside US" if stated, else empty.
- campus_size_pref_guess: small | medium | large | unknown from any stated preference.
- financial_aid_need_percent_guess: 0–100 only if need is clearly implied, else null.
TXT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $conf = $schema->string()
            ->enum(['high', 'medium', 'low', 'missing', 'ambiguous']);

        $fieldConfidence = $schema->object([
            'grade_level' => $conf->required(),
            'gpa' => $conf->required(),
            'curriculum' => $conf->required(),
            'sat_act' => $conf->required(),
            'test_optional' => $conf->required(),
            'activities' => $conf->required(),
            'awards' => $conf->required(),
            'citizenship' => $conf->required(),
            'location' => $conf->required(),
            'dream_schools' => $conf->required(),
            'regions' => $conf->required(),
        ])->withoutAdditionalProperties();

        $longList = $schema->array()->items($schema->string()->max(400))->max(24)->required();
        $majorList = $schema->array()->items($schema->string()->max(120))->max(8)->required();
        $dreamSchoolsList = $schema->array()->items($schema->string()->max(200))->max(5)->required();
        $regionsList = $schema->array()->items($schema->string()->max(64))->max(8)->required();

        /*
         * OpenAI json_schema with strict: true requires every key in `properties` to appear in `required`.
         * Nullable fields still use ->required() so the key is always present (value may be null).
         */
        return [
            'grade_level_guess' => $schema->string()->max(32)->description('e.g. 11th, 12th, Gap year')->required(),
            'gpa_numeric' => $schema->string()->max(16)->nullable()->description('Numeric GPA as string if parseable')->required(),
            'gpa_scale_guess' => $schema->string()->max(16)->nullable()->required(),
            'gpa_weighted_guess' => $schema->string()->enum(['weighted', 'unweighted', 'unknown'])->required(),
            'grading_system_guess' => $schema->string()->max(64)->nullable()->required(),
            'sat_score' => $schema->integer()->min(400)->max(1600)->nullable()->required(),
            'act_score' => $schema->integer()->min(1)->max(36)->nullable()->required(),
            'test_optional_guess' => $schema->string()->enum(['yes_have_scores', 'test_optional', 'not_decided', 'unknown'])->required(),
            'intended_majors' => $majorList,
            'activities' => $longList,
            'awards' => $longList,
            'goals_summary' => $schema->string()->max(600)->nullable()->required(),
            'family_context_summary' => $schema->string()->max(400)->nullable()->required(),
            'location_country_guess' => $schema->string()->max(120)->nullable()->required(),
            'location_region_guess' => $schema->string()->max(120)->nullable()->required(),
            'citizenship_guess' => $schema->string()->enum(['us_citizen', 'permanent_resident', 'international', 'unknown'])->required(),
            'grade_trend_guess' => $schema->string()->enum(['up', 'flat', 'down', 'unknown'])->required(),
            'early_student' => $schema->boolean()->required(),
            'international_candidate' => $schema->boolean()->required(),
            'spike_hypothesis' => $schema->string()->max(200)->nullable()->required(),
            'dream_schools_guess' => $dreamSchoolsList,
            'region_preferences_guess' => $regionsList,
            'campus_size_pref_guess' => $schema->string()->enum(['small', 'medium', 'large', 'unknown'])->required(),
            'financial_aid_need_percent_guess' => $schema->integer()->min(0)->max(100)->nullable()->required(),
            'field_confidence' => $fieldConfidence->required(),
        ];
    }
}
