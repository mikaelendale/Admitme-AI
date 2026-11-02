<?php

namespace App\Domain\Approvals;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The decision cannot be taken as submitted: the proposal is no longer pending, has expired, or
 * changed since the student reviewed it. Nothing was changed.
 */
final class ProposalConflictException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'error' => 'proposal_conflict'], 409);
        }

        return back()->withErrors(['proposal' => $this->getMessage()]);
    }
}
