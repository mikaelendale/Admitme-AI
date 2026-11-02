<?php

namespace App\Models;

use App\Models\Concerns\HasKnowledgeVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A searchable chunk of a source version, linked back to the original page.
 */
class KnowledgePassage extends Model
{
    use HasKnowledgeVisibility;

    public $timestamps = false;

    protected $fillable = ['knowledge_source_version_id', 'institution_id', 'owner_user_id', 'position', 'text'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSourceVersion::class, 'knowledge_source_version_id');
    }
}
