<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ConversationOwnership
{
    public static function userOwnsConversation(int $userId, string $conversationId): bool
    {
        return self::whereUser(DB::table('agent_conversations'), $userId)
            ->where('id', $conversationId)
            ->exists();
    }

    /**
     * Scope a Laravel AI conversation or conversation-message query to rows whose participant is the given user.
     */
    public static function whereUser(Builder $query, int|string $userId): Builder
    {
        return $query
            ->where('participant_type', (new User)->getMorphClass())
            ->where('participant_id', $userId);
    }
}
