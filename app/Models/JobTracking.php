<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobTracking extends Model
{
    protected $table = 'job_tracking';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const TYPE_AI_CONTEXT = 'ai_context';

    public const TYPE_ESSAY_RUN = 'essay_run';

    public const TYPE_COLLEGE_LIST_COUNCIL = 'college_list_council';

    public const TYPE_COUNCIL_PREFLIGHT = 'council_preflight';

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'job_type',
        'status',
        'payload',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Friendly label for the agent / UI.
     */
    public function label(): string
    {
        return match ($this->job_type) {
            self::TYPE_AI_CONTEXT => 'Rebuilding your profile context',
            self::TYPE_ESSAY_RUN => 'Generating your essay',
            self::TYPE_COLLEGE_LIST_COUNCIL => 'Building your college list',
            self::TYPE_COUNCIL_PREFLIGHT => 'Resuming college list preflight',
            default => 'Background task',
        };
    }

    /**
     * Human-readable "started X ago" string for the agent.
     */
    public function startedAgo(): string
    {
        if ($this->started_at === null) {
            return 'just now';
        }

        $diff = now()->diffInSeconds($this->started_at);
        if ($diff < 60) {
            return "{$diff} second".($diff === 1 ? '' : 's').' ago';
        }
        $mins = (int) floor($diff / 60);

        return "{$mins} minute".($mins === 1 ? '' : 's').' ago';
    }

    /**
     * URL to retry this specific tracking record.
     */
    public function retryUrl(): string
    {
        return url("/jobs/{$this->id}/retry");
    }
}
