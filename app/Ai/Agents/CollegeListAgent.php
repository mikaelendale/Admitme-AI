<?php

namespace App\Ai\Agents;

use App\Ai\Tools\AllowedDomainHttpFetchTool;
use App\Ai\Tools\GetStudentAdmissionContextTool;
use App\Models\User;
use App\Support\AdmissionsCycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Model('gpt-5-nano')]
#[MaxSteps(16)]
class CollegeListAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(
        public User $user,
        public string $intent,
    ) {}

    public function instructions(): Stringable|string
    {
        $profile = $this->user->onboardingProfile;
        $profilePayload = [
            'gpa_normalized_4' => $profile?->gpa_normalized_4 !== null ? (string) $profile->gpa_normalized_4 : null,
            'gpa_value' => $profile?->gpa_value !== null ? (string) $profile->gpa_value : null,
            'gpa_scale' => $profile?->gpa_scale !== null ? (string) $profile->gpa_scale : null,
            'sat_score' => $profile?->sat_score,
            'act_score' => $profile?->act_score,
            'intended_majors' => is_array($profile?->intended_majors_json) ? $profile->intended_majors_json : [],
            'budget_band' => $profile?->budget_band,
            'region_preferences' => is_array($profile?->region_preferences_json) ? $profile->region_preferences_json : [],
            'current_grade' => $profile?->current_grade,
            'dream_schools' => is_array($profile?->dream_schools_json) ? $profile->dream_schools_json : [],
            'ai_context' => is_array($profile?->ai_context) ? $profile->ai_context : [],
        ];

        $profileJson = json_encode(
            $profilePayload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';

        $existing = [];
        if (Schema::hasTable('college_list_schools')) {
            $existing = $this->user->collegeListSchools()
                ->active()
                ->orderBy('school_name')
                ->get()
                ->map(fn ($s) => [
                    'school_name' => $s->school_name,
                    'school_slug' => $s->school_slug,
                    'tier' => $s->tier,
                    'fit_score' => $s->fit_score,
                    'ea_deadline' => $s->ea_deadline?->toDateString(),
                    'ed_deadline' => $s->ed_deadline?->toDateString(),
                    'rd_deadline' => $s->rd_deadline?->toDateString(),
                    'application_portal_url' => $s->application_portal_url,
                    'admissions_cycle_label' => $s->admissions_cycle_label,
                ])
                ->values()
                ->all();
        }

        $existingJson = json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';

        $intentRules = match ($this->intent) {
            'generate' => 'Intent: GENERATE a fresh list. Return action "generate" and 8–12 schools. Include a healthy mix of reach, match, and safety for this student. You will replace their entire saved list.',
            'add' => 'Intent: ADD schools. Return action "refine" with only new or updated rows in schools, plus empty removed_slugs unless removing.',
            'remove' => 'Intent: REMOVE schools. Return action "refine" with removed_slugs for schools to drop; schools array may be empty or only rows that changed.',
            default => 'Intent: REFINE the list (swap, rebalance, adjust). Return action "refine" with minimal diff: changed rows and removed_slugs only.',
        };

        $cycle = AdmissionsCycle::primaryApplyingLabel();

        return <<<TXT
You are an expert college admissions advisor helping a student curate a realistic application list.

Default admissions cycle for deadlines and policy: {$cycle} first-year undergraduate entry unless the student message clearly specifies another term.

Student profile (JSON — ground truth; do not invent facts beyond reasonable estimates labeled as estimates in stats):
```json
{$profileJson}
```

Existing saved college list (JSON):
```json
{$existingJson}
```

Detected user intent for this turn: "{$this->intent}".
{$intentRules}

Tools:
- GetStudentAdmissionContext: call with detail "full" if you need the latest saved user + profile + college list schools. The JSON blocks above were fixed at the start of the run and may be stale.
- Web search: find up-to-date, reputable sources when unsure about deadlines or stats; prefer pages that state the application cycle or year.
- Web fetch: read specific official pages when you have a URL (e.g. admissions sites). Prefer .edu and established nonprofit or government college resources.

Output rules:
- Your final turn must be ONLY JSON matching the provided schema. No markdown, no preamble, no code fences in your output fields.
- chat_response: 2–4 sentences for the student (warm, clear, actionable). If critical facts are still missing for a responsible list, briefly name what you still need them to clarify (budget, tests, citizenship, term, etc.); otherwise summarize what you did.
- school_slug: lowercase, hyphenated ASCII (e.g. "university-of-michigan").
- tier must be reach, match, or safety relative to this student's profile.
- fit_score: integer 0–100 for how strong a fit this school is for the student.
- why_blurb: 2–3 sentences, specific to this student (not generic marketing).
- Dates: YYYY-MM-DD or null for THIS cycle ({$cycle}) only — never invent prior-year deadlines; use null if you cannot verify a current date.
- application_portal_url: https URL for the primary first-year application (institutional, Common App, or Coalition) when known; null if unknown.
- admissions_cycle_label: short label for the cycle these deadlines refer to (e.g. "{$cycle}"); null if unclear.
- Stats (acceptance_rate, medians) may be null if unknown — never fabricate precise official numbers.
- notable_programs: short program names or empty array.
- For refine/add/remove: keep schools array small (only touched rows). Populate removed_slugs with slugs to soft-delete from the existing list.
TXT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            new GetStudentAdmissionContextTool($this->user),
            (new WebSearch)->max(5),
            new AllowedDomainHttpFetchTool([
                'collegeboard.org',
                'commonapp.org',
                'nces.ed.gov',
            ], 3),
        ];
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

        $school = $schema->object([
            'school_name' => $schema->string()->max(200)->required(),
            'school_slug' => $schema->string()->max(160)->required(),
            'tier' => $tier,
            'fit_score' => $schema->integer()->min(0)->max(100)->required(),
            'why_blurb' => $schema->string()->max(2000)->required(),
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

        return [
            'action' => $schema->string()->enum(['generate', 'refine'])->required(),
            'chat_response' => $schema->string()->max(4000)->required(),
            'schools' => $schema->array()->items($school)->max(14)->required(),
            'removed_slugs' => $schema->array()->items($schema->string()->max(160))->max(24)->required(),
        ];
    }
}
