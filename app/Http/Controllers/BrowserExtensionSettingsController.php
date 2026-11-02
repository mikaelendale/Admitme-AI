<?php

namespace App\Http\Controllers;

use App\Domain\Browser\BrowserAgentService;
use App\Domain\Browser\BrowserBridge;
use App\Domain\Browser\BrowserSessionPresenter;
use App\Models\Application;
use App\Models\BrowserSession;
use App\Models\ExtensionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Linking the browser extension (one-time code → scoped token), seeing and revoking linked devices, and
 * preparing a fill from the page the extension observed (the student approves it like any proposal).
 */
class BrowserExtensionSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/browser-extension', [
            'devices' => ExtensionToken::query()->where('user_id', $user->getKey())->latest()->get()->map(fn (ExtensionToken $t) => [
                'id' => $t->id,
                'label' => $t->device_label,
                'lastUsedAt' => $t->last_used_at?->toIso8601String(),
                'expiresAt' => $t->expires_at->toIso8601String(),
                'revoked' => $t->revoked_at !== null,
            ])->values()->all(),
            'sessions' => BrowserSession::query()->where('user_id', $user->getKey())->latest()->limit(10)->get()->map(fn (BrowserSession $s) => BrowserSessionPresenter::present($s))->values()->all(),
            'serverUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function createCode(Request $request, BrowserBridge $bridge): JsonResponse
    {
        return response()->json(['code' => $bridge->issueLinkCode($request->user()), 'expires_in_minutes' => 10], 201);
    }

    public function revoke(Request $request, ExtensionToken $token, BrowserAgentService $agent): RedirectResponse
    {
        abort_unless((int) $token->user_id === (int) $request->user()->getKey(), 404);

        $token->update(['revoked_at' => now()]);
        $open = BrowserSession::query()->where('extension_token_id', $token->id)->where('status', '!=', 'closed')->pluck('id')->all();
        BrowserSession::query()->whereKey($open)->update(['status' => 'closed']);
        $agent->cancelOpenCommands($open, 'The browser was unlinked.');

        return back()->with('status', 'device-revoked');
    }

    /**
     * From the web app: end the mentor's control of the student's browser tab (the same as Stop in the side panel).
     */
    public function stopControl(Request $request, BrowserAgentService $agent): JsonResponse|RedirectResponse
    {
        $stopped = $agent->stop($request->user());

        return $request->expectsJson() ? response()->json(['stopped' => $stopped]) : back()->with('status', 'browser-control-stopped');
    }

    /**
     * From the application workspace: prepare a fill for the latest page the extension observed.
     */
    public function prepareFill(Request $request, Application $application, BrowserBridge $bridge): JsonResponse
    {
        abort_unless((int) $application->user_id === (int) $request->user()->getKey(), 404);

        $session = BrowserSession::query()->where('user_id', $request->user()->getKey())->where('application_id', $application->getKey())->where('status', '!=', 'closed')->latest('updated_at')->first();
        abort_if($session === null, 422, 'Open the application form in your browser and press "Observe this page" in the AdmitMe extension first.');

        return response()->json(['proposal_id' => (string) $bridge->proposeFillFromPlan($request->user(), $session, 'application-workspace')->getKey()], 201);
    }
}
