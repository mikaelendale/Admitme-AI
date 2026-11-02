<?php

namespace App\Domain\Tasks;

/**
 * Task states from the plan. A task "awaiting_approval" has produced a proposal and is waiting for the
 * student; that is pending, never success.
 */
enum TaskStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Blocked = 'blocked';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::AwaitingApproval => 'Waiting for your approval',
            self::Blocked => 'Blocked',
            self::Paused => 'Paused',
            self::Completed => 'Done',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
