<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stream part of a background mentor turn (the same Vercel UI message stream parts the chat reads), in order.
 *
 * @property int $id
 * @property string $mentor_turn_id
 * @property int $seq
 * @property string $payload
 */
class MentorTurnEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['mentor_turn_id', 'seq', 'payload', 'created_at'];

    protected function casts(): array
    {
        return ['seq' => 'integer'];
    }

    public function turn(): BelongsTo
    {
        return $this->belongsTo(MentorTurn::class, 'mentor_turn_id');
    }
}
