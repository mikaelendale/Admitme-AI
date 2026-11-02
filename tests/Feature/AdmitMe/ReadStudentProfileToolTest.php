<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Tools\ReadStudentProfileTool;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Forms ask for a name and an email first: the profile tool gives the mentor the ones on the student's AdmitMe
 * account with the identity section (and "all"), so filling a portal never depends on which tool it picked.
 */
class ReadStudentProfileToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_and_all_include_the_account_name_and_email(): void
    {
        $student = User::factory()->create(['name' => 'Ada Synthetic', 'email' => 'ada.synthetic@example.test']);
        OnboardingProfile::factory()->for($student)->create();
        $tool = new ReadStudentProfileTool($student);

        foreach (['identity', 'all'] as $section) {
            $read = json_decode((string) $tool->handle(new Request(['section' => $section])), true);

            $this->assertSame('Ada Synthetic', $read['account']['name'], $section);
            $this->assertSame('ada.synthetic@example.test', $read['account']['email'], $section);
        }

        $academics = json_decode((string) $tool->handle(new Request(['section' => 'academics'])), true);
        $this->assertArrayNotHasKey('account', $academics);
    }
}
