<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One model call made by a task's agent: which agent and model, the outcome and token usage.
 */
class AgentRun extends Model
{
    use HasUlids;

    public const STATUSES = ['queued', 'running', 'waiting_for_tool', 'awaiting_approval', 'completed', 'failed', 'cancelled'];

    protected $fillable = ['task_id', 'user_id', 'agent', 'provider', 'model', 'instruction_version', 'status', 'usage', 'error', 'handoff', 'started_at', 'finished_at', 'duration_ms'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['usage' => 'array', 'handoff' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'task_id');
    }
}
