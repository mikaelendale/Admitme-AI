<?php

namespace App\Domain\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a write was based on an older revision than the one stored, so applying it
 * would overwrite a newer change. Nothing is written when this is thrown.
 */
final class StaleRevisionException extends RuntimeException
{
    public function __construct(
        public readonly string $resource,
        public readonly int $expectedRevision,
        public readonly int $currentRevision,
    ) {
        parent::__construct("This {$resource} changed since you opened it (you had revision {$expectedRevision}, it is now {$currentRevision}). Reload to see the latest version, then try again.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'error' => 'stale_revision',
                'resource' => $this->resource,
                'current_revision' => $this->currentRevision,
            ], 409);
        }

        return back()->withErrors(['expected_revision' => $this->getMessage()]);
    }
}
