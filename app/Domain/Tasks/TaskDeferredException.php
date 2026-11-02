<?php

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * The step cannot run right now for a reason that is not its fault (e.g. the provider rate limit): it is
 * queued again after a delay without using up an attempt.
 */
final class TaskDeferredException extends RuntimeException
{
    public function __construct(public readonly int $seconds, string $message)
    {
        parent::__construct($message);
    }
}
