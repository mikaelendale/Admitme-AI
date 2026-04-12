<?php

namespace App\Notifications;

use App\Models\EssayRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EssayRunCompletedNotification extends Notification
{
    use Queueable;

    protected readonly string $title;

    protected readonly string $body;

    protected readonly string $actionUrl;

    public function __construct(
        public string $runId,
        string $runType,
        string $schoolName,
    ) {
        $word = $runType === EssayRun::TYPE_BRAINSTORM ? 'brainstorm' : 'draft';
        $this->title = "Your {$word} is ready";
        $this->body = "Your essay {$word} for {$schoolName} has been generated. Open it to review and continue.";
        $this->actionUrl = url('/essay-suite');
    }

    /**
     * @param  User  $notifiable
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if ($notifiable->notificationPreference('email_essay_complete')) {
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
