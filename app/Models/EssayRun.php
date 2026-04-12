<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EssayRun extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPE_BRAINSTORM = 'brainstorm';

    public const TYPE_DRAFT = 'draft';

    public const ESSAY_TYPE_COMMON_APP = 'common_app';

    public const ESSAY_TYPE_SUPPLEMENTAL = 'supplemental';

    public const ESSAY_TYPE_WHY_SCHOOL = 'why_school';

    /**
     * @return list<string>
     */
    public static function essayTypes(): array
    {
        return [
            self::ESSAY_TYPE_COMMON_APP,
            self::ESSAY_TYPE_SUPPLEMENTAL,
            self::ESSAY_TYPE_WHY_SCHOOL,
        ];
    }

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'college_list_school_id',
        'run_type',
        'status',
        'user_prompt',
        'output_markdown',
        'title_for_card',
        'error_message',
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
            'meta' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collegeListSchool(): BelongsTo
    {
        return $this->belongsTo(CollegeListSchool::class, 'college_list_school_id');
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
            self::STATUS_PROCESSING,
        ];
    }
}
