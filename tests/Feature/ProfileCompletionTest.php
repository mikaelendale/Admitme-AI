<?php

namespace Tests\Feature;

use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser as PdfParser;
use Tests\TestCase;

class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_post_profile_dump(): void
    {
        $this->post(route('profile-completion.dump'), [
            'profile_dump_text' => 'Hello world',
        ])->assertRedirect();
    }

    public function test_empty_profile_dump_fails_validation(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('profile-overview'))
            ->post(route('profile-completion.dump'), [
                'profile_dump_text' => '   ',
            ])
            ->assertSessionHasErrors('profile_dump_text');
    }

    public function test_onboarded_user_can_update_clarify_fields(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        OnboardingProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->post(route('profile-completion.clarify'), [
                'current_grade' => '11th',
                'gpa_value' => '3.7',
                'gpa_scale' => '4.0',
                'gpa_type' => 'unweighted',
                'curriculum' => 'US 4.0',
            ])
            ->assertRedirect(route('profile-overview'));

        $this->assertDatabaseHas('onboarding_profiles', [
            'user_id' => $user->id,
            'current_grade' => '11th',
        ]);
    }

    public function test_onboarded_user_can_update_goals(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        OnboardingProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->post(route('profile-completion.goals'), [
                'dream_schools' => ['MIT'],
                'intended_majors' => ['CS'],
                'region_preferences' => ['Northeast'],
                'campus_size_pref' => 'medium',
                'financial_aid_need_percent' => 40,
            ])
            ->assertRedirect(route('profile-overview'));

        $row = OnboardingProfile::query()->where('user_id', $user->id)->first();
        $this->assertIsArray($row->dream_schools_json);
        $this->assertContains('MIT', $row->dream_schools_json);
    }

    public function test_onboarded_user_can_update_mentor_preferences(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        OnboardingProfile::query()->create([
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->post(route('profile-completion.mentor'), [
                'mentor_tone' => 'balanced',
                'weekly_focus_nudges' => true,
                'parent_access' => false,
                'mood_checkins' => false,
            ])
            ->assertRedirect(route('profile-overview'));

        $user->refresh();
        $this->assertSame('balanced', $user->ai_preferences['mentor_tone'] ?? null);
    }

    public function test_pass_user_blocked_when_pdf_resume_limit_reached(): void
    {
        config(['billing.profile_resume_pdf.max_parses_for_pass_users' => 2]);

        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
            'platform_pass_plan' => 'sprint',
            'platform_pass_ends_at' => now()->addDays(30),
            'profile_pdf_resume_parse_count' => 2,
        ]);

        $doc = Mockery::mock(Document::class);
        $doc->shouldReceive('getText')->once()->andReturn('Unique resume body '.uniqid('t', true));

        $parser = Mockery::mock(PdfParser::class);
        $parser->shouldReceive('parseContent')->once()->andReturn($doc);
        $this->app->instance(PdfParser::class, $parser);

        $file = UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf');

        $this->actingAs($user)
            ->from(route('profile-overview'))
            ->post(route('profile-completion.resume'), ['resume' => $file])
            ->assertSessionHasErrors('resume');
    }
}
