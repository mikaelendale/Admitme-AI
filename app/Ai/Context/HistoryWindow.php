<?php

namespace App\Ai\Context;

/**
 * Which stored turns of a conversation are replayed to the model.
 */
final readonly class HistoryWindow
{
    public function __construct(
        /** Number of most-recent stored turns (rows) to replay. */
        public int $rows,
        /** Number of stored turns in the conversation. */
        public int $totalRows,
        /** Estimated tokens of the replayed turns (characters / 4). */
        public int $estimatedTokens,
        /** Newest stored turn that is NOT replayed; older turns rely on the summary. */
        public ?string $newestExcludedMessageId,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, null);
    }

    public function excludedRows(): int
    {
        return max(0, $this->totalRows - $this->rows);
    }
}
