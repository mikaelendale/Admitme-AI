<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A college or university as shared admissions knowledge (not a student's list entry).
 */
class Institution extends Model
{
    protected $fillable = ['name', 'slug', 'country', 'website_domain', 'writing_policy'];

    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(AdmissionRequirement::class);
    }
}
