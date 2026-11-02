<?php

namespace App\Domain\Applications;

/**
 * How we know an application was submitted. AdmitMe never infers submission on its own.
 */
enum SubmissionEvidence: string
{
    /** The student has a confirmation/receipt from the application portal. */
    case PortalReceipt = 'portal_receipt';

    /** The student told us they submitted it, without a receipt on file. */
    case StudentConfirmation = 'student_confirmation';

    public function label(): string
    {
        return match ($this) {
            self::PortalReceipt => 'Portal receipt',
            self::StudentConfirmation => 'Student confirmation',
        };
    }
}
