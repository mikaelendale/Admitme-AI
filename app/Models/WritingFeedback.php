<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coach feedback on one specific version: comments anchored to verbatim quotes of the student's text.
 */
class WritingFeedback extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'writing_feedback';

    protected $fillable = ['writing_piece_id', 'writing_version_id', 'user_id', 'assistance_policy', 'comments', 'summary', 'dropped_comments', 'model', 'usage'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['comments' => 'array', 'usage' => 'array', 'dropped_comments' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WritingVersion::class, 'writing_version_id');
    }
}
