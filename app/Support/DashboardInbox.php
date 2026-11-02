<?php

namespace App\Support;

use App\Domain\Applications\CollegeChoiceStatus;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentTask;
use App\Models\CollegeListSchool;
use App\Models\Document;
use App\Models\Proposal;
use App\Models\StudentFact;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The home inbox: everything that is waiting for the student, read from the same records the workspace
 * pages use (approvals, document values to review, deadlines, background tasks, notifications). Nothing
 * here is generated; each row links to the page where it can be acted on.
 */
final class DashboardInbox
{
    /** Nudges whose message Home already shows elsewhere (next-step card, profile progress, deadlines). */
    private const COVERED_ELSEWHERE = ['profile_incomplete', 'profile_missing', 'first_chat', 'empty_college_list', 'deadlines_soon'];

    /**
     * @param  list<array<string, mixed>>  $nudges  from DashboardHubInsights::nudges()
     * @return array{items: list<array<string, mixed>>, counts: array{needs_you: int, deadlines: int, activity: int}}
     */
    public static function for(User $user, array $nudges = []): array
    {
        $items = [
            ...self::approvals($user),
            ...self::documentReviews($user),
            ...self::deadlines($user),
            ...self::missingDeadlines($user),
            ...self::tasks($user),
            ...self::notifications($user),
            ...self::nudges($nudges),
        ];

        // Most pressing first; deadlines soonest first, everything else newest first.
        usort($items, function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            if (isset($a['days'], $b['days'])) {
                return $a['days'] <=> $b['days'];
            }

            return ($b['at'] ?? '') <=> ($a['at'] ?? '');
        });

        $counts = ['needs_you' => 0, 'deadlines' => 0, 'activity' => 0];
        foreach ($items as $item) {
            $counts[$item['group']]++;
        }

        return ['items' => array_map(fn (array $item) => collect($item)->except('rank')->all(), $items), 'counts' => $counts];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function approvals(User $user): array
    {
        return Proposal::query()
            ->where('user_id', $user->getKey())
            ->where('status', ProposalStatus::Pending->value)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->limit(6)
            ->get()
            ->map(function (Proposal $proposal) {
                $count = count((array) $proposal->items);

                return [
                    'id' => "approval:{$proposal->id}",
                    'kind' => 'approval',
                    'group' => 'needs_you',
                    'tone' => 'info',
                    'rank' => 2,
                    'title' => $proposal->title ?: 'A change waits for your approval',
                    'body' => $count === 1 ? 'One proposed change. Nothing is saved until you approve it.' : "{$count} proposed changes. Nothing is saved until you approve them.",
                    'href' => "/approvals/{$proposal->id}",
                    'cta' => 'Review',
                    'at' => $proposal->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function documentReviews(User $user): array
    {
        $byDocument = StudentFact::query()
            ->where('user_id', $user->getKey())
            ->where('review_state', StudentFact::REVIEW_PENDING)
            ->get(['source_ref', 'created_at'])
            ->groupBy(fn (StudentFact $fact) => (string) ($fact->source_ref['document_id'] ?? ''))
            ->filter(fn ($facts, $documentId) => $documentId !== '');

        if ($byDocument->isEmpty()) {
            return [];
        }

        $documents = Document::query()->whereIn('id', $byDocument->keys())->where('user_id', $user->getKey())->get(['id', 'title'])->keyBy('id');

        return $byDocument
            ->map(function ($facts, $documentId) use ($documents) {
                $document = $documents->get($documentId);
                if ($document === null) {
                    return null;
                }
                $count = $facts->count();

                return [
                    'id' => "document:{$documentId}",
                    'kind' => 'document',
                    'group' => 'needs_you',
                    'tone' => 'info',
                    'rank' => 3,
                    'title' => "Check {$count} ".Str::plural('value', $count)." read from {$document->title}",
                    'body' => 'Values read from your document reach your profile only after you accept them.',
                    'href' => "/documents/{$documentId}",
                    'cta' => 'Review',
                    'at' => $facts->max('created_at')?->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function deadlines(User $user): array
    {
        $today = Carbon::today();
        $labels = ['ea_deadline' => 'Early Action', 'ed_deadline' => 'Early Decision', 'rd_deadline' => 'Regular Decision'];
        $out = [];

        $schools = CollegeListSchool::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->with('application:id,college_list_school_id')
            ->get(['id', 'school_name', 'ea_deadline', 'ed_deadline', 'rd_deadline']);

        foreach ($schools as $school) {
            foreach ($labels as $field => $label) {
                $date = $school->{$field};
                if (! $date instanceof CarbonInterface || $date->lt($today) || $date->gt($today->copy()->addDays(120))) {
                    continue;
                }
                $days = (int) $today->diffInDays($date);
                $out[] = [
                    'id' => "deadline:{$school->id}:{$field}",
                    'kind' => 'deadline',
                    'group' => 'deadlines',
                    'tone' => $days <= 14 ? 'urgent' : ($days <= 30 ? 'warning' : 'info'),
                    'rank' => $days <= 14 ? 0 : 6,
                    'title' => "{$school->school_name} · {$label}",
                    'body' => ($days === 0 ? 'Due today' : ($days === 1 ? 'Due tomorrow' : "{$days} days left")).' · from your list — confirm on the college\'s site',
                    'href' => $school->application ? "/applications/{$school->application->id}" : "/college-lists/{$school->id}",
                    'cta' => $school->application ? 'Open application' : 'Open college',
                    'at' => $date->toIso8601String(),
                    'days' => $days,
                ];
            }
        }

        usort($out, fn (array $a, array $b) => $a['days'] <=> $b['days']);

        return array_slice($out, 0, 12);
    }

    /**
     * Colleges on the list with no deadline at all (no Early Action, Early Decision or Regular Decision date,
     * and not rolling admission): every college needs one, so the deadline tracker asks for them.
     *
     * @return list<array<string, mixed>>
     */
    private static function missingDeadlines(User $user): array
    {
        $missing = CollegeListSchool::query()
            ->where('user_id', $user->getKey())
            ->whereIn('choice_status', [CollegeChoiceStatus::Shortlisted->value, CollegeChoiceStatus::Applying->value])
            ->where('rolling_admission', false)
            ->whereNull('ea_deadline')
            ->whereNull('ed_deadline')
            ->whereNull('rd_deadline')
            ->orderBy('school_name')
            ->pluck('school_name');

        if ($missing->isEmpty()) {
            return [];
        }

        $count = $missing->count();
        $names = $missing->take(3)->implode(', ').($count > 3 ? ' and '.($count - 3).' more' : '');

        return [[
            'id' => 'deadlines:missing',
            'kind' => 'deadline_missing',
            'group' => 'needs_you',
            'tone' => 'warning',
            'rank' => 3,
            'title' => $count === 1 ? "{$missing->first()} needs a deadline" : "{$count} colleges need a deadline",
            'body' => ($count > 1 ? "{$names}. " : '').'Add their Early Action, Early Decision or Regular Decision dates — or mark rolling admission.',
            'href' => '/deadlines',
            'cta' => 'Add deadlines',
            'at' => null,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function tasks(User $user): array
    {
        return AgentTask::query()
            ->where('user_id', $user->getKey())
            ->whereNull('parent_id')
            ->where('updated_at', '>=', now()->subDays(7))
            ->whereIn('status', [TaskStatus::Running->value, TaskStatus::Queued->value, TaskStatus::AwaitingApproval->value, TaskStatus::Failed->value, TaskStatus::Completed->value, TaskStatus::Blocked->value])
            ->latest('updated_at')
            ->limit(6)
            ->get()
            ->map(function (AgentTask $task) {
                $status = $task->status instanceof TaskStatus ? $task->status : TaskStatus::tryFrom((string) $task->status);
                $needsYou = in_array($status, [TaskStatus::Failed, TaskStatus::AwaitingApproval, TaskStatus::Blocked], true);

                return [
                    'id' => "task:{$task->id}",
                    'kind' => 'task',
                    'group' => $needsYou ? 'needs_you' : 'activity',
                    'tone' => match ($status) {
                        TaskStatus::Failed, TaskStatus::Blocked => 'warning',
                        TaskStatus::Completed => 'success',
                        default => 'info',
                    },
                    'rank' => $needsYou ? 1 : 7,
                    'title' => $task->title ?: 'Background task',
                    'body' => match ($status) {
                        TaskStatus::Failed => 'Stopped: '.Str::limit((string) ($task->status_reason ?: 'it did not finish'), 120),
                        TaskStatus::AwaitingApproval => 'Waiting for your approval before it continues.',
                        TaskStatus::Blocked => 'Blocked: '.Str::limit((string) ($task->status_reason ?: 'it needs something first'), 120),
                        TaskStatus::Completed => 'Finished. See what it found on Tasks.',
                        TaskStatus::Queued => 'Queued — it starts when a worker is free.',
                        default => 'Running in the background.',
                    },
                    'href' => '/tasks',
                    'cta' => 'Open tasks',
                    'at' => $task->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function notifications(User $user): array
    {
        return $user->notifications()
            ->latest()
            ->limit(6)
            ->get()
            ->map(function (DatabaseNotification $notification) {
                $data = is_array($notification->data) ? $notification->data : [];
                $title = collect(['title', 'subject', 'heading'])->map(fn ($k) => $data[$k] ?? null)->first(fn ($v) => is_string($v) && $v !== '')
                    ?? (preg_replace('/([a-z])([A-Z])/', '$1 $2', class_basename($notification->type)) ?: 'Notification');
                $body = collect(['body', 'message', 'text', 'content'])->map(fn ($k) => $data[$k] ?? null)->first(fn ($v) => is_string($v) && $v !== '');

                return [
                    'id' => "notification:{$notification->id}",
                    'kind' => 'notification',
                    'group' => 'activity',
                    'tone' => $notification->read_at === null ? 'info' : 'muted',
                    'rank' => 5,
                    'title' => $title,
                    'body' => $body !== null ? Str::limit($body, 160) : null,
                    'href' => is_string($data['action_url'] ?? null) && $data['action_url'] !== '' ? $data['action_url'] : '/notifications',
                    'cta' => 'Open',
                    'at' => $notification->created_at?->toIso8601String(),
                    'unread' => $notification->read_at === null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $nudges
     * @return list<array<string, mixed>>
     */
    private static function nudges(array $nudges): array
    {
        // Home already shows the next step, the profile and the deadlines; keep only nudges that add something.
        $nudges = array_filter($nudges, fn (array $nudge) => ! in_array($nudge['id'] ?? '', self::COVERED_ELSEWHERE, true));

        return array_values(array_map(fn (array $nudge) => [
            'id' => 'nudge:'.($nudge['id'] ?? Str::random(6)),
            'kind' => 'nudge',
            'group' => 'needs_you',
            'tone' => ($nudge['severity'] ?? 'info') === 'urgent' ? 'urgent' : (($nudge['severity'] ?? '') === 'warning' ? 'warning' : 'info'),
            'rank' => ($nudge['severity'] ?? 'info') === 'urgent' ? 0 : 4,
            'title' => (string) ($nudge['title'] ?? ''),
            'body' => $nudge['body'] ?? null,
            'href' => (string) ($nudge['href'] ?? '/dashboard'),
            'cta' => (string) ($nudge['cta'] ?? 'Open'),
            'at' => null,
        ], $nudges));
    }
}
