<?php

namespace App\Models;

use App\Models\Concerns\HasKnowledgeVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A web page used as admissions evidence. Each fetch with new content is a new version.
 * Official/reference pages are shared; a page a student supplies is private to them (D-016).
 */
class KnowledgeSource extends Model
{
    use HasKnowledgeVisibility;
    use HasUlids;

    public const TYPE_OFFICIAL = 'official';

    public const TYPE_REFERENCE = 'reference';

    public const TYPE_STUDENT_PROVIDED = 'student_provided';

    protected $fillable = [
        'institution_id',
        'owner_user_id',
        'scope_key',
        'url',
        'url_hash',
        'host',
        'title',
        'source_type',
        'claimed_official',
        'status',
        'failure_reason',
        'current_version_id',
        'checked_at',
        'added_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'claimed_official' => 'boolean'];
    }

    public static function scopeKey(Institution $institution, ?User $owner): string
    {
        return 'i'.$institution->getKey().($owner !== null ? ':u'.$owner->getKey() : '');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeSourceVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSourceVersion::class, 'current_version_id');
    }

    public function isStale(): bool
    {
        return $this->checked_at === null || $this->checked_at->lt(now()->subDays((int) config('admitme.knowledge.stale_after_days', 30)));
    }
}
