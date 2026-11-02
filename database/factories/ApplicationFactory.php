<?php

namespace Database\Factories;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\Application;
use App\Models\CollegeListSchool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'college_list_school_id' => CollegeListSchool::factory()->state(['choice_status' => CollegeChoiceStatus::Applying]),
            'user_id' => fn (array $attributes) => CollegeListSchool::query()->find($attributes['college_list_school_id'])?->user_id,
            'status' => ApplicationStatus::Preparing,
        ];
    }
}
