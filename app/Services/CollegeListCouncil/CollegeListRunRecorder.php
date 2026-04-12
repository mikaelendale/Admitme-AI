<?php

namespace App\Services\CollegeListCouncil;

use App\Models\CollegeListRun;
use App\Models\CollegeListRunEvent;
use App\Notifications\CollegeListFailedNotification;
use App\Notifications\CollegeListReadyNotification;
use Illuminate\Support\Facades\Log;

class CollegeListRunRecorder
{
    public function __construct(
        protected CollegeListCouncilBroadcaster $broadcaster,
    ) {}

    public function transitionStatus(CollegeListRun $run, string $status): void
    {
        $run->forceFill(['status' => $status])->save();
        $this->broadcaster->status((string) $run->id, $status);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function appendEvent(
        CollegeListRun $run,
        string $phase,
        string $severity,
        string $title,
        ?string $body = null,
        ?string $schoolSlug = null,
        ?array $payload = null,
    ): CollegeListRunEvent {
        $event = CollegeListRunEvent::query()->create([
            'college_list_run_id' => $run->id,
            'phase' => $phase,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'school_slug' => $schoolSlug,
            'payload' => $payload,
        ]);

        $this->broadcaster->insight((string) $run->id, [
            'event_id' => (string) $event->id,
            'phase' => $phase,
            'severity' => $severity,
            'title' => $title,
            'body' => $body,
            'school_slug' => $schoolSlug,
        ]);

        return $event;
    }

    public function complete(CollegeListRun $run, ?string $summary = null): void
    {
        $run->forceFill([
            'status' => CollegeListRun::STATUS_COMPLETED,
            'summary_text' => $summary,
            'completed_at' => now(),
            'error_message' => null,
        ])->save();

        $this->broadcaster->completed((string) $run->id, [
            'summary_text' => $summary,
        ]);

        $run->loadMissing('user');
        $user = $run->user;
        if ($user !== null) {
            try {
                $schoolCount = $user->collegeListSchools()->active()->count();
                $user->notify(new CollegeListReadyNotification($schoolCount));
            } catch (\Throwable $e) {
                Log::warning('Notification failed: '.$e->getMessage(), [
                    'user_id' => $user->id,
                ]);
            }
        }
    }

    public function fail(CollegeListRun $run, string $message): void
    {
        $run->forceFill([
            'status' => CollegeListRun::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();

        $this->broadcaster->failed((string) $run->id, [
            'message' => $message,
        ]);

        $run->loadMissing('user');
        $user = $run->user;
        if ($user !== null) {
            try {
                $user->notify(new CollegeListFailedNotification);
            } catch (\Throwable $e) {
                Log::warning('Notification failed: '.$e->getMessage(), [
                    'user_id' => $user->id,
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $questions
     */
    public function pauseForPreflightAnswers(CollegeListRun $run, array $questions, int $timeoutSeconds): void
    {
        $run->forceFill([
            'status' => CollegeListRun::STATUS_AWAITING_PREFLIGHT,
            'meta' => array_merge($run->meta ?? [], [
                'preflight_questions_pending' => $questions,
            ]),
        ])->save();

        $this->broadcaster->status((string) $run->id, CollegeListRun::STATUS_AWAITING_PREFLIGHT);
        $this->broadcaster->preflightQuestions((string) $run->id, $questions, $timeoutSeconds);

        $this->appendEvent(
            $run,
            'preflight',
            CollegeListRunEvent::SEVERITY_INFO,
            'Waiting for optional answers',
            'Reply in the council card in chat, or wait — the build will continue automatically.',
        );
    }
}
