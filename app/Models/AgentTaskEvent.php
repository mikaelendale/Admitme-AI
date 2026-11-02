<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a task, with the reason. Append-only.
 */
class AgentTaskEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'user_id', 'type', 'message', 'data'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'task_id');
    }
}
