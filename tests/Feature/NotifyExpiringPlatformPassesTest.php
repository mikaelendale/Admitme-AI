<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PlatformPassExpiringNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class NotifyExpiringPlatformPassesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sends_notification_and_sets_notified_expiry_at(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'platform_pass_plan' => 'trial',
            'platform_pass_ends_at' => now()->addHours(6),
            'notified_expiry_at' => null,
            'stripe_id' => null,
        ]);

        $this->artisan('admitme:notify-expiring-passes')->assertSuccessful();

        Notification::assertSentTo($user, PlatformPassExpiringNotification::class);

        $user->refresh();
        $this->assertNotNull($user->notified_expiry_at);
    }

    public function test_command_skips_users_without_upcoming_expiry_window(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'platform_pass_ends_at' => now()->addDays(3),
            'notified_expiry_at' => null,
        ]);

        $this->artisan('admitme:notify-expiring-passes')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($user->fresh()->notified_expiry_at);
    }

    public function test_command_skips_users_with_active_pro_subscription(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'platform_pass_plan' => 'trial',
            'platform_pass_ends_at' => now()->addHours(6),
            'notified_expiry_at' => null,
            'stripe_id' => 'cus_test_subscribed',
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_active',
            'stripe_status' => 'active',
            'stripe_price' => null,
            'quantity' => null,
        ]);

        $this->artisan('admitme:notify-expiring-passes')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($user->fresh()->notified_expiry_at);
    }
}
