<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student document (transcript, test report, …). Its files are immutable versions.
 */
class Document extends Model
{
    use HasUlids;

    /**
     * @var array<string, string>
     */
    public const KINDS = [
        'transcript' => 'Transcript / grade report',
        'test_score_report' => 'Test score report',
        'resume' => 'Resume / activities',
        'recommendation' => 'Recommendation letter',
        'financial' => 'Financial document',
        'other' => 'Other',
    ];

    protected $fillable = [
        'user_id',
        'kind',
        'title',
        'current_version_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }
}
