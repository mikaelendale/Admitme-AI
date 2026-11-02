<?php

namespace App\Domain\Tasks;

use App\Models\Proposal;

/**
 * What a step's handler reports. The orchestrator turns it into a state transition and events.
 */
final class TaskOutcome
{
    /**
     * @param  array<string, mixed>  $result
     */
    private function __construct(
        public readonly TaskStatus $status,
        public readonly string $summary,
        public readonly array $result = [],
        public readonly ?Proposal $proposal = null,
        public readonly bool $retryable = false,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function completed(string $summary, array $result = []): self
    {
        return new self(TaskStatus::Completed, $summary, $result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function awaitingApproval(Proposal $proposal, string $summary, array $result = []): self
    {
        return new self(TaskStatus::AwaitingApproval, $summary, $result, $proposal);
    }

    /**
     * Cannot continue until the student does something (the reason says what).
     *
     * @param  array<string, mixed>  $result
     */
    public static function blocked(string $reason, array $result = []): self
    {
        return new self(TaskStatus::Blocked, $reason, $result);
    }

    public static function failed(string $reason, bool $retryable = false): self
    {
        return new self(TaskStatus::Failed, $reason, [], null, $retryable);
    }
}
