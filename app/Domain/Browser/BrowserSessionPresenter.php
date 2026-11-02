<?php

namespace App\Domain\Browser;

use App\Domain\Approvals\ProposalPresenter;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\Proposal;
use Illuminate\Support\Str;

/**
 * One JSON shape for a browser session, shared by the extension's API, the settings page and the
 * application workspace (so the panel and the web app always show the same steps and approvals).
 */
final class BrowserSessionPresenter
{
    /** Not heard from for this long: shown as waiting for the browser, never as finished. */
    public const QUIET_MINUTES = 2;

    /**
     * @return array<string, mixed>
     */
    public static function present(BrowserSession $session): array
    {
        $snapshot = $session->latestSnapshot;
        $proposals = Proposal::query()
            ->with('decision')
            ->where('user_id', $session->user_id)
            ->where('type', Proposal::TYPE_BROWSER_FILL)
            ->where('context->browser_session_id', (string) $session->getKey())
            ->latest('created_at')
            ->limit(5)
            ->get();

        return [
            'id' => (string) $session->id,
            'mode' => $session->mode ?? BrowserSession::MODE_FILL,
            'transport' => $session->transport,
            'simulated' => $session->isSimulated(),
            'origin' => $session->origin,
            'tab_id' => $session->tab_id,
            'application_id' => $session->application_id,
            'status' => self::status($session),
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            'current_url' => $session->current_url,
            'current_title' => $session->current_title,
            'snapshot' => $snapshot !== null ? ['id' => (string) $snapshot->id, 'url' => $snapshot->url, 'page_hash' => $snapshot->page_hash, 'fields' => count($snapshot->fields), 'at' => $snapshot->created_at?->toIso8601String()] : null,
            'commands' => $session->commands()->latest('created_at')->limit(50)->get()->map(fn (BrowserCommand $c) => [
                'id' => (string) $c->id,
                'type' => $c->type,
                'label' => $c->isAgent() ? self::agentLabel($c) : ($c->payload['label'] ?? null),
                'value' => $c->isAgent() ? null : ($c->payload['value'] ?? null),
                'status' => $c->status,
                'status_reason' => $c->status_reason,
                'observed' => $c->result['observed_value'] ?? null,
            ])->values()->all(),
            'proposals' => $proposals->map(fn (Proposal $p) => ProposalPresenter::present($p))->values()->all(),
        ];
    }

    /**
     * The agent-session shape the side panel polls (AgentSession in browser-extension/src/shared/agent.ts). No
     * queries: it is returned on every poll.
     *
     * @return array{id: string, mode: string, tab_id: int, status: string, current_url: string|null, current_title: string|null, last_seen_at: string|null}
     */
    public static function agent(BrowserSession $session): array
    {
        return [
            'id' => (string) $session->id,
            'mode' => BrowserSession::MODE_AGENT,
            'tab_id' => (int) $session->tab_id,
            'status' => self::status($session),
            'current_url' => $session->current_url,
            'current_title' => $session->current_title,
            'last_seen_at' => $session->last_seen_at?->toIso8601String(),
        ];
    }

    private static function status(BrowserSession $session): string
    {
        // An agent session's panel polls about every second: a short silence already means it is not there.
        $quietSince = $session->isAgent() ? now()->subSeconds(BrowserAgentService::CONTROL_SECONDS) : now()->subMinutes(self::QUIET_MINUTES);

        return $session->status === 'active' && $session->last_seen_at?->lt($quietSince) ? 'waiting_for_browser' : $session->status;
    }

    /**
     * What the mentor did, in a few words ("Open apply.example.edu/start", "Fill 6 fields", "Click Next").
     */
    private static function agentLabel(BrowserCommand $command): string
    {
        $payload = (array) $command->payload;
        $clicked = $command->result['data']['clicked'] ?? null;

        return match ($command->type) {
            'agent_open' => 'Open '.(BrowserAgentService::describeUrl(is_string($payload['url'] ?? null) ? $payload['url'] : null) ?? 'a page'),
            'agent_read' => 'Read the page',
            'agent_fill' => 'Fill '.count((array) ($payload['fields'] ?? [])).' field(s)',
            'agent_click' => 'Click '.(is_string($clicked) && $clicked !== '' ? Str::limit($clicked, 60, '…') : ($payload['id'] ?? 'a control')),
            'agent_key' => 'Press '.($payload['key'] ?? 'a key'),
            default => $command->type,
        };
    }
}
