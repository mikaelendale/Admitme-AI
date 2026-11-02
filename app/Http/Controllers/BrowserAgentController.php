<?php

namespace App\Http\Controllers;

use App\Domain\Browser\BrowserAgentService;
use App\Domain\Browser\BrowserSessionPresenter;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\ExtensionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mentor browser control, the side panel's side (bearer token; contract in browser-extension/src/shared/agent.ts):
 * start control of a tab, poll for the mentor's next action (also the panel's heartbeat), report what happened.
 * Stopping is the existing DELETE /api/browser/sessions/{session}.
 */
class BrowserAgentController extends Controller
{
    public function start(Request $request, BrowserAgentService $agent): JsonResponse
    {
        $validated = $request->validate([
            'tab_id' => ['required', 'integer', 'min:0'],
            'url' => ['nullable', 'string'],
        ]);

        $session = $agent->start($this->token($request), (int) $validated['tab_id'], $validated['url'] ?? null);

        return response()->json(['session' => BrowserSessionPresenter::agent($session)], 201);
    }

    public function next(Request $request, BrowserSession $session, BrowserAgentService $agent): JsonResponse
    {
        $validated = $request->validate([
            'tab_id' => ['required', 'integer', 'min:0'],
            'url' => ['nullable', 'string'],
        ]);

        $next = $agent->next($session, $this->token($request), (int) $validated['tab_id'], $validated['url'] ?? null);

        if ($next['status'] !== 'ok') {
            return response()->json(['status' => $next['status'], 'message' => $next['message'] ?? null], match ($next['status']) {
                'forbidden' => 404,
                'wrong_tab' => 409,
                default => 410,
            });
        }

        return response()->json(['status' => 'ok', 'command' => $next['command'], 'session' => BrowserSessionPresenter::agent($next['session'])]);
    }

    public function result(Request $request, BrowserCommand $command, BrowserAgentService $agent): JsonResponse
    {
        abort_unless((int) $command->user_id === (int) $request->user()->getKey() && $command->isAgent(), 404);

        $validated = $request->validate([
            'outcome' => ['required', 'in:done,failed,declined'],
            'message' => ['nullable', 'string', 'max:2000'],
            'data' => ['nullable', 'array'],
        ]);

        $command = $agent->report($command, $this->token($request), $validated);

        return response()->json(['command' => ['id' => (string) $command->id, 'status' => $command->status]]);
    }

    private function token(Request $request): ExtensionToken
    {
        return $request->attributes->get('extension_token');
    }
}
