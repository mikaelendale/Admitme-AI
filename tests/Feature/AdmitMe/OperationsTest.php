<?php

namespace Tests\Feature\AdmitMe;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Observability\QualityMetrics;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Application;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 11: operator checks. Health reports stuck work and missing configuration (exit code for
 * monitoring); metrics include integrity checks for success states the app must never report.
 */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_warns_about_stuck_steps_and_fails_without_a_provider_key(): void
    {
        Storage::fake('local');
        config(['admitme.ai.provider' => 'anthropic', 'ai.providers.anthropic.key' => 'test-key']);

        $this->artisan('admitme:health')->assertSuccessful()->expectsOutputToContain('nothing stuck');

        $student = User::factory()->create();
        $goal = AgentTask::query()->forceCreate(['user_id' => $student->id, 'type' => 'goal', 'title' => 'Research', 'status' => 'running', 'created_by' => 'mentor']);
        AgentTask::query()->forceCreate(['user_id' => $student->id, 'parent_id' => $goal->id, 'step_key' => 'a', 'type' => 'college_research', 'title' => 'Step', 'status' => 'running', 'created_by' => 'mentor', 'lease_expires_at' => now()->subMinutes(5)]);

        $this->artisan('admitme:health')->assertSuccessful()->expectsOutputToContain('running past their lease');

        config(['ai.providers.anthropic.key' => null]);
        $this->artisan('admitme:health')->assertFailed()->expectsOutputToContain('MISSING');
    }

    public function test_metrics_report_measures_and_fail_on_an_impossible_success_state(): void
    {
        $this->artisan('admitme:metrics', ['--json' => true])->assertSuccessful()->expectsOutputToContain('"integrity"');

        $student = User::factory()->create();
        $school = CollegeListSchool::factory()->for($student)->create();
        $application = Application::factory()->for($student)->create(['college_list_school_id' => $school->id]);
        // Bypass the domain service (which requires evidence) to plant the state the check must catch.
        Application::query()->whereKey($application->id)->update(['status' => ApplicationStatus::Submitted->value, 'submission_evidence_type' => null]);

        $this->artisan('admitme:metrics')->assertFailed()->expectsOutputToContain('submitted_without_evidence');
    }

    public function test_cost_is_estimated_only_from_operator_prices_never_guessed(): void
    {
        $student = User::factory()->create();
        $goal = AgentTask::query()->forceCreate(['user_id' => $student->id, 'type' => 'goal', 'title' => 'Research', 'status' => 'completed', 'created_by' => 'mentor']);
        $step = AgentTask::query()->forceCreate(['user_id' => $student->id, 'parent_id' => $goal->id, 'step_key' => 'a', 'type' => 'college_research', 'title' => 'Step', 'status' => 'completed', 'created_by' => 'mentor', 'usage' => ['model_calls' => 1, 'input_tokens' => 2_000_000, 'output_tokens' => 1_000_000]]);
        AgentRun::query()->create(['task_id' => $step->id, 'user_id' => $student->id, 'agent' => 'CollegeResearchAgent', 'model' => 'synthetic-model', 'status' => 'completed', 'usage' => ['input_tokens' => 2_000_000, 'output_tokens' => 1_000_000], 'duration_ms' => 1200]);

        $report = app(QualityMetrics::class)->report(now()->subDay());
        $this->assertNull($report['specialists']['estimated_cost_usd'], 'No prices configured: no cost.');
        $this->assertSame(1200, $report['specialists']['p50_ms']);
        $this->assertSame(3_000_000, $report['tasks']['tokens_per_completed_goal']);

        config(['admitme.pricing' => ['synthetic-model' => ['input_per_million' => 1.0, 'output_per_million' => 5.0]]]);
        $report = app(QualityMetrics::class)->report(now()->subDay());
        $this->assertSame(7.0, $report['specialists']['estimated_cost_usd']);
        $this->assertSame(7.0, $report['tasks']['cost_per_completed_goal_usd']);
        $this->assertSame(1.0, $report['tasks']['completion_rate']);
    }
}
