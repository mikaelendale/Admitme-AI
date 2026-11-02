<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored, source-backed answer to a student's question about a college (a research artifact).
 */
class ResearchAnswer extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'institution_id',
        'college_list_school_id',
        'question',
        'answer',
        'claims',
        'unresolved',
        'evidence',
        'cycle_label',
        'model',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['claims' => 'array', 'unresolved' => 'array', 'evidence' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
