<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only record of a change to student-owned data.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'revision_before',
        'revision_after',
        'changes',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'revision_before' => 'integer',
            'revision_after' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
