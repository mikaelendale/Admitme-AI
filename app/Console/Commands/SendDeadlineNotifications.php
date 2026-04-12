<?php

namespace App\Console\Commands;

use App\Models\CollegeListSchool;
use App\Models\User;
use App\Notifications\DeadlineApproachingNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDeadlineNotifications extends Command
{
    protected $signature = 'admitme:send-deadline-notifications';

    protected $description = 'Notify users about college application deadlines in the next 14 days';

    public function handle(): int
    {
        $windowStart = now()->startOfDay();

        $windowEnd = now()->copy()->addDays(14)->endOfDay();

        $deadlineMap = [
            'EA' => 'ea_deadline',
            'ED' => 'ed_deadline',
            'RD' => 'rd_deadline',
        ];

        $sent = 0;

        CollegeListSchool::query()
            ->active()
            ->whereNotNull('user_id')
            ->where(function ($q) use ($windowStart, $windowEnd): void {
                $q->whereBetween('ea_deadline', [$windowStart, $windowEnd])
                    ->orWhereBetween('ed_deadline', [$windowStart, $windowEnd])
                    ->orWhereBetween('rd_deadline', [$windowStart, $windowEnd]);
            })
            ->with('user')
            ->chunkById(100, function ($schools) use (&$sent, $deadlineMap, $windowStart, $windowEnd): void {
                foreach ($schools as $school) {
                    /** @var CollegeListSchool $school */
                    $user = $school->user;
                    if (! $user instanceof User) {
                        continue;
                    }

                    foreach ($deadlineMap as $label => $column) {
                        $deadline = $school->{$column};
                        if ($deadline === null) {
                            continue;
                        }
                        $day = $deadline->copy()->startOfDay();
                        if ($day->lt($windowStart) || $day->gt($windowEnd)) {
                            continue;
                        }

                        if ($this->alreadySentToday($user, $school->school_name, $label)) {
                            continue;
                        }

                        $deadlineDate = $deadline->format('M j, Y');
                        $daysRemaining = (int) now()->startOfDay()->diffInDays($day, false);

                        try {
                            $user->notify(new DeadlineApproachingNotification(
                                $school->school_name,
                                $label,
                                $deadlineDate,
                                $daysRemaining,
                            ));
                            $sent++;
                        } catch (\Throwable $e) {
                            Log::warning('Notification failed: '.$e->getMessage(), [
                                'user_id' => $user->id,
                            ]);
                        }
                    }
                }
            });

        Log::info('Deadline notifications sent', ['count' => $sent]);

        return self::SUCCESS;
    }

    private function alreadySentToday(User $user, string $schoolName, string $deadlineType): bool
    {
        return $user->notifications()
            ->where('type', DeadlineApproachingNotification::class)
            ->where('created_at', '>=', now()->startOfDay())
            ->get()
            ->contains(function ($n) use ($schoolName, $deadlineType): bool {
                $data = $n->data;

                return is_array($data)
                    && ($data['school_name'] ?? '') === $schoolName
                    && ($data['deadline_type'] ?? '') === $deadlineType;
            });
    }
}
