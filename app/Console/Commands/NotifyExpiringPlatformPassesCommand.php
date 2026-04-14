<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\PlatformPassExpiringNotification;
use Illuminate\Console\Command;

class NotifyExpiringPlatformPassesCommand extends Command
{
    protected $signature = 'admitme:notify-expiring-passes';

    protected $description = 'Notify users whose platform pass expires within 24 hours';

    public function handle(): int
    {
        $windowEnd = now()->addDay();

        User::query()
            ->whereNotNull('platform_pass_ends_at')
            ->where('platform_pass_ends_at', '>', now())
            ->where('platform_pass_ends_at', '<=', $windowEnd)
            ->whereNull('notified_expiry_at')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    if ($user->subscribed('default')) {
                        continue;
                    }

                    $user->notify(new PlatformPassExpiringNotification);
                    $user->forceFill(['notified_expiry_at' => now()])->save();
                }
            });

        return self::SUCCESS;
    }
}
