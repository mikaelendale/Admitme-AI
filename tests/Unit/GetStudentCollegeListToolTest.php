<?php

namespace Tests\Unit;

use App\Ai\Tools\GetStudentCollegeList;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class GetStudentCollegeListToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_json_for_user_rows(): void
    {
        $user = User::factory()->create();
        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Example University',
            'school_slug' => 'example-university',
            'tier' => 'match',
            'fit_score' => 72,
            'why_blurb' => 'Solid academic fit.',
            'application_status' => 'researching',
            'rd_deadline' => '2026-01-01',
            'student_notes' => 'Visit campus.',
            'student_metadata' => ['scholarships' => ['Merit A']],
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $tool = new GetStudentCollegeList($user);
        $out = (string) $tool->handle(new Request([
            'list_type' => null,
            'status' => null,
            'school_name_contains' => null,
            'limit' => null,
        ]));

        $this->assertStringContainsString('Example University', $out);
        $this->assertStringContainsString('target', $out);
        $this->assertStringContainsString('Merit A', $out);
    }

    public function test_empty_message_when_no_rows(): void
    {
        $user = User::factory()->create();
        $tool = new GetStudentCollegeList($user);
        $out = (string) $tool->handle(new Request([
            'list_type' => null,
            'status' => null,
            'school_name_contains' => null,
            'limit' => null,
        ]));

        $this->assertStringContainsString('No colleges found', $out);
    }
}
