<?php

namespace App\Domain\Writing;

/**
 * How much AI help a college allows with application writing. Enforced on the server: the coach is never
 * called under "no_ai_assistance", and it never produces replacement text under "feedback_only".
 */
enum WritingPolicy: string
{
    case NoAiAssistance = 'no_ai_assistance';
    case FeedbackOnly = 'feedback_only';
    case LightEditing = 'light_editing';

    public static function default(): self
    {
        return self::tryFrom((string) config('admitme.writing.default_policy', 'feedback_only')) ?? self::FeedbackOnly;
    }

    public function label(): string
    {
        return match ($this) {
            self::NoAiAssistance => 'No AI assistance',
            self::FeedbackOnly => 'Feedback only',
            self::LightEditing => 'Feedback and light language edits',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NoAiAssistance => 'This college asks that application writing is done without AI help, so the writing coach is turned off for this piece.',
            self::FeedbackOnly => 'The coach comments and asks questions about your draft. It never writes or rewrites your text.',
            self::LightEditing => 'The coach comments on your draft and may point out spelling and grammar fixes (a few words at a time). It never rewrites your ideas.',
        };
    }

    public function allowsFeedback(): bool
    {
        return $this !== self::NoAiAssistance;
    }

    public function allowsLanguageFixes(): bool
    {
        return $this === self::LightEditing;
    }
}
