<?php

namespace App\Ai\Context;

use App\Models\ConversationSummary;

/**
 * The assembled instructions for one mentor run, plus what went into them.
 */
final readonly class MentorContext
{
    /**
     * @param  array<string, int>  $estimatedTokens  Part => estimated tokens (characters / 4).
     */
    public function __construct(
        public string $instructions,
        public array $estimatedTokens,
        public int $profileRevision,
        public string $instructionVersion,
        public ?ConversationSummary $summary = null,
        public bool $summaryOutdated = false,
    ) {}
}
