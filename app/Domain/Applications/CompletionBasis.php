<?php

namespace App\Domain\Applications;

/**
 * How we know a checklist item is complete. AdmitMe never reads a portal's own record of what was received
 * (the browser extension only reads form fields on a page the student chooses, to fill them), so every basis
 * is something the student reports — the label says what they reported.
 */
enum CompletionBasis: string
{
    /** The student says they did it (their own work). */
    case StudentReported = 'student_reported';

    /** The student says the application portal shows the item as received (third-party work). */
    case PortalShowsReceived = 'portal_shows_received';

    /** The student says the sender (teacher, counselor, testing agency) confirmed sending it. */
    case SenderConfirmed = 'sender_confirmed';

    public function label(): string
    {
        return match ($this) {
            self::StudentReported => 'Marked done by you',
            self::PortalShowsReceived => 'You saw it marked received in the portal',
            self::SenderConfirmed => 'The sender told you it was sent',
        };
    }

    /**
     * @return list<self>
     */
    public static function for(ChecklistOwner $owner): array
    {
        return $owner === ChecklistOwner::Student ? [self::StudentReported] : [self::PortalShowsReceived, self::SenderConfirmed];
    }
}
