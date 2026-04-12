<?php

namespace Tests\Unit;

use App\Ai\Tools\StartEssayRunTool;
use App\Jobs\ProcessEssayRunJob;
use App\Models\CollegeListSchool;
use App\Models\EssayRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class StartEssayRunToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_when_school_id_not_on_students_active_list(): void
    {
        $user = User::factory()->create();
        $tool = new StartEssayRunTool($user);

        $out = json_decode((string) $tool->handle(new Request([
            'college_list_school_id' => (string) Str::ulid(),
            'run_type' => 'brainstorm',
            'message' => 'Brainstorm ideas for my personal statement.',
        ])), true);

        $this->assertFalse($out['ok']);
        $this->assertFalse($out['essay_job_started'] ?? false);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame(0, EssayRun::query()->count());
    }

    public function test_rejects_removed_list_school(): void
    {
        $user = User::factory()->create();
        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Removed U',
            'school_slug' => 'removed-u',
            'tier' => 'match',
            'fit_score' => 70,
            'why_blurb' => 'Was on list.',
            'application_status' => 'not_started',
            'is_removed' => true,
            'generated_at' => now(),
        ]);

        $tool = new StartEssayRunTool($user);
        $out = json_decode((string) $tool->handle(new Request([
            'college_list_school_id' => $school->id,
            'run_type' => 'brainstorm',
            'message' => 'Angles for this school.',
        ])), true);

        $this->assertFalse($out['ok']);
        $this->assertSame(0, EssayRun::query()->count());
    }

    public function test_rejects_school_owned_by_another_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobsSchool = CollegeListSchool::query()->create([
            'user_id' => $bob->id,
            'school_name' => 'Bob State',
            'school_slug' => 'bob-state',
            'tier' => 'reach',
            'fit_score' => 55,
            'why_blurb' => 'Reach.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $tool = new StartEssayRunTool($alice);
        $out = json_decode((string) $tool->handle(new Request([
            'college_list_school_id' => $bobsSchool->id,
            'run_type' => 'brainstorm',
            'message' => 'Brainstorm for Bob State.',
        ])), true);

        $this->assertFalse($out['ok']);
        $this->assertSame(0, EssayRun::query()->count());
    }

    public function test_starts_run_when_school_on_list(): void
    {
        Bus::fake([ProcessEssayRunJob::class]);

        $user = User::factory()->create();
        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Good U',
            'school_slug' => 'good-u',
            'tier' => 'match',
            'fit_score' => 80,
            'why_blurb' => 'Fit.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $tool = new StartEssayRunTool($user);
        $out = json_decode((string) $tool->handle(new Request([
            'college_list_school_id' => $school->id,
            'run_type' => 'brainstorm',
            'message' => 'Brainstorm personal statement angles.',
        ])), true);

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['essay_job_started']);
        $this->assertSame(1, EssayRun::query()->count());
        Bus::assertDispatched(ProcessEssayRunJob::class);
    }
}
