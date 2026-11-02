<?php

namespace App\Domain\Applications;

/**
 * Where a college sits in the student's list. A suggestion is not a saved shortlist entry.
 */
enum CollegeChoiceStatus: string
{
    case Suggested = 'suggested';
    case Shortlisted = 'shortlisted';
    case Applying = 'applying';
    case Archived = 'archived';

    public function canTransitionTo(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        return in_array($next, match ($this) {
            self::Suggested => [self::Shortlisted, self::Archived],
            self::Shortlisted => [self::Applying, self::Archived],
            self::Applying => [self::Shortlisted, self::Archived],
            self::Archived => [self::Shortlisted],
        }, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Suggested',
            self::Shortlisted => 'Shortlisted',
            self::Applying => 'Applying',
            self::Archived => 'Archived',
        };
    }
}
