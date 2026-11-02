<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A field-by-field plan for filling in an application by hand, against a (synthetic) form snapshot.
 */
class ApplicationFormPlan extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['application_id', 'user_id', 'agent_task_id', 'form_key', 'entries', 'profile_revision', 'model'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['entries' => 'array', 'profile_revision' => 'integer'];
    }
}
