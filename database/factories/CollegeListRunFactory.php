<?php

namespace Database\Factories;

use App\Models\CollegeListRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollegeListRun>
 */
class CollegeListRunFactory extends Factory
{
    protected $model = CollegeListRun::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => CollegeListRun::STATUS_QUEUED,
            'intent' => 'generate',
            'request_message' => 'Build my college list',
            'error_message' => null,
            'preflight_payload' => null,
            'scout_payload' => null,
            'judge_payload' => null,
            'summary_text' => null,
            'meta' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
