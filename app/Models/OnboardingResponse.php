<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingResponse extends Model
{
    protected $fillable = [
        'user_id',
        'onboarding_session_id',
        'step_key',
        'question_key',
        'source',
        'answer_text',
        'answer_json',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answer_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OnboardingSession::class, 'onboarding_session_id');
    }
}
