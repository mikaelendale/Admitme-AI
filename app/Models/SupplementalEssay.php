<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplementalEssay extends Model
{
    use HasUlids;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPE_WHY_SCHOOL = 'why_school';

    public const TYPE_ACTIVITY = 'activity';

    public const TYPE_DIVERSITY = 'diversity';

    public const TYPE_COMMUNITY = 'community';

    public const TYPE_ADDITIONAL_INFO = 'additional_info';

    public const TYPE_SHORT_ANSWER = 'short_answer';

    public const TYPE_OTHER = 'other';

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DRAFTED = 'drafted';

    public const STATUS_FINAL = 'final';

    /**
     * @return list<string>
     */
    public static function essayTypes(): array
    {
        return [
            self::TYPE_WHY_SCHOOL,
            self::TYPE_ACTIVITY,
            self::TYPE_DIVERSITY,
            self::TYPE_COMMUNITY,
            self::TYPE_ADDITIONAL_INFO,
            self::TYPE_SHORT_ANSWER,
            self::TYPE_OTHER,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_NOT_STARTED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_DRAFTED,
            self::STATUS_FINAL,
        ];
    }

    protected $fillable = [
        'user_id',
        'college_list_school_id',
        'school_name',
        'prompt_text',
        'word_limit',
        'essay_type',
        'status',
        'content',
        'notes',
        'due_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
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

    public static function navBadgeCountFor(User $user): int
    {
        return (int) static::query()
            ->where('user_id', $user->id)
            ->where('status', self::STATUS_NOT_STARTED)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays(14)->toDateString())
            ->where('due_date', '>=', now()->toDateString())
            ->count();
    }
}
