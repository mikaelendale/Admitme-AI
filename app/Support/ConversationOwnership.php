<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class ConversationOwnership
{
    public static function userOwnsConversation(int $userId, string $conversationId): bool
    {
        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', $userId)
            ->exists();
    }
}
