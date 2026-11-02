<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The student's decision on a proposal and the authoritative receipt of what it changed.
 */
class ProposalDecision extends Model
{
    protected $fillable = [
        'proposal_id',
        'user_id',
        'decision',
        'selected_keys',
        'proposal_revision',
        'payload_hash',
        'idempotency_key',
        'receipt',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_keys' => 'array',
            'receipt' => 'array',
            'proposal_revision' => 'integer',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
