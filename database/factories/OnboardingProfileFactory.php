<?php

namespace Database\Factories;

use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic student profiles only — never copy real student data into fixtures.
 *
 * @extends Factory<OnboardingProfile>
 */
class OnboardingProfileFactory extends Factory
{
    protected $model = OnboardingProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => 'completed',
            'age_range' => '17_18',
            'is_age_13_or_older_confirmed' => true,
            'current_grade' => '12',
            'graduation_year' => 2027,
            'curriculum' => 'US high school',
            'gpa_value' => '3.70',
            'gpa_scale' => '4.00',
            'gpa_type' => 'unweighted',
            'sat_score' => 1420,
            'intended_majors_json' => ['Computer Science'],
            'target_countries_json' => ['United States'],
            'budget_band' => 'moderate',
            'financial_aid_need_percent' => 60,
            'location_country' => 'United States',
        ];
    }

    /**
     * An international A-level student applying to the US and the UK.
     */
    public function internationalALevel(): static
    {
        return $this->state(fn () => [
            'curriculum' => 'A-Levels',
            'current_grade' => 'Year 13',
            'gpa_value' => null,
            'gpa_scale' => null,
            'gpa_type' => null,
            'sat_score' => null,
            'test_optional_status' => 'test_optional',
            'citizenship_residency' => 'Nigerian citizen',
            'location_country' => 'Nigeria',
            'location_region' => 'Lagos',
            'intended_majors_json' => ['Economics', 'Mathematics'],
            'target_countries_json' => ['United States', 'United Kingdom'],
            'financial_aid_need_percent' => 90,
        ]);
    }
}
