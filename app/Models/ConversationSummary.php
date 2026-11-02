<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A derived summary of older conversation turns. Never authoritative: saved facts win.
 */
class ConversationSummary extends Model
{
    protected $fillable = [
        'user_id',
        'conversation_id',
        'items',
        'covers_through_message_id',
        'message_count',
        'profile_revision',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'message_count' => 'integer',
            'profile_revision' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
