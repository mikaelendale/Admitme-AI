<?php

namespace App\Notifications;

use App\Models\EssayRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EssayRunFailedNotification extends Notification
{
    use Queueable;

    protected readonly string $title;

    protected readonly string $body;

    protected readonly string $actionUrl;

    public function __construct(
        string $runType,
        string $schoolName,
    ) {
        $word = $runType === EssayRun::TYPE_BRAINSTORM ? 'brainstorm' : 'draft';
        $this->title = 'Essay generation failed';
        $this->body = "We couldn't complete your {$word} for {$schoolName}. Please try again.";
        $this->actionUrl = url('/essay-suite');
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
