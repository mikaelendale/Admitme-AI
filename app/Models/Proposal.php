<?php

namespace App\Models;

use App\Domain\Approvals\ProposalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A change an agent proposed, waiting for (or decided by) the student.
 */
class Proposal extends Model
{
    use HasUlids;

    public const TYPE_PROFILE_UPDATE = 'profile.update';

    public const TYPE_COLLEGE_LIST_ADD = 'college_list.add';

    /** Changes to colleges already on the list: field updates, removal, starting an application, adding a source. */
    public const TYPE_COLLEGE_LIST_UPDATE = 'college_list.update';

    /** Changes to existing applications: status/notes/portal fields, checklist step statuses, new checklist steps. */
    public const TYPE_APPLICATION_UPDATE = 'application.update';

    /** Accept or reject values read from the student's documents (the Documents review, decided in one go). */
    public const TYPE_DOCUMENT_VALUES = 'document.values';

    /** Values to type into a page in the student's browser (Phase 10); applying queues bridge commands. */
    public const TYPE_BROWSER_FILL = 'browser.fill';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'actor',
        'type',
        'status',
        'title',
        'items',
        'target_revision',
        'revision',
        'payload_hash',
        'expires_at',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'context' => 'array',
            'status' => ProposalStatus::class,
            'target_revision' => 'integer',
            'revision' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decision(): HasOne
    {
        return $this->hasOne(ProposalDecision::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Hash of the exact items the student reviews, so a decision authorizes only that payload.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function hashItems(string $type, array $items, int $revision): string
    {
        return hash('sha256', json_encode([$type, $revision, $items], JSON_UNESCAPED_UNICODE) ?: '');
    }
}
