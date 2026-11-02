<?php

namespace App\Models;

use App\Domain\Tasks\TaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A durable unit of agent work: a goal (parent_id null) or one typed step of a goal's plan.
 */
class AgentTask extends Model
{
    use HasUlids;

    public const TYPE_GOAL = 'goal';

    protected $fillable = [
        'user_id',
        'conversation_id',
        'parent_id',
        'step_key',
        'type',
        'title',
        'goal',
        'status',
        'status_reason',
        'input',
        'input_revisions',
        'result',
        'proposal_id',
        'attempts',
        'max_attempts',
        'budget',
        'usage',
        'created_by',
        'idempotency_key',
        'available_at',
        'lease_expires_at',
        'cancel_requested_at',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'input' => 'array',
            'input_revisions' => 'array',
            'result' => 'array',
            'budget' => 'array',
            'usage' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isGoal(): bool
    {
        return $this->parent_id === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at')->orderBy('id');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'agent_task_dependencies', 'task_id', 'depends_on_id');
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'agent_task_dependencies', 'depends_on_id', 'task_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentTaskEvent::class, 'task_id')->orderBy('id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'task_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
