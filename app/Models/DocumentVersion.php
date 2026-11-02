<?php

namespace App\Models;

use App\Domain\Documents\DocumentVersionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded file. The stored file never changes; a replacement is a new version.
 */
class DocumentVersion extends Model
{
    use HasUlids;

    protected $fillable = [
        'document_id',
        'user_id',
        'version',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'status',
        'failure_reason',
        'page_count',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentVersionStatus::class,
            'version' => 'integer',
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }
}
