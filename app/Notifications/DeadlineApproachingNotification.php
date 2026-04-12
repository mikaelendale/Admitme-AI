<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineApproachingNotification extends Notification
{
    use Queueable;

    protected readonly string $title;

    protected readonly string $body;

    protected readonly string $actionUrl;

    public function __construct(
        protected string $schoolName,
        protected string $deadlineType,
        protected string $deadlineDate,
        protected int $daysRemaining,
    ) {
        $this->title = "Deadline approaching — {$schoolName}";
        $this->body = "Your {$deadlineType} deadline for {$schoolName} is on {$deadlineDate} — {$daysRemaining} days away.";
        $this->actionUrl = url('/college-lists');
    }

    /**
     * @param  User  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->notificationPreference('email_deadlines')) {
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
     * @return array<string, string|int>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'school_name' => $this->schoolName,
            'deadline_type' => $this->deadlineType,
        ];
    }
}
