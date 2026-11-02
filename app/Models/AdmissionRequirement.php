<?php

namespace App\Models;

use App\Models\Concerns\HasKnowledgeVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admissions assertion (a deadline, fee, test policy…) scoped to an institution, programme and cycle,
 * with the source and verbatim quote it came from. Extraction is not verification. Whether two sources
 * disagree depends on which sources the viewer can see, so conflicts are computed when read, not stored.
 */
class AdmissionRequirement extends Model
{
    use HasKnowledgeVisibility;

    /**
     * `cost` is a yearly cost of attendance or tuition figure; `aid` is a financial-aid policy or aid form
     * (both used for affordability, never as a guarantee of an aid offer).
     */
    public const TYPES = ['deadline', 'fee', 'test_policy', 'english_test', 'document', 'essay', 'recommendation', 'interview', 'cost', 'aid', 'other'];

    public const REVIEW_UNVERIFIED = 'unverified';

    public const REVIEW_VERIFIED = 'verified';

    protected $fillable = [
        'institution_id',
        'owner_user_id',
        'program',
        'cycle_label',
        'requirement_type',
        'label',
        'value_text',
        'deadline_date',
        'deadline_time',
        'timezone',
        'amount',
        'currency',
        'conditions',
        'applicant_scope',
        'knowledge_source_id',
        'knowledge_source_version_id',
        'quote',
        'review_status',
        'verified_by_user_id',
        'verified_at',
        'retired_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deadline_date' => 'date',
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    /**
     * @param  Builder<AdmissionRequirement>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('retired_at');
    }
}
