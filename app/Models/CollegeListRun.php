<?php

namespace App\Models;

use Database\Factories\CollegeListRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollegeListRun extends Model
{
    /** @use HasFactory<CollegeListRunFactory> */
    use HasFactory, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PREFLIGHT = 'preflight';

    /** Waiting for optional student clarifications in the council UI (or auto-continue after timeout). */
    public const STATUS_AWAITING_PREFLIGHT = 'awaiting_preflight';

    public const STATUS_SCOUTING = 'scouting';

    public const STATUS_JUDGING = 'judging';

    public const STATUS_ASSEMBLING = 'assembling';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'status',
        'intent',
        'request_message',
        'error_message',
        'preflight_payload',
        'scout_payload',
        'judge_payload',
        'summary_text',
        'meta',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preflight_payload' => 'array',
            'scout_payload' => 'array',
            'judge_payload' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CollegeListRunEvent::class, 'college_list_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_PREFLIGHT,
            self::STATUS_AWAITING_PREFLIGHT,
            self::STATUS_SCOUTING,
            self::STATUS_JUDGING,
            self::STATUS_ASSEMBLING,
        ];
    }
}
