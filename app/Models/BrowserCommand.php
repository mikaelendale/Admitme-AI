<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One action for the extension to perform once. A fill command (Phase 10) comes from an approved proposal and is
 * bound to session, tab, origin and snapshot; an agent command is the mentor's action in the tab the student gave it.
 */
class BrowserCommand extends Model
{
    use HasUlids;

    public const TYPES = ['observe_page', 'fill_field', 'select_option', 'click_supported_control'];

    /** The mentor's actions in an agent session (browser-extension/src/shared/agent.ts). */
    public const AGENT_TYPES = ['agent_open', 'agent_read', 'agent_fill', 'agent_click', 'agent_key'];

    /** The browser reported back (or the student declined): never handed out or re-recorded. */
    public const AGENT_FINISHED = ['completed', 'failed', 'declined'];

    protected $fillable = ['browser_session_id', 'user_id', 'proposal_id', 'proposal_revision', 'type', 'payload', 'snapshot_id', 'tab_id', 'origin', 'idempotency_key', 'status', 'lease_expires_at', 'attempts', 'result', 'status_reason', 'completed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'lease_expires_at' => 'datetime', 'completed_at' => 'datetime', 'tab_id' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BrowserSession::class, 'browser_session_id');
    }

    public function isAgent(): bool
    {
        return in_array($this->type, self::AGENT_TYPES, true);
    }
}
