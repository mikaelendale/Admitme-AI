<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DemoInAppNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Welcome to your inbox',
            'body' => 'This is a demo in-app notification. Open Email alerts in Settings to choose what we email you.',
            'action_url' => url('/settings/notifications'),
        ];
    }
}
