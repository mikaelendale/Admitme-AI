<?php

namespace App\Support\GapFix;

use App\Ai\Agents\GapFixAnalysisAgent;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds the JSON envelope sent to {@see GapFixAnalysisAgent}.
 * Facts-only; mirrors key fields from GetStudentAdmissionContextTool without pulling the whole list.
 */
final class GapFixPromptBuilder
{
    /**
     * @param  Collection<int, CollegeListSchool>  $schools
     * @return array{student_profile: array<string, mixed>|null, schools: list<array<string, mixed>>}
     */
    public static function build(User $user, Collection $schools): array
    {
        $profile = $user->onboardingProfile;

        return [
            'student_profile' => $profile ? self::profileSlice($profile) : null,
            'schools' => $schools->map(fn (CollegeListSchool $s): array => self::schoolRow($s))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function profileSlice(OnboardingProfile $p): array
    {
        $aiContext = is_array($p->ai_context) ? $p->ai_context : [];

        return [
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
            'grade_trend' => $p->grade_trend,
            'spike_summary' => $p->spike_summary,
            'student_summary' => $p->student_summary,
            'intended_majors_json' => is_array($p->intended_majors_json) ? $p->intended_majors_json : [],
            'budget_band' => $p->budget_band,
            'financial_aid_need_percent' => $p->financial_aid_need_percent,
            'profile_completeness_score' => $p->profile_completeness_score,
            'ai_context' => $aiContext,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schoolRow(CollegeListSchool $s): array
    {
        return [
            'id' => (string) $s->id,
            'school_name' => $s->school_name,
            'school_slug' => $s->school_slug,
            'tier' => $s->tier,
            'fit_score' => $s->fit_score,
            'why_blurb' => $s->why_blurb,
            'acceptance_rate' => $s->acceptance_rate !== null ? (string) $s->acceptance_rate : null,
            'median_gpa' => $s->median_gpa !== null ? (string) $s->median_gpa : null,
            'median_sat' => $s->median_sat,
            'median_act' => $s->median_act,
            'ea_deadline' => $s->ea_deadline?->toDateString(),
            'ed_deadline' => $s->ed_deadline?->toDateString(),
            'rd_deadline' => $s->rd_deadline?->toDateString(),
            'aid_estimate_band' => $s->aid_estimate_band,
            'campus_size' => $s->campus_size,
            'location_city' => $s->location_city,
            'location_state' => $s->location_state,
            'notable_programs' => is_array($s->notable_programs) ? $s->notable_programs : [],
        ];
    }
}
