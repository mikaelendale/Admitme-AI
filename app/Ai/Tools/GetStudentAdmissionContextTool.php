<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Returns a JSON snapshot of the authenticated student: account, preferences,
 * onboarding profile, and saved college list schools (college_list_schools).
 */
class GetStudentAdmissionContextTool implements ReadOnlyTool, Tool
{
    public function __construct(
        protected User $user,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TXT'
Load the full saved context for this student from the database: account basics, AI/notification preferences, pass/subscription hints, the onboarding profile (academics, goals, summaries, ai_context, completion state), and saved college list schools (college_list_schools). Use whenever you need authoritative, up-to-date facts about who this user is — the system prompt may be partial or stale. Call early when answering personalized questions. Parameters: detail "full" (default depth) or "compact" (truncates long text fields).
TXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $detail = $request->string('detail')->toString();
        if (! in_array($detail, ['full', 'compact'], true)) {
            $detail = 'full';
        }

        $payload = $this->buildPayload($this->user, $detail);

        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{"error":"encode_failed"}';
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(User $user, string $detail): array
    {
        $compact = $detail === 'compact';

        $profile = $user->onboardingProfile;

        return [
            'detail' => $detail,
            'user' => $this->userSection($user),
            'onboarding_profile' => $profile ? $this->profileSection($profile, $compact) : null,
            'college_list_schools' => $this->collegeListSchoolsSection($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function userSection(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'onboarded' => (bool) $user->onboarded,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'ai_preferences' => is_array($user->ai_preferences) ? $user->ai_preferences : [],
            'notification_preferences' => is_array($user->notification_preferences) ? $user->notification_preferences : [],
            'platform_pass_plan' => $user->platform_pass_plan,
            'platform_pass_ends_at' => $user->platform_pass_ends_at?->toIso8601String(),
            'profile_pdf_resume_parse_count' => (int) $user->profile_pdf_resume_parse_count,
            'billing' => [
                'has_stripe_customer' => filled($user->stripe_id ?? null),
                'subscribed_default' => $this->safeSubscribed($user),
            ],
        ];
    }

    protected function safeSubscribed(User $user): bool
    {
        try {
            if (! method_exists($user, 'subscribed')) {
                return false;
            }

            return $user->subscribed();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function profileSection(OnboardingProfile $p, bool $compact): array
    {
        $dump = $p->profile_dump_text;
        if ($compact && is_string($dump) && strlen($dump) > 800) {
            $dump = substr($dump, 0, 800).'… [truncated]';
        }
        if ($compact && is_string($p->student_summary) && strlen((string) $p->student_summary) > 400) {
            $summary = substr((string) $p->student_summary, 0, 400).'… [truncated]';
        } else {
            $summary = $p->student_summary;
        }

        $aiContext = is_array($p->ai_context) ? $p->ai_context : [];
        if ($compact && $aiContext !== []) {
            $encoded = json_encode($aiContext, JSON_UNESCAPED_UNICODE) ?: '';
            if (strlen($encoded) > 6000) {
                $aiContext = ['_truncated' => true, '_preview' => substr($encoded, 0, 4000).'…'];
            }
        }

        return [
            'id' => $p->id,
            'status' => $p->status,
            'current_grade' => $p->current_grade,
            'graduation_year' => $p->graduation_year,
            'curriculum' => $p->curriculum,
            'gpa_value' => $p->gpa_value !== null ? (string) $p->gpa_value : null,
            'gpa_scale' => $p->gpa_scale !== null ? (string) $p->gpa_scale : null,
            'gpa_type' => $p->gpa_type,
            'gpa_normalized_4' => $p->gpa_normalized_4 !== null ? (string) $p->gpa_normalized_4 : null,
            'sat_score' => $p->sat_score,
            'act_score' => $p->act_score,
            'test_optional_status' => $p->test_optional_status,
            'citizenship_residency' => $p->citizenship_residency,
            'location_country' => $p->location_country,
            'location_region' => $p->location_region,
            'age_range' => $p->age_range,
            'intended_majors_json' => is_array($p->intended_majors_json) ? $p->intended_majors_json : [],
            'dream_schools_json' => is_array($p->dream_schools_json) ? $p->dream_schools_json : [],
            'region_preferences_json' => is_array($p->region_preferences_json) ? $p->region_preferences_json : [],
            'target_countries_json' => is_array($p->target_countries_json) ? $p->target_countries_json : [],
            'campus_size_pref' => $p->campus_size_pref,
            'budget_band' => $p->budget_band,
            'financial_aid_need_percent' => $p->financial_aid_need_percent,
            'grade_trend' => $p->grade_trend,
            'spike_summary' => $p->spike_summary,
            'student_summary' => $summary,
            'profile_completeness_score' => $p->profile_completeness_score,
            'profile_completion_state' => is_array($p->profile_completion_state) ? $p->profile_completion_state : [],
            'completeness_callouts_json' => is_array($p->completeness_callouts_json) ? $p->completeness_callouts_json : [],
            'open_questions_json' => is_array($p->open_questions_json) ? $p->open_questions_json : [],
            'activities_extracted_json' => is_array($p->activities_extracted_json) ? $p->activities_extracted_json : [],
            'awards_extracted_json' => is_array($p->awards_extracted_json) ? $p->awards_extracted_json : [],
            'profile_modes_json' => is_array($p->profile_modes_json) ? $p->profile_modes_json : [],
            'resume_pdf_unreadable' => (bool) $p->resume_pdf_unreadable,
            'profile_dump_text' => $dump,
            'ai_context' => $aiContext,
            'last_ai_context_at' => $p->last_ai_context_at?->toIso8601String(),
            'last_ai_structured_at' => $p->last_ai_structured_at?->toIso8601String(),
            'last_profile_extraction_at' => $p->last_profile_extraction_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function collegeListSchoolsSection(User $user): array
    {
        if (! Schema::hasTable('college_list_schools')) {
            return [];
        }

        return $user->collegeListSchools()
            ->active()
            ->orderByRaw("CASE tier WHEN 'reach' THEN 1 WHEN 'match' THEN 2 WHEN 'safety' THEN 3 ELSE 4 END")
            ->orderBy('school_name')
            ->limit(80)
            ->get()
            ->map(fn (CollegeListSchool $s) => [
                'id' => $s->id,
                'school_name' => $s->school_name,
                'school_slug' => $s->school_slug,
                'list_type' => CollegeListSchool::tierToListType($s->tier),
                'tier' => $s->tier,
                'fit_score' => $s->fit_score,
                'why_blurb' => $s->why_blurb,
                'application_status' => $s->application_status,
                'student_notes' => $s->student_notes,
                'student_metadata' => is_array($s->student_metadata) ? $s->student_metadata : [],
                'acceptance_rate' => $s->acceptance_rate !== null ? (string) $s->acceptance_rate : null,
                'median_gpa' => $s->median_gpa !== null ? (string) $s->median_gpa : null,
                'median_sat' => $s->median_sat,
                'median_act' => $s->median_act,
                'ea_deadline' => $s->ea_deadline?->toDateString(),
                'ed_deadline' => $s->ed_deadline?->toDateString(),
                'rd_deadline' => $s->rd_deadline?->toDateString(),
                'application_portal_url' => $s->application_portal_url,
                'admissions_cycle_label' => $s->admissions_cycle_label,
                'aid_estimate_band' => $s->aid_estimate_band,
                'campus_size' => $s->campus_size,
                'location_city' => $s->location_city,
                'location_state' => $s->location_state,
                'notable_programs' => is_array($s->notable_programs) ? $s->notable_programs : [],
                'generated_at' => $s->generated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'detail' => $schema->string()
                ->enum(['full', 'compact'])
                ->description('full = complete snapshot; compact = truncate long text and large JSON.')
                ->required(),
        ];
    }
}
