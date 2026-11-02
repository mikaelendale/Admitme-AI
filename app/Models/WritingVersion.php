<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One saved state of the student's text. Immutable: a change is always a new version.
 */
class WritingVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['writing_piece_id', 'user_id', 'number', 'body', 'word_count', 'author', 'body_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number' => 'integer', 'word_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Writing versions are immutable; save a new version instead.'));
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(WritingPiece::class, 'writing_piece_id');
    }
}
