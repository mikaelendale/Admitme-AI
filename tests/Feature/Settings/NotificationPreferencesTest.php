<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_preferences_can_be_updated(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('settings.notifications.update'), [
                'deadline_reminders' => '1',
                'weekly_progress' => '0',
                'profile_updates' => '1',
                'essay_feedback' => '1',
                'marketing' => '0',
            ])
            ->assertRedirect(route('settings.notifications.edit'));

        $user->refresh();
        $this->assertTrue($user->notification_preferences['deadline_reminders']);
        $this->assertFalse($user->notification_preferences['weekly_progress']);
        $this->assertFalse($user->notification_preferences['marketing']);
    }
}
