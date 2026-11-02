<?php

namespace App\Models;

use App\Domain\Students\ProfileProvenanceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(ProfileProvenanceObserver::class)]
class OnboardingProfile extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Set by StudentProfileService when it records provenance itself, so the observer does not record the save again.
     */
    public bool $provenanceHandled = false;

    /**
     * Tracked changes captured while saving, written as provenance once the save succeeds.
     *
     * @var array{changes: array<string, array{0: mixed, 1: mixed}>, revision_before: int|null}|null
     */
    public ?array $pendingProvenance = null;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
    ];

    protected $fillable = [
        'user_id',
        'status',
        'age_range',
        'is_age_13_or_older_confirmed',
        'legal_accepted_at',
        'legal_accepted_version',
        'current_grade',
        'graduation_year',
        'curriculum',
        'gpa_value',
        'gpa_scale',
        'gpa_type',
        'gpa_normalized_4',
        'sat_score',
        'act_score',
        'test_optional_status',
        'citizenship_residency',
        'location_country',
        'location_region',
        'dream_schools_json',
        'region_preferences_json',
        'campus_size_pref',
        'financial_aid_need_percent',
        'activities_extracted_json',
        'awards_extracted_json',
        'grade_trend',
        'spike_summary',
        'resume_pdf_unreadable',
        'intended_majors_json',
        'target_countries_json',
        'budget_band',
        'profile_completeness_score',
        'student_summary',
        'profile_dump_text',
        'extraction_draft_json',
        'extraction_source_hash',
        'last_profile_extraction_at',
        'profile_modes_json',
        'profile_completion_state',
        'completeness_callouts_json',
        'open_questions_json',
        'last_ai_structured_at',
        'ai_context',
        'last_ai_context_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_age_13_or_older_confirmed' => 'boolean',
            'legal_accepted_at' => 'datetime',
            'last_ai_structured_at' => 'datetime',
            'intended_majors_json' => 'array',
            'target_countries_json' => 'array',
            'open_questions_json' => 'array',
            'extraction_draft_json' => 'array',
            'profile_modes_json' => 'array',
            'profile_completion_state' => 'array',
            'completeness_callouts_json' => 'array',
            'dream_schools_json' => 'array',
            'region_preferences_json' => 'array',
            'activities_extracted_json' => 'array',
            'awards_extracted_json' => 'array',
            'ai_context' => 'array',
            'last_ai_context_at' => 'datetime',
            'last_profile_extraction_at' => 'datetime',
            'gpa_value' => 'decimal:2',
            'gpa_scale' => 'decimal:2',
            'gpa_normalized_4' => 'decimal:2',
            'resume_pdf_unreadable' => 'boolean',
            'revision' => 'integer',
            'budget_amount' => 'integer',
            'unknown_fields_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
