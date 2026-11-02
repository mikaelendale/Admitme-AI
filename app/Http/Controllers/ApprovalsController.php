<?php

namespace App\Http\Controllers;

use App\Domain\Approvals\ProposalPresenter;
use App\Domain\Approvals\ProposalService;
use App\Domain\Approvals\ProposalStatus;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The student's approval inbox and the single decision endpoint used by both the inbox and chat cards.
 */
class ApprovalsController extends Controller
{
    public function index(Request $request, ProposalService $proposals): Response
    {
        $user = $request->user();

        $recent = Proposal::query()
            ->where('user_id', $user->getKey())
            ->where('status', '!=', ProposalStatus::Pending)
            ->with('decision')
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return Inertia::render('approvals', [
            'pending' => $proposals->pendingFor($user)->map(fn (Proposal $p) => ProposalPresenter::present($p))->values()->all(),
            'recent' => $recent->map(fn (Proposal $p) => ProposalPresenter::present($p))->values()->all(),
        ]);
    }

    public function show(Request $request, Proposal $proposal, ProposalService $proposals): JsonResponse
    {
        Gate::authorize('view', $proposal);

        return response()->json(['proposal' => ProposalPresenter::present($proposals->refreshExpiry($proposal))]);
    }

    public function decide(Request $request, Proposal $proposal, ProposalService $proposals): JsonResponse
    {
        Gate::authorize('decide', $proposal);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'selected_keys' => ['sometimes', 'array', 'max:50'],
            'selected_keys.*' => ['string', 'max:200'],
            'edits' => ['sometimes', 'array', 'max:20'],
            'proposal_revision' => ['required', 'integer', 'min:1'],
            'payload_hash' => ['required', 'string', 'size:64'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $edits = $this->editsFromInput($validated['edits'] ?? []);

        $decision = $proposals->decide(
            $request->user(),
            $proposal,
            $validated['decision'],
            $validated['selected_keys'] ?? [],
            $edits,
            (int) $validated['proposal_revision'],
            $validated['payload_hash'],
            $validated['idempotency_key'] ?? null,
        );

        $fresh = Proposal::query()->with('decision')->findOrFail($decision->proposal_id);

        return response()->json(['proposal' => ProposalPresenter::present($fresh)]);
    }

    /**
     * Edit boxes send text; turn list fields back into lists before validation.
     *
     * @param  array<string, mixed>  $edits
     * @return array<string, mixed>
     */
    private function editsFromInput(array $edits): array
    {
        foreach ($edits as $key => $value) {
            if (is_string($key) && StudentFieldRegistry::has($key) && StudentFieldRegistry::get($key)['type'] === 'list' && is_string($value)) {
                $edits[$key] = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($item) => $item !== ''));
            }
        }

        return $edits;
    }
}
