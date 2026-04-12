<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CollegeListFailedNotification extends Notification
{
    use Queueable;

    protected readonly string $title;

    protected readonly string $body;

    protected readonly string $actionUrl;

    public function __construct()
    {
        $this->title = 'College list generation failed';
        $this->body = 'Something went wrong building your college list. Please try again from the college list page.';
        $this->actionUrl = url('/college-lists');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
