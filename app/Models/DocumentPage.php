<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extracted text of one page of a document version, used as the source shown beside extracted values.
 */
class DocumentPage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_version_id',
        'user_id',
        'page_number',
        'text',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['page_number' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }
}
