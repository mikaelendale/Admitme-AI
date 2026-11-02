<?php

namespace App\Http\Controllers;

use App\Domain\Documents\CandidateReviewService;
use App\Models\StudentFact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accept, edit-and-accept, or reject a value read from one of the student's documents.
 */
class DocumentValuesController extends Controller
{
    public function decide(Request $request, StudentFact $fact, CandidateReviewService $reviews): RedirectResponse
    {
        abort_unless((int) $fact->user_id === (int) $request->user()->getKey(), 404);

        $validated = $request->validate([
            'action' => ['required', 'in:accept,reject'],
            'value' => ['nullable', 'string', 'max:200'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        $edited = isset($validated['value']) && trim((string) $validated['value']) !== '' && (string) $validated['value'] !== (string) (is_scalar($fact->value) ? $fact->value : '')
            ? trim((string) $validated['value'])
            : null;

        $reviews->decide(
            $request->user(),
            $fact,
            $validated['action'],
            $edited !== null && is_numeric($edited) && ! str_contains($edited, '.') ? (int) $edited : $edited,
            isset($validated['expected_revision']) && (int) $validated['expected_revision'] > 0 ? (int) $validated['expected_revision'] : null,
        );

        return back()->with('status', $validated['action'] === 'accept' ? 'document-value-accepted' : 'document-value-rejected');
    }
}
