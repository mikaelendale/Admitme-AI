<?php

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * Thrown at a checkpoint when the student cancelled a running task; the orchestrator records it as cancelled.
 */
final class TaskCancelledException extends RuntimeException {}
