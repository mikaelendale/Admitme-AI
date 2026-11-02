<?php

namespace App\Domain\Documents;

use RuntimeException;

/**
 * A document's text could not be read. The message is written for the student and is shown as-is.
 */
final class DocumentUnreadableException extends RuntimeException {}
