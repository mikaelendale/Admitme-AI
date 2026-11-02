<?php

namespace Database\Factories;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Synthetic college choices. Names are generic placeholders, not real admissions data.
 *
 * @extends Factory<CollegeListSchool>
 */
class CollegeListSchoolFactory extends Factory
{
    protected $model = CollegeListSchool::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Synthetic '.fake()->unique()->lastName().' College';

        return [
            'user_id' => User::factory(),
            'school_name' => $name,
            'school_slug' => Str::slug($name),
            'tier' => fake()->randomElement(['reach', 'match', 'safety']),
            'fit_score' => fake()->numberBetween(40, 90),
            'why_blurb' => 'Synthetic fixture.',
            'application_status' => 'not_started',
            'choice_status' => CollegeChoiceStatus::Shortlisted,
            'is_removed' => false,
            'generated_at' => now(),
        ];
    }

    public function suggested(): static
    {
        return $this->state(fn () => ['choice_status' => CollegeChoiceStatus::Suggested]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['choice_status' => CollegeChoiceStatus::Archived, 'is_removed' => true]);
    }
}
