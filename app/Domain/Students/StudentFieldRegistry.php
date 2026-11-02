<?php

namespace App\Domain\Students;

use InvalidArgumentException;

/**
 * The tracked student profile fields: their stable keys, storage columns, sections and validation.
 *
 * Keys are the vocabulary used by manual edits, provenance records, memory projections and (later)
 * agent proposals. Derived values (normalised GPA, completeness, AI context) are deliberately absent:
 * they are recomputed from these fields, never edited directly.
 */
final class StudentFieldRegistry
{
    public const SECTION_ACADEMICS = 'academics';

    public const SECTION_IDENTITY = 'identity';

    public const SECTION_PREFERENCES = 'preferences';

    public const SECTION_NARRATIVE = 'narrative';

    public const SECTION_EXPERIENCE = 'experience';

    /**
     * @return array<string, array{column: string, section: string, label: string, type: string, rules: list<mixed>, item_rules?: list<mixed>}>
     */
    public static function fields(): array
    {
        return [
            'current_grade' => self::field('current_grade', self::SECTION_ACADEMICS, 'Current grade or year', 'string', ['nullable', 'string', 'max:32']),
            'graduation_year' => self::field('graduation_year', self::SECTION_ACADEMICS, 'Graduation year', 'integer', ['nullable', 'integer', 'min:2000', 'max:2100']),
            'curriculum' => self::field('curriculum', self::SECTION_ACADEMICS, 'Curriculum', 'string', ['nullable', 'string', 'max:64']),
            'gpa_value' => self::field('gpa_value', self::SECTION_ACADEMICS, 'GPA / grade average (as reported)', 'decimal', ['nullable', 'numeric', 'min:0', 'max:999.99']),
            'gpa_scale' => self::field('gpa_scale', self::SECTION_ACADEMICS, 'Grading scale maximum', 'decimal', ['nullable', 'numeric', 'min:0', 'max:999.99']),
            'gpa_type' => self::field('gpa_type', self::SECTION_ACADEMICS, 'GPA type', 'string', ['nullable', 'in:weighted,unweighted']),
            'sat_score' => self::field('sat_score', self::SECTION_ACADEMICS, 'SAT score', 'integer', ['nullable', 'integer', 'min:400', 'max:1600']),
            'act_score' => self::field('act_score', self::SECTION_ACADEMICS, 'ACT score', 'integer', ['nullable', 'integer', 'min:1', 'max:36']),
            'test_optional_status' => self::field('test_optional_status', self::SECTION_ACADEMICS, 'Test-optional plan', 'string', ['nullable', 'string', 'max:32']),
            'grade_trend' => self::field('grade_trend', self::SECTION_ACADEMICS, 'Grade trend', 'string', ['nullable', 'string', 'max:16']),

            'applicant_situation' => self::field('applicant_situation', self::SECTION_IDENTITY, 'Current situation', 'string', ['nullable', 'in:finishing_high_school,graduated,gap_year']),
            'first_generation' => self::field('first_generation', self::SECTION_IDENTITY, 'First in family to attend university', 'string', ['nullable', 'in:yes,no']),
            'citizenship_residency' => self::field('citizenship_residency', self::SECTION_IDENTITY, 'Citizenship / residency', 'string', ['nullable', 'string', 'max:64']),
            'location_country' => self::field('location_country', self::SECTION_IDENTITY, 'Country of residence', 'string', ['nullable', 'string', 'max:120']),
            'location_region' => self::field('location_region', self::SECTION_IDENTITY, 'Region / state', 'string', ['nullable', 'string', 'max:120']),

            'intended_majors' => self::field('intended_majors_json', self::SECTION_PREFERENCES, 'Intended majors', 'list', ['nullable', 'array', 'max:3'], ['nullable', 'string', 'max:120']),
            'target_countries' => self::field('target_countries_json', self::SECTION_PREFERENCES, 'Target countries', 'list', ['nullable', 'array', 'max:20'], ['nullable', 'string', 'max:120']),
            'target_intake' => self::field('target_intake', self::SECTION_PREFERENCES, 'Target intake', 'string', ['nullable', 'string', 'max:64']),
            'budget_amount' => self::field('budget_amount', self::SECTION_PREFERENCES, 'Yearly budget (amount)', 'integer', ['nullable', 'integer', 'min:0', 'max:10000000']),
            'budget_currency' => self::field('budget_currency', self::SECTION_PREFERENCES, 'Budget currency', 'currency', ['nullable', 'string', 'size:3', 'alpha']),
            'budget_band' => self::field('budget_band', self::SECTION_PREFERENCES, 'Budget band', 'string', ['nullable', 'string', 'max:64']),
            'financial_aid_need_percent' => self::field('financial_aid_need_percent', self::SECTION_PREFERENCES, 'Financial aid need (%)', 'integer', ['nullable', 'integer', 'min:0', 'max:100']),
            'campus_size_pref' => self::field('campus_size_pref', self::SECTION_PREFERENCES, 'Campus size preference', 'string', ['nullable', 'string', 'max:32']),
            'region_preferences' => self::field('region_preferences_json', self::SECTION_PREFERENCES, 'Region preferences', 'list', ['nullable', 'array', 'max:20'], ['nullable', 'string', 'max:64']),
            'dream_schools' => self::field('dream_schools_json', self::SECTION_PREFERENCES, 'Dream schools', 'list', ['nullable', 'array', 'max:5'], ['nullable', 'string', 'max:200']),

            'student_summary' => self::field('student_summary', self::SECTION_NARRATIVE, 'Student summary', 'text', ['nullable', 'string', 'max:65535']),
            'spike_summary' => self::field('spike_summary', self::SECTION_NARRATIVE, 'Spike / standout theme', 'text', ['nullable', 'string', 'max:2000']),

            'activities' => self::field('activities_extracted_json', self::SECTION_EXPERIENCE, 'Activities', 'json', ['nullable', 'array']),
            'awards' => self::field('awards_extracted_json', self::SECTION_EXPERIENCE, 'Awards', 'json', ['nullable', 'array']),
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::fields());
    }

    /**
     * @return array{column: string, section: string, label: string, type: string, rules: list<mixed>, item_rules?: list<mixed>}
     */
    public static function get(string $key): array
    {
        return self::fields()[$key] ?? throw new InvalidArgumentException("Unknown student field [{$key}].");
    }

    public static function column(string $key): string
    {
        return self::get($key)['column'];
    }

    public static function keyForColumn(string $column): ?string
    {
        foreach (self::fields() as $key => $field) {
            if ($field['column'] === $column) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function trackedColumns(): array
    {
        return array_values(array_map(fn (array $field) => $field['column'], self::fields()));
    }

    /**
     * @return list<string>
     */
    public static function keysInSection(string $section): array
    {
        return array_keys(array_filter(self::fields(), fn (array $field) => $field['section'] === $section));
    }

    /**
     * Validation rules for the given field keys, including per-item rules for list fields.
     *
     * @param  list<string>  $keys
     * @return array<string, list<mixed>>
     */
    public static function rules(array $keys): array
    {
        $rules = [];

        foreach ($keys as $key) {
            $field = self::get($key);
            $rules[$key] = $field['rules'];

            if (isset($field['item_rules'])) {
                $rules["{$key}.*"] = $field['item_rules'];
            }
        }

        return $rules;
    }

    /**
     * Normalise an incoming value into the shape stored in the column.
     */
    public static function normalize(string $key, mixed $value): mixed
    {
        return match (self::get($key)['type']) {
            'string', 'text' => is_string($value) && trim($value) !== '' ? trim($value) : null,
            'currency' => is_string($value) && trim($value) !== '' ? strtoupper(trim($value)) : null,
            'integer' => $value === null || $value === '' ? null : (int) $value,
            'decimal' => $value === null || $value === '' ? null : (string) $value,
            'list' => array_values(array_filter(
                array_map(fn (mixed $item) => is_string($item) ? trim($item) : null, is_array($value) ? $value : []),
                fn (?string $item) => $item !== null && $item !== '',
            )),
            default => $value,
        };
    }

    /**
     * @param  list<mixed>  $rules
     * @param  list<mixed>|null  $itemRules
     * @return array{column: string, section: string, label: string, type: string, rules: list<mixed>, item_rules?: list<mixed>}
     */
    private static function field(string $column, string $section, string $label, string $type, array $rules, ?array $itemRules = null): array
    {
        return array_filter([
            'column' => $column,
            'section' => $section,
            'label' => $label,
            'type' => $type,
            'rules' => $rules,
            'item_rules' => $itemRules,
        ], fn (mixed $value) => $value !== null);
    }
}
