<?php

namespace App\Domain\Applications;

/**
 * Checklist item states. The student's own work is "done" when they report it; third-party work is
 * "requested" when the student has asked for it — which is not completion — and "received" only when the
 * portal shows it arrived or the sender confirmed it.
 */
enum ChecklistStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case NotRequested = 'not_requested';
    case Requested = 'requested';
    case Received = 'received';
    case Blocked = 'blocked';
    case NotApplicable = 'not_applicable';

    /**
     * @return list<self>
     */
    public static function for(ChecklistOwner $owner): array
    {
        return $owner === ChecklistOwner::Student
            ? [self::Todo, self::InProgress, self::Done, self::Blocked, self::NotApplicable]
            : [self::NotRequested, self::Requested, self::Received, self::Blocked, self::NotApplicable];
    }

    public static function initial(ChecklistOwner $owner): self
    {
        return $owner === ChecklistOwner::Student ? self::Todo : self::NotRequested;
    }

    public function isComplete(): bool
    {
        return $this === self::Done || $this === self::Received;
    }

    /** Finished one way or the other: complete, or deliberately not needed. */
    public function isClosed(): bool
    {
        return $this->isComplete() || $this === self::NotApplicable;
    }

    public function needsNote(): bool
    {
        return $this === self::Blocked || $this === self::NotApplicable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Done => 'Done (you marked it)',
            self::NotRequested => 'Not requested yet',
            self::Requested => 'Requested — waiting for them',
            self::Received => 'Received',
            self::Blocked => 'Blocked',
            self::NotApplicable => 'Not needed',
        };
    }
}
