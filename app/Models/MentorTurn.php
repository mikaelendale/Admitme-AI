<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One mentor reply running in the background (see RunMentorTurnJob). Its stream parts are kept in order in
 * mentor_turn_events so the chat can follow the reply and pick it up again after a page refresh.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $conversation_id
 * @property string $mode
 * @property string $message
 * @property list<string>|null $document_ids
 * @property list<string>|null $attachments
 * @property string $status
 * @property string|null $error
 * @property int $event_count
 */
class MentorTurn extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Statuses of a turn that is still producing a reply. */
    public const ACTIVE = [self::QUEUED, self::RUNNING];

    protected $fillable = [
        'user_id',
        'conversation_id',
        'mode',
        'message',
        'document_ids',
        'attachments',
        'status',
        'error',
        'event_count',
        'cancel_requested_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'document_ids' => 'array',
            'attachments' => 'array',
            'event_count' => 'integer',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MentorTurnEvent::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
