<?php

namespace App\Models;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\SubmissionEvidence;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's application to one college choice.
 */
class Application extends Model
{
    use HasFactory, HasUlids;

    /**
     * Application systems we can name; `other` covers any portal not listed.
     *
     * @var list<string>
     */
    public const SYSTEMS = ['common_app', 'coalition', 'ucas', 'institution_portal', 'other'];

    protected $fillable = [
        'user_id',
        'college_list_school_id',
        'status',
        'application_system',
        'portal_url',
        'cycle_label',
        'notes',
        'submitted_at',
        'submission_evidence_type',
        'submission_evidence_note',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'preparing',
        'revision' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'submission_evidence_type' => SubmissionEvidence::class,
            'submitted_at' => 'datetime',
            'checklist_built_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ApplicationItem::class)->orderBy('position');
    }

    public function collegeListSchool(): BelongsTo
    {
        return $this->belongsTo(CollegeListSchool::class);
    }
}
