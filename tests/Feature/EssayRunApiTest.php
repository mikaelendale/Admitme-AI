<?php

namespace Tests\Feature;

use App\Models\CollegeListSchool;
use App\Models\EssayRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EssayRunApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_brainstorm_run_completes_with_fake_suite_and_poll_returns_output(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Test U',
            'school_slug' => 'test-u',
            'tier' => 'match',
            'fit_score' => 75,
            'why_blurb' => 'Solid match.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $runId = (string) $this->actingAs($user)
            ->postJson(route('essay-suite.runs.store'), [
                'college_list_school_id' => $school->id,
                'run_type' => 'brainstorm',
                'essay_type' => 'common_app',
                'essay_prompt' => 'Share an essay on any topic of your choice (Common App main essay).',
                'message' => 'Emphasize CS + robotics leadership; keep tone ambitious but humble.',
                'word_limit' => 650,
                'tone' => 'balanced',
            ])
            ->assertCreated()
            ->assertJsonStructure(['run_id', 'status', 'poll_url'])
            ->json('run_id');

        $run = EssayRun::query()->find($runId);
        $this->assertNotNull($run);
        $this->assertSame('common_app', $run->meta['essay_type'] ?? null);
        $this->assertSame(EssayRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertNotNull($run->output_markdown);

        $this->actingAs($user)
            ->getJson(route('essay-suite.runs.show', ['run' => $runId]))
            ->assertOk()
            ->assertJsonPath('status', EssayRun::STATUS_COMPLETED)
            ->assertJsonPath('run_type', EssayRun::TYPE_BRAINSTORM);
    }

    public function test_cannot_start_second_run_while_first_is_active(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Other U',
            'school_slug' => 'other-u',
            'tier' => 'reach',
            'fit_score' => 60,
            'why_blurb' => 'Reach.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        EssayRun::query()->create([
            'user_id' => $user->id,
            'college_list_school_id' => $school->id,
            'run_type' => EssayRun::TYPE_DRAFT,
            'status' => EssayRun::STATUS_PROCESSING,
            'user_prompt' => 'seed',
            'meta' => [
                'essay_type' => 'supplemental',
                'essay_prompt' => 'Describe a topic that fascinates you.',
                'tone' => 'balanced',
            ],
        ]);

        $this->actingAs($user)
            ->postJson(route('essay-suite.runs.store'), [
                'college_list_school_id' => $school->id,
                'run_type' => 'brainstorm',
                'essay_type' => 'supplemental',
                'essay_prompt' => 'Why are you interested in Other U?',
                'message' => 'Should conflict.',
            ])
            ->assertStatus(409);
    }

    public function test_draft_requires_essay_prompt_field(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Prompt U',
            'school_slug' => 'prompt-u',
            'tier' => 'match',
            'fit_score' => 70,
            'why_blurb' => 'Fit.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('essay-suite.runs.store'), [
                'college_list_school_id' => $school->id,
                'run_type' => 'draft',
                'essay_type' => 'supplemental',
                'message' => str_repeat('x', 50),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['essay_prompt']);
    }

    public function test_essay_suite_inertia_includes_schools_and_runs_props(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('essay-suite'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('essay-suite')
                ->has('essay_schools')
                ->has('essay_recent_runs')
                ->has('essay_defaults'));
    }
}
