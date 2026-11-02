<?php

namespace App\Models;

use App\Domain\Writing\WritingPolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of application writing the student owns (an essay, supplement or short answer).
 */
class WritingPiece extends Model
{
    use HasUlids;

    public const KINDS = ['personal_statement', 'supplement', 'short_answer', 'other'];

    protected $fillable = ['user_id', 'college_list_school_id', 'application_item_id', 'title', 'prompt_text', 'word_limit', 'kind', 'status', 'assistance_policy', 'current_version_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['assistance_policy' => WritingPolicy::class, 'word_limit' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WritingVersion::class)->orderByDesc('number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WritingVersion::class, 'current_version_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(WritingFeedback::class)->latest('created_at');
    }

    public function collegeListSchool(): BelongsTo
    {
        return $this->belongsTo(CollegeListSchool::class);
    }

    public function applicationItem(): BelongsTo
    {
        return $this->belongsTo(ApplicationItem::class);
    }
}
