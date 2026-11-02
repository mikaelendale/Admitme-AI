<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One assisted browsing session, bound to a student, a linked device and one tab. A fill session (Phase 10) is also
 * bound to one origin; an agent session is the tab the student gave the mentor (see BrowserAgentService).
 */
class BrowserSession extends Model
{
    use HasUlids;

    /** Approved fills on one origin and page (Phase 10). */
    public const MODE_FILL = 'fill';

    /** The mentor works in the tab (opens, reads, fills, clicks) while the side panel is open. */
    public const MODE_AGENT = 'agent';

    protected $fillable = ['user_id', 'extension_token_id', 'application_id', 'transport', 'mode', 'origin', 'tab_id', 'status', 'latest_snapshot_id', 'checkpoint', 'last_seen_at', 'current_url', 'current_title'];

    protected $attributes = ['mode' => self::MODE_FILL];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['checkpoint' => 'array', 'last_seen_at' => 'datetime', 'tab_id' => 'integer'];
    }

    public function isAgent(): bool
    {
        return $this->mode === self::MODE_AGENT;
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(BrowserSnapshot::class)->latest('created_at');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(BrowserCommand::class);
    }

    public function latestSnapshot(): BelongsTo
    {
        return $this->belongsTo(BrowserSnapshot::class, 'latest_snapshot_id');
    }

    public function isSimulated(): bool
    {
        return $this->transport === 'simulated';
    }
}
