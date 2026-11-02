<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A linked browser extension's scoped, revocable credential. Only its SHA-256 hash is stored.
 */
class ExtensionToken extends Model
{
    public const ABILITY_SESSION = 'browser:session';

    protected $fillable = ['user_id', 'device_label', 'token_hash', 'abilities', 'last_used_at', 'expires_at', 'revoked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
