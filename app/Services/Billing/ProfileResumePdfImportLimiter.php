<?php

namespace App\Services\Billing;

use App\Models\User;

/**
 * PDF resume import: unlimited for active Pro subscriptions; capped for trial/sprint (one-time) passes.
 */
final class ProfileResumePdfImportLimiter
{
    public function __construct(
        private readonly int $maxParsesForPassUsers,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            max(0, (int) config('billing.profile_resume_pdf.max_parses_for_pass_users', 3)),
        );
    }

    public function hasUnlimitedImports(User $user): bool
    {
        $sub = $user->subscription('default');

        return $sub !== null && $sub->valid();
    }

    /**
     * @return int|null Remaining imports for pass users; null when subscription = unlimited.
     */
    public function remainingForDisplay(User $user): ?int
    {
        if ($this->hasUnlimitedImports($user)) {
            return null;
        }

        return max(0, $this->maxParsesForPassUsers - (int) $user->profile_pdf_resume_parse_count);
    }

    public function canRunNewPdfExtraction(User $user): bool
    {
        return $this->hasUnlimitedImports($user)
            || (int) $user->profile_pdf_resume_parse_count < $this->maxParsesForPassUsers;
    }

    public function blockMessage(): string
    {
        return 'Resume PDF import is limited on your plan. Subscribe for unlimited imports, or paste your resume text in the box above.';
    }
}
