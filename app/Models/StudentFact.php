<?php

namespace App\Models;

use App\Domain\Students\FactStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provenance for one recorded value of a tracked student field.
 */
class StudentFact extends Model
{
    protected $fillable = [
        'user_id',
        'field_key',
        'value',
        'status',
        'source_type',
        'source_ref',
        'actor_type',
        'profile_revision',
        'superseded_at',
        'review_state',
        'note',
    ];

    /** Candidate awaiting the student's review. */
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_REJECTED = 'rejected';

    /** Its document version was replaced before it was reviewed. */
    public const REVIEW_SUPERSEDED = 'superseded';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'source_ref' => 'array',
            'status' => FactStatus::class,
            'profile_revision' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Facts describing the value currently stored on the profile (not superseded, not a pending candidate).
     *
     * @param  Builder<StudentFact>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('superseded_at')->whereIn('status', [FactStatus::Confirmed, FactStatus::Inferred]);
    }
}
