<?php

namespace App\Domain\Approvals;

enum ProposalStatus: string
{
    /** Waiting for the student. Never reported as a successful change. */
    case Pending = 'pending';

    /** Approved and applied (possibly only some items; see the receipt). */
    case Applied = 'applied';

    case Rejected = 'rejected';

    /** Not decided before it expired. */
    case Expired = 'expired';

    /** The data it was based on changed; it needs a fresh proposal instead of overwriting newer edits. */
    case Stale = 'stale';

    /** Approved, but applying it failed; nothing was changed. */
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
