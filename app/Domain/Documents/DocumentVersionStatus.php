<?php

namespace App\Domain\Documents;

enum DocumentVersionStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';

    /** Text was read and values were found; the student needs to review them. */
    case NeedsReview = 'needs_review';

    /** Processed; nothing (more) to review. */
    case Ready = 'ready';

    /** Could not be read (e.g. a scanned image without OCR). The reason is shown to the student. */
    case Failed = 'failed';

    /** A newer version replaced this one. Its file and history are kept. */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting to be read',
            self::Processing => 'Reading…',
            self::NeedsReview => 'Needs your review',
            self::Ready => 'Ready',
            self::Failed => 'Could not be read',
            self::Superseded => 'Replaced by a newer version',
        };
    }
}
