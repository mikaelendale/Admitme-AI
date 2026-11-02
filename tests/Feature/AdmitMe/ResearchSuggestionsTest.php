<?php

namespace Tests\Feature\AdmitMe;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Models\CollegeListSchool;
use App\Models\User;
use App\Services\CollegeList\CollegeListAssemblyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI list research produces suggestions. It may refresh details, but never removes or re-statuses a
 * college the student saved (decisions D-012).
 */
class ResearchSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_regenerating_a_list_only_replaces_earlier_suggestions(): void
    {
        $student = User::factory()->create();

        $shortlisted = CollegeListSchool::factory()->for($student)->create(['school_name' => 'Synthetic Alpha College', 'school_slug' => 'synthetic-alpha-college', 'why_blurb' => 'old']);
        $applying = CollegeListSchool::factory()->for($student)->create(['school_name' => 'Synthetic Beta College', 'school_slug' => 'synthetic-beta-college', 'choice_status' => CollegeChoiceStatus::Applying]);
        $oldSuggestion = CollegeListSchool::factory()->for($student)->suggested()->create(['school_name' => 'Synthetic Gamma College', 'school_slug' => 'synthetic-gamma-college']);

        app(CollegeListAssemblyService::class)->persistFromAgentPayload($student, 'generate', [
            'action' => 'generate',
            'schools' => [
                ['school_name' => 'Synthetic Alpha College', 'tier' => 'reach', 'fit_score' => 70, 'why_blurb' => 'refreshed by research'],
                ['school_name' => 'Synthetic Delta College', 'tier' => 'match', 'fit_score' => 65, 'why_blurb' => 'new idea'],
            ],
            'removed_slugs' => ['synthetic-beta-college'],
        ]);

        $shortlisted->refresh();
        $this->assertSame(CollegeChoiceStatus::Shortlisted, $shortlisted->choice_status);
        $this->assertFalse($shortlisted->is_removed);
        $this->assertSame('refreshed by research', $shortlisted->why_blurb);
        $this->assertSame(2, $shortlisted->revision, 'Research updates still bump the revision.');

        $applying->refresh();
        $this->assertSame(CollegeChoiceStatus::Applying, $applying->choice_status, 'Research cannot remove a college the student is applying to.');
        $this->assertFalse($applying->is_removed);

        $oldSuggestion->refresh();
        $this->assertSame(CollegeChoiceStatus::Archived, $oldSuggestion->choice_status);
        $this->assertTrue($oldSuggestion->is_removed);

        $new = CollegeListSchool::query()->where('user_id', $student->id)->where('school_slug', 'synthetic-delta-college')->sole();
        $this->assertSame(CollegeChoiceStatus::Suggested, $new->choice_status, 'A research result is a suggestion, not a saved shortlist entry.');
    }

    public function test_the_student_promotes_a_suggestion_to_their_shortlist(): void
    {
        $student = User::factory()->create();
        $suggestion = CollegeListSchool::factory()->for($student)->suggested()->create();

        $this->actingAs($student)
            ->patchJson(route('college-lists.school.update', $suggestion), ['choice_status' => 'shortlisted', 'expected_revision' => 1])
            ->assertOk()
            ->assertJsonPath('choiceStatus', 'shortlisted');
    }
}
