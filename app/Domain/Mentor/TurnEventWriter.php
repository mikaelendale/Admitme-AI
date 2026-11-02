<?php

namespace App\Domain\Mentor;

use App\Models\MentorTurn;

/**
 * Buffers a background turn's stream parts and writes them in small batches: often enough that the chat sees the
 * reply arrive as it is written, rarely enough not to write a row per token. Consecutive text deltas are merged.
 */
final class TurnEventWriter
{
    /** Write at least this often while parts keep coming. */
    private const FLUSH_MS = 120;

    /** Check whether the student pressed stop at most this often. */
    private const CANCEL_CHECK_MS = 700;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private float $lastFlush;

    private float $lastCancelCheck;

    public function __construct(private readonly MentorTurn $turn, private readonly MentorTurnService $turns)
    {
        $this->lastFlush = $this->lastCancelCheck = microtime(true);
    }

    /**
     * @param  array<string, mixed>  $part
     */
    public function push(array $part): void
    {
        $lastIndex = count($this->buffer) - 1;
        $last = $this->buffer[$lastIndex] ?? null;
        $preliminary = $part['type'] === 'tool-output-available' && ! empty($part['preliminary']);

        if ($part['type'] === 'text-delta' && $last !== null && $last['type'] === 'text-delta' && ($last['id'] ?? null) === ($part['id'] ?? null)) {
            $this->buffer[$lastIndex]['delta'] = ($last['delta'] ?? '').($part['delta'] ?? '');
        } elseif ($preliminary && $last !== null && ! empty($last['preliminary']) && ($last['toolCallId'] ?? null) === ($part['toolCallId'] ?? null)) {
            // A specialist's partial output restates everything so far: only the latest one matters.
            $this->buffer[$lastIndex] = $part;
        } else {
            $this->buffer[] = $part;
        }

        // Text and a specialist's partial output wait for the next batch; tool steps and cards are written at once.
        $batched = $part['type'] === 'text-delta' || $preliminary;

        if (! $batched || (microtime(true) - $this->lastFlush) * 1000 >= self::FLUSH_MS) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $this->turns->append($this->turn, $this->buffer);
        $this->buffer = [];
        $this->lastFlush = microtime(true);
    }

    public function cancelRequested(): bool
    {
        if ((microtime(true) - $this->lastCancelCheck) * 1000 < self::CANCEL_CHECK_MS) {
            return false;
        }

        $this->lastCancelCheck = microtime(true);

        return MentorTurn::query()->whereKey($this->turn->getKey())->whereNotNull('cancel_requested_at')->exists();
    }
}
