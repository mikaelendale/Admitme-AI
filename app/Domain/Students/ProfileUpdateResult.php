<?php

namespace App\Domain\Students;

use App\Models\OnboardingProfile;

final readonly class ProfileUpdateResult
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  Field key => [before, after]; empty when nothing changed.
     */
    public function __construct(
        public OnboardingProfile $profile,
        public array $changes,
        public int $revisionBefore,
        public int $revisionAfter,
    ) {}

    public function changed(): bool
    {
        return $this->changes !== [];
    }
}
