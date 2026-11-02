<?php

namespace App\Http\Controllers;

use App\Models\OnboardingProfile;
use App\Services\Billing\ProfileResumePdfImportLimiter;
use App\Services\Profile\DetermineClarificationFields;
use App\Services\Profile\ProfileModesResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileOverviewController extends Controller
{
    public function __invoke(Request $request, DetermineClarificationFields $clarificationFields, ProfileModesResolver $modesResolver): Response
    {
        $user = $request->user();

        $row = OnboardingProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'completed']
        );

        $aiContext = is_array($row->ai_context) ? $row->ai_context : [];
        $radarRaw = data_get($aiContext, 'radar');
        $radar = [];
        if (is_array($radarRaw)) {
            foreach ($radarRaw as $key => $entry) {
                if (! is_string($key) || ! is_array($entry)) {
                    continue;
                }
                $radar[] = [
                    'key' => $key,
                    'label' => isset($entry['label']) && is_string($entry['label'])
                        ? $entry['label']
                        : self::humanizeKey($key),
                    'score' => is_numeric($entry['score'] ?? null)
                        ? (float) $entry['score']
                        : null,
                    'verdict' => isset($entry['verdict']) && is_string($entry['verdict'])
                        ? $entry['verdict']
                        : null,
                ];
            }
        }

        $majors = is_array($row->intended_majors_json) ? $row->intended_majors_json : [];
        $countries = is_array($row->target_countries_json) ? $row->target_countries_json : [];
        $openQuestions = is_array($row->open_questions_json) ? $row->open_questions_json : [];
        $dreams = is_array($row->dream_schools_json) ? $row->dream_schools_json : [];
        $regions = is_array($row->region_preferences_json) ? $row->region_preferences_json : [];

        $completionState = is_array($row->profile_completion_state) ? $row->profile_completion_state : [];
        $completedSteps = isset($completionState['completed_steps']) && is_array($completionState['completed_steps'])
            ? $completionState['completed_steps']
            : [];
        $currentStep = isset($completionState['current_step']) && is_int($completionState['current_step'])
            ? $completionState['current_step']
            : 1;

        $prefs = is_array($user->ai_preferences) ? $user->ai_preferences : [];

        $pdfImportLimiter = ProfileResumePdfImportLimiter::fromConfig();

        return Inertia::render('profile-overview', [
            'has_profile' => true,
            'completion' => [
                'current_step' => max(1, min(5, $currentStep)),
                'completed_steps' => $completedSteps,
            ],
            'clarification_fields' => $clarificationFields->forProfile($row),
            'profile_modes' => $modesResolver->resolve($row),
            'mentor_preferences' => [
                'mentor_tone' => is_string($prefs['mentor_tone'] ?? null) ? $prefs['mentor_tone'] : 'balanced',
                'weekly_focus_nudges' => (bool) ($prefs['weekly_focus_nudges'] ?? true),
                'parent_access' => (bool) ($prefs['parent_access'] ?? false),
                'mood_checkins' => (bool) ($prefs['mood_checkins'] ?? false),
            ],
            'resume_pdf_import' => [
                'unlimited' => $pdfImportLimiter->hasUnlimitedImports($user),
                'remaining' => $pdfImportLimiter->remainingForDisplay($user),
                'used' => (int) $user->profile_pdf_resume_parse_count,
                'max_per_pass' => (int) config('billing.profile_resume_pdf.max_parses_for_pass_users', 3),
            ],
            'profile' => [
                'status' => $row->status,
                'revision' => (int) $row->revision,
                'profile_dump_text' => $row->profile_dump_text,
                'resume_pdf_unreadable' => (bool) $row->resume_pdf_unreadable,
                'demographics' => [
                    'age_range' => $row->age_range,
                    'age_range_label' => self::ageRangeLabel($row->age_range),
                    'current_grade' => $row->current_grade,
                    'graduation_year' => $row->graduation_year,
                ],
                'academics' => [
                    'curriculum' => $row->curriculum,
                    'gpa_value' => $row->gpa_value !== null ? (string) $row->gpa_value : null,
                    'gpa_scale' => $row->gpa_scale !== null ? (string) $row->gpa_scale : null,
                    'gpa_type' => $row->gpa_type,
                    'gpa_normalized_4' => $row->gpa_normalized_4 !== null ? (string) $row->gpa_normalized_4 : null,
                    'sat_score' => $row->sat_score,
                    'act_score' => $row->act_score,
                    'test_optional_status' => $row->test_optional_status,
                    'citizenship_residency' => $row->citizenship_residency,
                    'location_country' => $row->location_country,
                    'location_region' => $row->location_region,
                    'grade_trend' => $row->grade_trend,
                ],
                'targets' => [
                    'intended_majors' => array_values(array_filter($majors, fn ($m) => is_string($m))),
                    'dream_schools' => array_values(array_filter($dreams, fn ($d) => is_string($d))),
                    'region_preferences' => array_values(array_filter($regions, fn ($r) => is_string($r))),
                    'campus_size_pref' => $row->campus_size_pref,
                    'financial_aid_need_percent' => $row->financial_aid_need_percent,
                    'target_countries' => array_values(array_filter($countries, fn ($c) => is_string($c))),
                    'budget_band' => $row->budget_band,
                ],
                'completeness_score' => (int) $row->profile_completeness_score,
                'completeness_callouts' => is_array($row->completeness_callouts_json)
                    ? $row->completeness_callouts_json
                    : [],
                'student_summary' => $row->student_summary,
                'spike_summary' => $row->spike_summary,
                'open_questions' => array_values(array_filter($openQuestions, fn ($q) => is_string($q))),
                'activities' => is_array($row->activities_extracted_json) ? $row->activities_extracted_json : [],
                'awards' => is_array($row->awards_extracted_json) ? $row->awards_extracted_json : [],
                'ai' => [
                    'headline' => isset($aiContext['headline']) && is_string($aiContext['headline'])
                        ? $aiContext['headline']
                        : null,
                    'excerpt' => is_string(data_get($aiContext, 'profile.excerpt'))
                        ? data_get($aiContext, 'profile.excerpt')
                        : null,
                    'signals' => self::stringList(data_get($aiContext, 'signals')),
                    'takeaways' => self::stringList(data_get($aiContext, 'takeaways')),
                    'radar' => $radar,
                    'last_structured_at' => $row->last_ai_structured_at?->toIso8601String(),
                    'last_context_at' => $row->last_ai_context_at?->toIso8601String(),
                ],
            ],
        ]);
    }

    private static function humanizeKey(string $key): string
    {
        return str($key)->replace(['_', '-'], ' ')->title()->toString();
    }

    private static function ageRangeLabel(?string $range): ?string
    {
        return match ($range) {
            '13_14' => '13–14',
            '15_16' => '15–16',
            '17_18' => '17–18',
            '19plus' => '19+',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
