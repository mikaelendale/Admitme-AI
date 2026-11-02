<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeSourceVersion extends Model
{
    use HasUlids;

    protected $fillable = [
        'knowledge_source_id',
        'content_text',
        'content_hash',
        'http_status',
        'fetched_at',
        'extraction_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fetched_at' => 'datetime', 'http_status' => 'integer'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    public function passages(): HasMany
    {
        return $this->hasMany(KnowledgePassage::class)->orderBy('position');
    }
}
