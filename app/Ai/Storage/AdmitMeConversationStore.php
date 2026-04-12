<?php

namespace App\Ai\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * OpenAI gpt-5* models require each function_call in the Responses API input to be paired
 * with its reasoning item. Laravel AI persists reasoning on tool calls, but the stock
 * {@see DatabaseConversationStore} omits those fields when rehydrating from JSON.
 * This store restores them and, for older rows without reasoning ids, collapses the
 * tool turn into plain assistant text so the next request does not 400.
 */
class AdmitMeConversationStore extends DatabaseConversationStore
{
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function ($record) {
                $toolCalls = collect(json_decode($record->tool_calls, true) ?? []);
                $toolResults = collect(json_decode($record->tool_results, true) ?? []);

                if ($record->role === 'user') {
                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    $messages = [];

                    $messages[] = new AssistantMessage(
                        $record->content ?: '',
                        $toolCalls->map(fn (array $toolCall) => new ToolCall(
                            id: (string) ($toolCall['id'] ?? ''),
                            name: (string) ($toolCall['name'] ?? ''),
                            arguments: $this->coerceToolArguments($toolCall['arguments'] ?? []),
                            resultId: isset($toolCall['result_id']) ? (string) $toolCall['result_id'] : null,
                            reasoningId: isset($toolCall['reasoning_id']) ? (string) $toolCall['reasoning_id'] : null,
                            reasoningSummary: isset($toolCall['reasoning_summary']) && is_array($toolCall['reasoning_summary'])
                                ? $toolCall['reasoning_summary']
                                : null,
                        ))
                    );

                    if ($toolResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage(
                            $toolResults->map(fn (array $toolResult) => new ToolResult(
                                id: (string) ($toolResult['id'] ?? ''),
                                name: (string) ($toolResult['name'] ?? ''),
                                arguments: $this->coerceToolArguments($toolResult['arguments'] ?? []),
                                result: $toolResult['result'] ?? '',
                                resultId: isset($toolResult['result_id']) ? (string) $toolResult['result_id'] : null,
                            ))
                        );
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            });

        return $this->collapseToolTurnsMissingReasoning($messages);
    }

    /**
     * @return array<string, mixed>
     */
    protected function coerceToolArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if ($arguments === null || $arguments === '') {
            return [];
        }

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);

            return is_array($decoded) ? $decoded : [];
        }

        $decoded = json_decode((string) json_encode($arguments), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Message>
     */
    protected function collapseToolTurnsMissingReasoning(Collection $messages): Collection
    {
        $items = $messages->values()->all();
        $out = [];

        for ($i = 0, $n = count($items); $i < $n; $i++) {
            $msg = $items[$i];

            if (! $msg instanceof AssistantMessage || $msg->toolCalls->isEmpty()) {
                $out[] = $msg;

                continue;
            }

            $missingReasoning = $msg->toolCalls->contains(
                fn (ToolCall $tc) => $tc->reasoningId === null || $tc->reasoningId === '',
            );

            if (! $missingReasoning) {
                $out[] = $msg;

                continue;
            }

            $toolResultPayloads = [];
            if ($i + 1 < $n && $items[$i + 1] instanceof ToolResultMessage) {
                $toolResultPayloads = $items[$i + 1]->toolResults->all();
                $i++;
            }

            $out[] = new AssistantMessage($this->inlineExecutedToolsAsText($msg, $toolResultPayloads));
        }

        return collect($out);
    }

    /**
     * @param  list<ToolResult>  $toolResults
     */
    protected function inlineExecutedToolsAsText(AssistantMessage $assistant, array $toolResults): string
    {
        $parts = [];

        $lead = trim($assistant->content);
        if ($lead !== '') {
            $parts[] = $lead;
        }

        $parts[] = '[Earlier in this chat the assistant called tools; condensed context follows.]';

        foreach ($assistant->toolCalls as $tc) {
            $parts[] = '- '.$tc->name.' ← '.Str::limit(
                json_encode($tc->arguments, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
                4000,
                '…',
            );
        }

        foreach ($toolResults as $tr) {
            $raw = $tr->result;
            $text = is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $parts[] = '- '.$tr->name.' → '.Str::limit((string) $text, 12000, '…');
        }

        return implode("\n", $parts);
    }
}
