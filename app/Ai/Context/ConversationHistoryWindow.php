<?php

namespace App\Ai\Context;

use Illuminate\Support\Facades\DB;

/**
 * Chooses how much history to replay within the configured budget.
 *
 * It works in whole stored turns: one row holds a complete user message or a complete assistant turn
 * (every step, each tool call with its result), so trimming can never separate a tool call from its
 * result. The most recent turns are always kept, even over budget.
 */
final class ConversationHistoryWindow
{
    public function select(string $conversationId): HistoryWindow
    {
        $budget = (int) config('admitme.context.history_tokens', 4000);
        $minimum = (int) config('admitme.context.min_history_turns', 2);
        $maxRows = (int) config('admitme.context.max_history_rows', 100);

        $table = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        $totalRows = DB::table($table)->where('conversation_id', $conversationId)->count();

        if ($totalRows === 0) {
            return HistoryWindow::empty();
        }

        // Same ordering the SDK's store uses when it loads the latest messages...
        $rows = DB::table($table)
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($maxRows)
            ->get(['id', DB::raw('LENGTH(content) + LENGTH(steps) AS size')]);

        $included = 0;
        $tokens = 0;

        foreach ($rows as $row) {
            $rowTokens = (int) ceil(((int) $row->size) / 4);

            if ($included >= $minimum && $tokens + $rowTokens > $budget) {
                break;
            }

            $included++;
            $tokens += $rowTokens;
        }

        $newestExcluded = isset($rows[$included]) ? (string) $rows[$included]->id : null;

        // Every inspected row fit, but older rows exist beyond the inspection limit...
        if ($newestExcluded === null && $totalRows > $included) {
            $newestExcluded = (string) DB::table($table)
                ->where('conversation_id', $conversationId)
                ->orderByDesc('id')
                ->offset($included)
                ->limit(1)
                ->value('id');
        }

        return new HistoryWindow(
            rows: $included,
            totalRows: $totalRows,
            estimatedTokens: $tokens,
            newestExcludedMessageId: $newestExcluded,
        );
    }
}
