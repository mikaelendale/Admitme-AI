<?php

namespace Tests\Unit;

use App\Ai\Tools\StartCollegeListCouncilJobTool;
use App\Jobs\RunCollegeListCouncilJob;
use App\Models\CollegeListRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class StartCollegeListCouncilJobToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_council_run_when_enabled(): void
    {
        Bus::fake([RunCollegeListCouncilJob::class]);

        config(['college_list_council.enabled' => true]);

        $user = User::factory()->create();
        $tool = new StartCollegeListCouncilJobTool($user);
        $out = json_decode((string) $tool->handle(new Request([
            'message' => 'Add five reach schools',
            'intent' => 'refine',
        ])), true);

        $this->assertTrue($out['ok']);
        $this->assertTrue($out['council_started']);
        $this->assertArrayHasKey('run_id', $out);
        $this->assertSame(1, CollegeListRun::query()->count());
        Bus::assertDispatched(RunCollegeListCouncilJob::class);
    }
}
