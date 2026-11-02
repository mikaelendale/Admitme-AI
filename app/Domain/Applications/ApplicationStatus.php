<?php

namespace App\Domain\Applications;

/**
 * A prepared form is not a submitted application: `submitted` needs recorded evidence.
 */
enum ApplicationStatus: string
{
    case Preparing = 'preparing';
    case ReadyForReview = 'ready_for_review';
    case ReadyToSubmit = 'ready_to_submit';
    case Submitted = 'submitted';
    case DecisionReceived = 'decision_received';
    case Closed = 'closed';

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this || $next === self::Closed) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Preparing => [self::ReadyForReview, self::ReadyToSubmit],
            self::ReadyForReview => [self::Preparing, self::ReadyToSubmit],
            self::ReadyToSubmit => [self::Preparing, self::ReadyForReview, self::Submitted],
            self::Submitted => [self::DecisionReceived],
            self::DecisionReceived, self::Closed => [],
        }, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'Preparing',
            self::ReadyForReview => 'Ready for review',
            self::ReadyToSubmit => 'Ready to submit',
            self::Submitted => 'Submitted',
            self::DecisionReceived => 'Decision received',
            self::Closed => 'Closed',
        };
    }
}
