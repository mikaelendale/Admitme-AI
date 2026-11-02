<?php

namespace App\Models;

use App\Domain\Applications\ChecklistOwner;
use App\Domain\Applications\ChecklistStatus;
use App\Domain\Applications\CompletionBasis;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an application: a requirement from a source, a default step, or one the student added.
 */
class ApplicationItem extends Model
{
    use HasUlids;

    public const KINDS = ['deadline', 'form', 'fee', 'test', 'english_test', 'document', 'essay', 'recommendation', 'interview', 'financial_aid', 'portal_step', 'other'];

    public const ORIGIN_REQUIREMENT = 'requirement';

    public const ORIGIN_DEFAULT = 'default';

    public const ORIGIN_MANUAL = 'manual';

    protected $fillable = [
        'application_id',
        'user_id',
        'item_key',
        'kind',
        'title',
        'details',
        'origin',
        'admission_requirement_id',
        'owner_type',
        'owner_label',
        'status',
        'status_note',
        'due_date',
        'due_time',
        'timezone',
        'document_id',
        'completed_at',
        'completion_basis',
        'source_missing_at',
        'position',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_type' => ChecklistOwner::class,
            'status' => ChecklistStatus::class,
            'completion_basis' => CompletionBasis::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'source_missing_at' => 'datetime',
            'position' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(AdmissionRequirement::class, 'admission_requirement_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
