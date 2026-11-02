<?php

namespace App\Domain\Memory;

use App\Ai\Agents\ConversationSummarizerAgent;
use App\Ai\Context\HistoryWindow;
use App\Models\ConversationSummary;
use App\Models\User;
use App\Support\ConversationOwnership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps a derived summary of the turns that no longer fit in replayed history. Each summary point
 * cites the stored messages it came from; ids the model invents are dropped.
 */
final class ConversationSummaryService
{
    public function __construct(private readonly MemoryReader $memory) {}

    /**
     * Whether enough turns have fallen outside the replayed history (and outside the latest summary).
     */
    public function shouldSummarize(string $conversationId, HistoryWindow $window): bool
    {
        if (! config('admitme.summaries.enabled', true) || $window->newestExcludedMessageId === null) {
            return false;
        }

        $coveredThrough = ConversationSummary::query()
            ->where('conversation_id', $conversationId)
            ->latest('id')
            ->value('covers_through_message_id');

        if ($coveredThrough !== null && strcmp($coveredThrough, $window->newestExcludedMessageId) >= 0) {
            return false;
        }

        $unsummarized = $this->messages()
            ->where('conversation_id', $conversationId)
            ->where('id', '<=', $window->newestExcludedMessageId)
            ->when($coveredThrough !== null, fn ($query) => $query->where('id', '>', $coveredThrough))
            ->count();

        return $unsummarized >= (int) config('admitme.summaries.min_unsummarized_turns', 6);
    }

    /**
     * Summarize the conversation through the given stored message, building on the previous summary.
     */
    public function summarize(User $student, string $conversationId, string $throughMessageId): ?ConversationSummary
    {
        if (! ConversationOwnership::userOwnsConversation((int) $student->getKey(), $conversationId)) {
            return null;
        }

        $previous = ConversationSummary::query()
            ->where('user_id', $student->getKey())
            ->where('conversation_id', $conversationId)
            ->latest('id')
            ->first();

        $rows = $this->messages()
            ->where('conversation_id', $conversationId)
            ->where('id', '<=', $throughMessageId)
            ->when($previous !== null, fn ($query) => $query->where('id', '>', $previous->covers_through_message_id))
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'steps']);

        if ($rows->isEmpty()) {
            return null;
        }

        $response = ConversationSummarizerAgent::make()->prompt($this->transcript($previous, $rows));

        $allowedIds = $rows->pluck('id')->map(fn ($id) => (string) $id)
            ->merge(collect($previous?->items ?? [])->flatMap(fn ($item) => $item['message_ids'] ?? []))
            ->unique()
            ->values()
            ->all();

        $items = collect($response['items'] ?? [])
            ->filter(fn ($item) => is_array($item) && is_string($item['text'] ?? null) && trim($item['text']) !== '')
            ->map(fn (array $item) => [
                'text' => Str::limit(trim($item['text']), 500, '…'),
                'message_ids' => array_values(array_intersect(array_map('strval', (array) ($item['message_ids'] ?? [])), $allowedIds)),
            ])
            ->take(12)
            ->values()
            ->all();

        if ($items === []) {
            return null;
        }

        return ConversationSummary::query()->create([
            'user_id' => $student->getKey(),
            'conversation_id' => $conversationId,
            'items' => $items,
            'covers_through_message_id' => (string) $rows->last()->id,
            'message_count' => ($previous?->message_count ?? 0) + $rows->count(),
            'profile_revision' => $this->memory->profileRevision($student),
        ]);
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function transcript(?ConversationSummary $previous, Collection $rows): string
    {
        $lines = [];

        if ($previous !== null) {
            $lines[] = 'Previous summary (keep what still matters):';

            foreach ($previous->items as $item) {
                $lines[] = '- '.($item['text'] ?? '').' ['.implode(', ', $item['message_ids'] ?? []).']';
            }

            $lines[] = '';
        }

        $lines[] = 'New messages to fold in:';

        foreach ($rows as $row) {
            $tools = collect(json_decode((string) $row->steps, true) ?: [])
                ->flatMap(fn ($step) => is_array($step) ? array_column($step['tool_calls'] ?? [], 'name') : [])
                ->filter()
                ->unique()
                ->implode(', ');

            $lines[] = "[{$row->id}] {$row->role}: ".Str::limit(trim((string) $row->content), 1200, '…')
                .($tools !== '' ? " (tools used: {$tools})" : '');
        }

        return implode("\n", $lines);
    }

    private function messages(): Builder
    {
        return DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'));
    }
}
