<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one agent run was given: mode, profile revision, instruction version, tools, context size and
 * the provider's reported usage. Used for debugging, evaluation and cost tracking.
 */
class AiContextSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'conversation_id',
        'agent',
        'mode',
        'profile_revision',
        'instruction_version',
        'estimated_tokens',
        'history_rows_included',
        'history_rows_total',
        'conversation_summary_id',
        'tools',
        'usage',
        'outcome',
        'provider',
        'model',
        'duration_ms',
        'tool_calls',
        'evidence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_tokens' => 'array',
            'tools' => 'array',
            'usage' => 'array',
            'tool_calls' => 'array',
            'evidence' => 'array',
            'duration_ms' => 'integer',
            'profile_revision' => 'integer',
            'history_rows_included' => 'integer',
            'history_rows_total' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
