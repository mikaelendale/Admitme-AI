<?php

namespace App\Domain\Students;

use App\Models\OnboardingProfile;
use Illuminate\Support\Str;

/**
 * The guided, resumable profile setup: situation & intake → curriculum & grades → interests →
 * destinations → budget & aid → optional documents → review. Every field can be answered
 * "don't know / not applicable" instead of blocking the student.
 */
final class GuidedOnboarding
{
    /**
     * @var array<string, array{title: string, intro: string, keys: list<string>}>
     */
    public const SECTIONS = [
        'situation' => [
            'title' => 'Your situation',
            'intro' => 'Where you are now and when you want to start. International, domestic and first-generation students all use the same steps.',
            'keys' => ['applicant_situation', 'first_generation', 'citizenship_residency', 'location_country', 'location_region', 'graduation_year', 'target_intake'],
        ],
        'academics' => [
            'title' => 'Curriculum & grades',
            'intro' => 'Use your school\'s own grading system — AdmitMe keeps the original scale and never invents a conversion.',
            'keys' => ['curriculum', 'current_grade', 'gpa_value', 'gpa_scale', 'gpa_type', 'sat_score', 'act_score', 'test_optional_status'],
        ],
        'interests' => [
            'title' => 'Interests',
            'intro' => 'What you might study. It is fine to be unsure.',
            'keys' => ['intended_majors'],
        ],
        'destinations' => [
            'title' => 'Where you want to study',
            'intro' => 'Countries, regions and the kind of campus you prefer.',
            'keys' => ['target_countries', 'region_preferences', 'campus_size_pref'],
        ],
        'budget' => [
            'title' => 'Budget & financial aid',
            'intro' => 'What your family can pay per year, in your own currency, and how much aid you need.',
            'keys' => ['budget_amount', 'budget_currency', 'financial_aid_need_percent'],
        ],
        'documents' => [
            'title' => 'Documents (optional)',
            'intro' => 'Upload a transcript or test report and review the values read from it. You can skip this.',
            'keys' => [],
        ],
        'review' => [
            'title' => 'Review',
            'intro' => 'Check what is saved and what is still missing for the next steps.',
            'keys' => [],
        ],
    ];

    /**
     * Option labels for fields with a fixed set of answers.
     *
     * @var array<string, array<string, string>>
     */
    public const OPTIONS = [
        'applicant_situation' => [
            'finishing_high_school' => 'Finishing high school / secondary school',
            'graduated' => 'Already graduated',
            'gap_year' => 'On a gap year',
        ],
        'first_generation' => ['yes' => 'Yes', 'no' => 'No'],
        'gpa_type' => ['unweighted' => 'Unweighted', 'weighted' => 'Weighted'],
        'campus_size_pref' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
    ];

    /**
     * Required information per upcoming task. A "don't know" answer counts as answered.
     *
     * @var array<string, array{label: string, keys: list<string|list<string>>}>
     */
    public const READINESS = [
        'college_research' => [
            'label' => 'Researching colleges',
            'keys' => ['curriculum', 'intended_majors', 'target_countries', ['budget_amount', 'budget_band'], 'financial_aid_need_percent', ['graduation_year', 'target_intake']],
        ],
        'applications' => [
            'label' => 'Preparing applications',
            'keys' => ['citizenship_residency', 'location_country', 'curriculum', 'graduation_year', ['gpa_value', 'sat_score', 'act_score']],
        ],
    ];

    /**
     * @return array{completed: list<string>, current: string}
     */
    public static function state(?OnboardingProfile $profile): array
    {
        $guided = (array) (($profile?->profile_completion_state ?? [])['guided'] ?? []);
        $completed = array_values(array_intersect(array_keys(self::SECTIONS), (array) ($guided['completed'] ?? [])));
        $current = is_string($guided['current'] ?? null) && isset(self::SECTIONS[$guided['current']])
            ? $guided['current']
            : (array_values(array_diff(array_keys(self::SECTIONS), $completed))[0] ?? 'review');

        return ['completed' => $completed, 'current' => $current];
    }

    /**
     * The profile_completion_state after finishing a section (other keys are kept).
     *
     * @return array<string, mixed>
     */
    public static function stateAfter(?OnboardingProfile $profile, string $section): array
    {
        $all = (array) ($profile?->profile_completion_state ?? []);
        $completed = array_values(array_unique([...self::state($profile)['completed'], $section]));
        $sections = array_keys(self::SECTIONS);
        $next = $sections[array_search($section, $sections, true) + 1] ?? 'review';

        $all['guided'] = ['completed' => $completed, 'current' => $next];

        return $all;
    }

    /**
     * @return array{key: string, label: string, type: string, options: array<string, string>|null}
     */
    public static function field(string $key): array
    {
        $field = StudentFieldRegistry::get($key);

        return [
            'key' => $key,
            'label' => $field['label'],
            'type' => $field['type'],
            'options' => self::OPTIONS[$key] ?? null,
        ];
    }

    /**
     * @return array<string, array{label: string, ready: bool, missing: list<string>}>
     */
    public static function readiness(?OnboardingProfile $profile): array
    {
        $unknown = (array) ($profile?->unknown_fields_json ?? []);
        $answered = function (string $key) use ($profile, $unknown): bool {
            $value = $profile?->getAttribute(StudentFieldRegistry::column($key));

            return in_array($key, $unknown, true) || ($value !== null && $value !== '' && $value !== []);
        };

        $result = [];

        foreach (self::READINESS as $task => $definition) {
            $missing = [];

            foreach ($definition['keys'] as $requirement) {
                $alternatives = (array) $requirement;

                if (! collect($alternatives)->contains($answered)) {
                    $missing[] = implode(' or ', array_map(fn (string $key) => Str::lower(StudentFieldRegistry::get($key)['label']), $alternatives));
                }
            }

            $result[$task] = ['label' => $definition['label'], 'ready' => $missing === [], 'missing' => $missing];
        }

        return $result;
    }
}
