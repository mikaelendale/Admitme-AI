<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

class CollegeListSchool extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** Tool / legacy UI bucket names (maps to {@see $tier} via {@see listTypeToTier()}). */
    public const LIST_TYPES = ['reach', 'target', 'safety'];

    /** @var list<string> */
    public const APPLICATION_STATUSES = [
        'not_started',
        'researching',
        'applying',
        'submitted',
        'accepted',
        'rejected',
        'waitlisted',
    ];

    protected $fillable = [
        'user_id',
        'college_list_run_id',
        'school_name',
        'school_slug',
        'tier',
        'fit_score',
        'why_blurb',
        'acceptance_rate',
        'median_gpa',
        'median_sat',
        'median_act',
        'ea_deadline',
        'ed_deadline',
        'rd_deadline',
        'application_portal_url',
        'admissions_cycle_label',
        'aid_estimate_band',
        'notable_programs',
        'campus_size',
        'location_city',
        'location_state',
        'is_removed',
        'application_status',
        'student_notes',
        'student_metadata',
        'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notable_programs' => 'array',
            'is_removed' => 'boolean',
            'generated_at' => 'datetime',
            'ea_deadline' => 'date',
            'ed_deadline' => 'date',
            'rd_deadline' => 'date',
            'acceptance_rate' => 'decimal:2',
            'median_gpa' => 'decimal:2',
            'median_sat' => 'integer',
            'median_act' => 'integer',
            'fit_score' => 'integer',
            'student_metadata' => 'array',
        ];
    }

    public static function listTypeToTier(string $listType): string
    {
        return $listType === 'target' ? 'match' : $listType;
    }

    public static function tierToListType(string $tier): string
    {
        return $tier === 'match' ? 'target' : $tier;
    }

    protected static function booted(): void
    {
        static::creating(function (CollegeListSchool $model): void {
            if (empty($model->school_slug) && ! empty($model->school_name)) {
                $model->school_slug = Str::slug($model->school_name);
            }
        });
    }

    /**
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_removed', false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
