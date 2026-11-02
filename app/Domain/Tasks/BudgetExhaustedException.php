<?php

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * The task tried to use more model calls than its budget allows. Never retried automatically.
 */
final class BudgetExhaustedException extends RuntimeException {}
