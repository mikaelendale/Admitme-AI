<?php

namespace App\Ai\Context;

/**
 * What the mentor may do in a run. Enforced server-side by the tools each mode receives.
 */
enum ChatMode: string
{
    /** Answers and read-only lookups. Conversation logging still persists. */
    case Ask = 'ask';

    /** Plans and performs permitted work with the tools offered. */
    case Agent = 'agent';

    public static function fromInput(?string $value): self
    {
        return self::tryFrom((string) $value)
            ?? self::tryFrom((string) config('admitme.mentor.default_mode'))
            ?? self::Ask;
    }
}
