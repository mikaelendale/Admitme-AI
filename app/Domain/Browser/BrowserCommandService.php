<?php

namespace App\Domain\Browser;

use App\Domain\Approvals\ProposalStatus;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\ExtensionToken;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Commands the extension performs: created only from approved proposals, bound to session/tab/origin/page,
 * leased one at a time, executed once, read back, and acknowledged. A lost acknowledgement is reconciled
 * (the extension reads the field before acting again); anything uncertain is frozen for the student to inspect.
 */
final class BrowserCommandService
{
    /**
     * Called by the shared approval flow when a browser-fill proposal is approved.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: ProposalStatus, 1: array<string, string>}
     */
    public function queueApprovedFills(User $student, Proposal $proposal, array $items): array
    {
        $context = (array) $proposal->context;
        $session = BrowserSession::query()->where('user_id', $student->getKey())->find($context['browser_session_id'] ?? null);
        $current = $session?->latestSnapshot;

        // The page changed (or the session ended) since the student reviewed this: nothing is queued.
        if ($session === null || $session->status === 'closed' || $current === null || $current->page_hash !== ($context['page_hash'] ?? null)) {
            return [ProposalStatus::Stale, array_fill_keys(array_column($items, 'key'), 'stale')];
        }

        $fields = collect($current->fields)->keyBy('field_id');
        $outcomes = [];

        foreach ($items as $item) {
            $field = $fields->get($item['key']);

            if ($field === null) {
                $outcomes[$item['key']] = 'stale';

                continue;
            }

            BrowserCommand::query()->firstOrCreate(['idempotency_key' => $proposal->getKey().':'.$item['key'].':r'.$proposal->revision], [
                'browser_session_id' => $session->getKey(),
                'user_id' => $student->getKey(),
                'proposal_id' => (string) $proposal->getKey(),
                'proposal_revision' => (int) $proposal->revision,
                'type' => $field['type'] === 'select' ? 'select_option' : 'fill_field',
                'payload' => ['field_id' => $item['key'], 'label' => $item['label'], 'value' => (string) $item['after'], 'page_hash' => $current->page_hash],
                'snapshot_id' => (string) $current->getKey(),
                'tab_id' => $session->tab_id,
                'origin' => $session->origin,
                'status' => 'pending',
            ]);

            $outcomes[$item['key']] = 'applied';
        }

        return [ProposalStatus::Applied, $outcomes];
    }

    /**
     * Lease the commands the extension may run now. The caller must be the session's device, on the
     * session's tab and origin, looking at the same page structure the commands were approved for.
     *
     * @return array{status: string, commands: list<array<string, mixed>>, message?: string}
     */
    public function lease(BrowserSession $session, ExtensionToken $token, int $tabId, string $origin, string $pageHash): array
    {
        if ((int) $session->extension_token_id !== (int) $token->id || (int) $session->user_id !== (int) $token->user_id) {
            return ['status' => 'forbidden', 'commands' => [], 'message' => 'This session belongs to another device.'];
        }

        $session->forceFill(['last_seen_at' => now(), 'status' => $session->status === 'closed' ? 'closed' : 'active'])->save();

        if ($tabId !== (int) $session->tab_id || rtrim($origin, '/') !== $session->origin) {
            return ['status' => 'wrong_tab', 'commands' => [], 'message' => "These steps are for the tab showing {$session->origin}. Switch back to it."];
        }

        return DB::transaction(function () use ($session, $pageHash) {
            $candidates = BrowserCommand::query()
                ->where('browser_session_id', $session->getKey())
                ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'leased')->where('lease_expires_at', '<', now())))
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $leased = [];

            foreach ($candidates as $command) {
                if (($command->payload['page_hash'] ?? null) !== $pageHash) {
                    $command->update(['status' => 'stale', 'status_reason' => 'The page changed after this was approved. Observe again and approve a new fill.']);

                    continue;
                }

                // A command leased before without an acknowledgement may already have run: reconcile first.
                $reconcile = $command->status === 'leased';
                $command->update(['status' => 'leased', 'lease_expires_at' => now()->addSeconds(max(5, (int) config('admitme.browser.command_lease_seconds', 60))), 'attempts' => $command->attempts + 1]);
                $leased[] = ['id' => (string) $command->id, 'type' => $command->type, 'field_id' => $command->payload['field_id'], 'value' => $command->payload['value'], 'label' => $command->payload['label'], 'reconcile' => $reconcile];
            }

            return ['status' => 'ok', 'commands' => $leased];
        });
    }

    /**
     * Record what the extension observed after acting. Completed commands are never run again.
     *
     * @param  array{outcome: string, observed_value?: string|null, message?: string|null}  $result
     */
    public function acknowledge(BrowserCommand $command, ExtensionToken $token, array $result): BrowserCommand
    {
        return DB::transaction(function () use ($command, $token, $result) {
            /** @var BrowserCommand $locked */
            $locked = BrowserCommand::query()->lockForUpdate()->findOrFail($command->getKey());

            if ((int) $locked->session->extension_token_id !== (int) $token->id) {
                abort(404);
            }

            if (in_array($locked->status, ['completed', 'stale', 'cancelled'], true)) {
                return $locked;
            }

            $expected = (string) $locked->payload['value'];
            $observed = isset($result['observed_value']) ? (string) $result['observed_value'] : null;

            [$status, $reason] = match ($result['outcome'] ?? 'uncertain') {
                'done' => $observed === $expected
                    ? ['completed', null]
                    : ['frozen', "The field reads \"{$observed}\" after filling, not \"{$expected}\". Check the page before doing anything else."],
                'failed' => ['failed', (string) ($result['message'] ?? 'The extension could not do this step.')],
                default => ['frozen', 'The extension could not confirm what happened. Check the page before doing anything else.'],
            };

            $locked->update([
                'status' => $status,
                'status_reason' => $reason,
                'result' => ['outcome' => $result['outcome'] ?? 'uncertain', 'observed_value' => $observed, 'at' => now()->toIso8601String()],
                'lease_expires_at' => null,
                'completed_at' => $status === 'completed' ? now() : null,
            ]);

            return $locked;
        });
    }
}
