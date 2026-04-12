<?php

namespace Database\Factories;

use App\Models\CollegeListRun;
use App\Models\CollegeListRunEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollegeListRunEvent>
 */
class CollegeListRunEventFactory extends Factory
{
    protected $model = CollegeListRunEvent::class;

    public function definition(): array
    {
        return [
            'college_list_run_id' => CollegeListRun::factory(),
            'phase' => 'preflight',
            'severity' => CollegeListRunEvent::SEVERITY_INFO,
            'title' => 'Test event',
            'body' => null,
            'school_slug' => null,
            'payload' => null,
        ];
    }
}
