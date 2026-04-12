<?php

namespace Tests\Feature;

use App\Models\CollegeListSchool;
use App\Models\SupplementalEssay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EssaySuiteExtensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_post_roast(): void
    {
        $this->postJson('/essay-suite/roast', [
            'essay_text' => str_repeat('word ', 30),
        ])->assertUnauthorized();
    }

    public function test_onboarded_user_can_roast_with_fake_suite(): void
    {
        Config::set('essay_suite.fake', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $text = str_repeat('This is a sentence with enough characters for the roast endpoint. ', 5);

        $this->actingAs($user)
            ->postJson('/essay-suite/roast', ['essay_text' => $text])
            ->assertCreated()
            ->assertJsonStructure(['id', 'payload' => [
                'overall_verdict',
                'scores',
                'cliches',
                'structural_issues',
                'voice_issues',
                'strengths',
                'top_priority_fix',
            ]]);
    }

    public function test_roast_validation_rejects_short_essay(): void
    {
        Config::set('essay_suite.fake', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/essay-suite/roast', ['essay_text' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['essay_text']);
    }

    public function test_roast_returns_429_when_rate_limited(): void
    {
        Config::set('essay_suite.fake', true);
        Config::set('essay_suite.roast_per_user_per_hour', 2);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $text = str_repeat('Enough text for roast validation minimum length here. ', 4);

        $this->actingAs($user)->postJson('/essay-suite/roast', ['essay_text' => $text])->assertCreated();
        $this->actingAs($user)->postJson('/essay-suite/roast', ['essay_text' => $text])->assertCreated();
        $this->actingAs($user)->postJson('/essay-suite/roast', ['essay_text' => $text])->assertStatus(429);
    }

    public function test_score_only_with_fake(): void
    {
        Config::set('essay_suite.fake', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $text = str_repeat('Enough text for score only validation minimum length here. ', 4);

        $this->actingAs($user)
            ->postJson('/essay-suite/score-only', ['essay_text' => $text])
            ->assertCreated()
            ->assertJsonStructure(['payload' => ['scores', 'top_priority_fix']]);
    }

    public function test_why_this_school_with_fake(): void
    {
        Config::set('essay_suite.fake', true);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Roast Test U',
            'school_slug' => 'roast-test-u',
            'tier' => 'match',
            'fit_score' => 70,
            'why_blurb' => 'Solid fit.',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/essay-suite/why-this-school', [
                'college_list_school_id' => (string) $school->id,
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'payload' => [
                'draft',
                'hooks_used',
                'generic_phrases_avoided',
                'suggested_improvements',
            ]]);
    }

    public function test_supplement_crud(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $school = CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Supp U',
            'school_slug' => 'supp-u',
            'tier' => 'safety',
            'fit_score' => 80,
            'why_blurb' => 'Good.',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $due = now()->addDays(10)->toDateString();

        $this->actingAs($user)
            ->postJson('/essay-suite/supplements', [
                'school_name' => 'Supp U',
                'college_list_school_id' => (string) $school->id,
                'prompt_text' => 'Describe a community you belong to.',
                'word_limit' => 250,
                'essay_type' => SupplementalEssay::TYPE_COMMUNITY,
                'status' => SupplementalEssay::STATUS_NOT_STARTED,
                'due_date' => $due,
            ])
            ->assertCreated();

        $row = SupplementalEssay::query()->first();
        $this->assertNotNull($row);

        $this->actingAs($user)
            ->patchJson('/essay-suite/supplements/'.$row->id, [
                'content' => str_repeat('word ', 120),
                'status' => SupplementalEssay::STATUS_DRAFTED,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->delete('/essay-suite/supplements/'.$row->id)
            ->assertNoContent();

        $this->assertSoftDeleted('supplemental_essays', ['id' => $row->id]);
    }

    public function test_supplements_index_requires_auth(): void
    {
        $this->get('/essay-suite/supplements')->assertRedirect();
    }

    public function test_onboarded_user_can_view_supplements_page(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/essay-suite/supplements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('essay-suite/supplements')
                ->has('supplement_groups')
                ->has('supplements_summary')
                ->has('active_schools'));
    }
}
