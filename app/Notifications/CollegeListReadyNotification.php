<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CollegeListReadyNotification extends Notification
{
    use Queueable;

    protected readonly string $title;

    protected readonly string $body;

    protected readonly string $actionUrl;

    public function __construct(int $schoolCount)
    {
        $this->title = 'Your college list is ready';
        $this->body = "We built a personalized list of {$schoolCount} schools based on your profile. Review your reaches, matches, and safeties.";
        $this->actionUrl = url('/college-lists');
    }

    /**
     * @param  User  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->notificationPreference('email_college_list')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->body)
            ->action('View', $this->actionUrl)
            ->salutation('The AdmitMe Team');
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
        ];
    }
}
