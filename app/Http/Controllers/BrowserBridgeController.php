<?php

namespace App\Http\Controllers;

use App\Domain\Approvals\ProposalPresenter;
use App\Domain\Approvals\ProposalService;
use App\Domain\Browser\BrowserBridge;
use App\Domain\Browser\BrowserCommandService;
use App\Domain\Browser\BrowserSessionPresenter;
use App\Models\Application;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\ExtensionToken;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The extension's API (bearer token). Every call is scoped to the token's student and device.
 */
class BrowserBridgeController extends Controller
{
    public function link(Request $request, BrowserBridge $bridge): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:20'], 'device_label' => ['nullable', 'string', 'max:120']]);

        return response()->json($bridge->link($validated['code'], $validated['device_label'] ?? 'Browser'), 201);
    }

    public function applications(Request $request): JsonResponse
    {
        return response()->json(['applications' => Application::query()->where('user_id', $request->user()->getKey())->with('collegeListSchool:id,school_name')->get()
            ->map(fn (Application $a) => ['id' => (string) $a->id, 'college' => $a->collegeListSchool?->school_name])->values()->all()]);
    }

    public function startSession(Request $request, BrowserBridge $bridge): JsonResponse
    {
        $validated = $request->validate([
            'origin' => ['required', 'string', 'max:255'],
            'tab_id' => ['required', 'integer', 'min:0'],
            'application_id' => ['nullable', 'string', 'size:26'],
        ]);

        $session = $bridge->startSession($this->token($request), $validated['origin'], (int) $validated['tab_id'], $validated['application_id'] ?? null);

        return response()->json(['session' => BrowserSessionPresenter::present($session)], 201);
    }

    public function showSession(Request $request, BrowserSession $session): JsonResponse
    {
        $this->ownSession($request, $session);

        // The open side panel refreshes this: it is the extension's heartbeat (written at most every 30s).
        if ($session->status !== 'closed' && ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subSeconds(30)))) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }

        return response()->json(['session' => BrowserSessionPresenter::present($session)]);
    }

    public function snapshot(Request $request, BrowserSession $session, BrowserBridge $bridge): JsonResponse
    {
        $this->ownFillSession($request, $session);
        $validated = $request->validate(['url' => ['required', 'string', 'max:2048'], 'fields' => ['present', 'array', 'max:500']]);

        $snapshot = $bridge->recordSnapshot($session, $validated['url'], $validated['fields']);

        return response()->json(['snapshot' => ['id' => (string) $snapshot->id, 'page_hash' => $snapshot->page_hash, 'fields' => count($snapshot->fields)], 'session' => BrowserSessionPresenter::present($session->refresh())], 201);
    }

    public function fillProposal(Request $request, BrowserSession $session, BrowserBridge $bridge): JsonResponse
    {
        $this->ownFillSession($request, $session);

        return response()->json(['proposal' => ProposalPresenter::present($bridge->proposeFillFromPlan($request->user(), $session))], 201);
    }

    public function commands(Request $request, BrowserSession $session, BrowserCommandService $commands): JsonResponse
    {
        $this->ownFillSession($request, $session);
        $validated = $request->validate(['tab_id' => ['required', 'integer'], 'origin' => ['required', 'string', 'max:255'], 'page_hash' => ['required', 'string', 'size:64']]);

        $lease = $commands->lease($session, $this->token($request), (int) $validated['tab_id'], $validated['origin'], $validated['page_hash']);

        return response()->json($lease, match ($lease['status']) {
            'forbidden' => 404,
            'wrong_tab' => 409,
            default => 200,
        });
    }

    public function result(Request $request, BrowserCommand $command, BrowserCommandService $commands): JsonResponse
    {
        // The mentor's actions report to agent-commands/{command}/result (BrowserAgentController).
        abort_unless((int) $command->user_id === (int) $request->user()->getKey() && ! $command->isAgent(), 404);
        $validated = $request->validate(['outcome' => ['required', 'in:done,failed,uncertain'], 'observed_value' => ['nullable', 'string', 'max:20000'], 'message' => ['nullable', 'string', 'max:500']]);

        $command = $commands->acknowledge($command, $this->token($request), $validated);

        return response()->json(['command' => ['id' => (string) $command->id, 'status' => $command->status, 'status_reason' => $command->status_reason]]);
    }

    /**
     * The same decision flow as the web app's approval card (browser-fill proposals only, from the panel).
     */
    public function decide(Request $request, Proposal $proposal, ProposalService $proposals): JsonResponse
    {
        abort_unless((int) $proposal->user_id === (int) $request->user()->getKey() && $proposal->type === Proposal::TYPE_BROWSER_FILL, 404);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'selected_keys' => ['sometimes', 'array', 'max:200'],
            'selected_keys.*' => ['string', 'max:20'],
            'proposal_revision' => ['required', 'integer', 'min:1'],
            'payload_hash' => ['required', 'string', 'size:64'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $decision = $proposals->decide($request->user(), $proposal, $validated['decision'], $validated['selected_keys'] ?? [], [], (int) $validated['proposal_revision'], $validated['payload_hash'], $validated['idempotency_key'] ?? null);

        return response()->json(['proposal' => ProposalPresenter::present(Proposal::query()->with('decision')->findOrFail($decision->proposal_id))]);
    }

    /**
     * Ends a fill session or the mentor's browser control (the panel's Stop): queued and running steps are cancelled,
     * and the panel's next poll of an agent session answers 410.
     */
    public function closeSession(Request $request, BrowserSession $session): JsonResponse
    {
        $this->ownSession($request, $session);
        $session->update(['status' => 'closed']);
        $session->commands()->whereIn('status', ['pending', 'leased'])->update(['status' => 'cancelled', 'status_reason' => 'The session was closed.', 'lease_expires_at' => null]);

        return response()->json(['session' => BrowserSessionPresenter::present($session->refresh())]);
    }

    private function token(Request $request): ExtensionToken
    {
        return $request->attributes->get('extension_token');
    }

    private function ownSession(Request $request, BrowserSession $session): void
    {
        abort_unless((int) $session->user_id === (int) $request->user()->getKey() && (int) $session->extension_token_id === (int) $this->token($request)->id, 404);
    }

    /**
     * Observing, proposing and leasing fills are for fill sessions only; the mentor's browser control has its own API.
     */
    private function ownFillSession(Request $request, BrowserSession $session): void
    {
        $this->ownSession($request, $session);
        abort_if($session->isAgent(), 404);
    }
}
