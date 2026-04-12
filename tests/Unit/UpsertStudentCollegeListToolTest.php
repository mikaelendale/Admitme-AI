<?php

namespace Tests\Unit;

use App\Ai\Tools\UpsertStudentCollegeList;
use App\Models\CollegeListSchool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class UpsertStudentCollegeListToolTest extends TestCase
{
    use RefreshDatabase;

    protected function baseRequest(array $overrides = []): Request
    {
        return new Request(array_merge([
            'school_name' => 'Example College',
            'list_type' => 'target',
            'fit_score' => null,
            'ai_reasoning' => null,
            'status' => null,
            'deadline' => null,
            'notes' => null,
            'ai_metadata_json' => null,
            'application_portal_url' => null,
            'admissions_cycle_label' => null,
        ], $overrides));
    }

    public function test_creates_row_for_user(): void
    {
        $user = User::factory()->create();
        $tool = new UpsertStudentCollegeList($user);
        $out = json_decode((string) $tool->handle($this->baseRequest([
            'school_name' => 'State University',
            'list_type' => 'safety',
            'fit_score' => 80,
            'ai_reasoning' => 'Good fit.',
        ])), true);

        $this->assertTrue($out['ok']);
        $this->assertSame('State University', $out['school_name']);
        $this->assertSame('safety', $out['list_type']);
        $this->assertSame(80, $out['fit_score']);
        $this->assertSame('/college-lists', $out['college_lists_path']);

        $this->assertDatabaseHas('college_list_schools', [
            'user_id' => $user->id,
            'school_name' => 'State University',
            'school_slug' => 'state-university',
            'tier' => 'safety',
        ]);
    }

    public function test_updates_same_school_name_for_user(): void
    {
        $user = User::factory()->create();
        CollegeListSchool::query()->create([
            'user_id' => $user->id,
            'school_name' => 'Same School',
            'school_slug' => 'same-school',
            'tier' => 'reach',
            'fit_score' => 50,
            'why_blurb' => 'Initial.',
            'application_status' => 'not_started',
            'is_removed' => false,
            'generated_at' => now(),
        ]);

        $tool = new UpsertStudentCollegeList($user);
        $out = json_decode((string) $tool->handle($this->baseRequest([
            'school_name' => 'Same School',
            'list_type' => 'target',
            'fit_score' => 72,
            'status' => 'researching',
        ])), true);

        $this->assertTrue($out['ok']);
        $this->assertSame('target', $out['list_type']);
        $this->assertSame(72, $out['fit_score']);
        $this->assertSame('researching', $out['status']);

        $this->assertSame(1, CollegeListSchool::query()->where('user_id', $user->id)->count());
    }

    public function test_rejects_invalid_list_type(): void
    {
        $user = User::factory()->create();
        $tool = new UpsertStudentCollegeList($user);
        $out = json_decode((string) $tool->handle($this->baseRequest([
            'list_type' => 'invalid',
        ])), true);

        $this->assertArrayHasKey('error', $out);
    }
}
