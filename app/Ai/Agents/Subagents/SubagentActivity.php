<?php

namespace App\Ai\Agents\Subagents;

use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolCall;

/**
 * What each running specialist is doing — the web searches it runs and what they found — keyed by the mentor's
 * tool call id, so the chat can show a sub-agent's activity live, not only its text.
 *
 * When a specialist finishes, its activity is appended to its report as an HTML comment (see footer()). The report
 * is what the conversation stores, so a reopened chat shows the same activity; split() takes it back out.
 */
final class SubagentActivity
{
    private const MAX_ENTRIES = 12;

    private const FOOTER = '<!-- admitme:activity ';

    /** @var array<string, array<string, array<string, mixed>>> tool call id => entry key => entry */
    private array $entries = [];

    public function record(string $toolCallId, StreamEvent $event): void
    {
        if ($event instanceof ProviderToolEvent) {
            $this->providerTool($toolCallId, $event);

            return;
        }

        if ($event instanceof ToolCall) {
            $this->put($toolCallId, 'tool:'.$event->toolCall->id, ['kind' => 'tool', 'name' => $event->toolCall->name, 'status' => 'done']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(?string $toolCallId): array
    {
        return $toolCallId === null ? [] : array_values($this->entries[$toolCallId] ?? []);
    }

    public function forget(string $toolCallId): void
    {
        unset($this->entries[$toolCallId]);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function footer(string $report, array $entries): string
    {
        if ($entries === []) {
            return $report;
        }

        return rtrim($report)."\n\n".self::FOOTER.json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).' -->';
    }

    /**
     * A stored or final report without its activity comment, and the activity it carried.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    public static function split(string $output): array
    {
        $at = strrpos($output, self::FOOTER);

        if ($at === false) {
            return [$output, []];
        }

        $json = substr($output, $at + strlen(self::FOOTER));
        $json = substr($json, 0, (int) strrpos($json, ' -->'));
        $entries = json_decode($json, true);

        return [rtrim(substr($output, 0, $at)), is_array($entries) ? array_values(array_filter($entries, 'is_array')) : []];
    }

    /**
     * Anthropic sends a `server_tool_use` block (the search, with its query once complete) and then a
     * `web_search_tool_result` block; OpenAI sends `web_search_call` items whose query arrives when done.
     */
    private function providerTool(string $toolCallId, ProviderToolEvent $event): void
    {
        $data = $event->data;

        if ($event->type === 'web_search_call') {
            $query = $data['action']['query'] ?? $data['item']['action']['query'] ?? null;
            $sources = self::sources((array) ($data['action']['sources'] ?? []));

            $this->put($toolCallId, 'search:'.$event->itemId, array_filter([
                'kind' => 'search',
                'query' => is_string($query) ? mb_substr($query, 0, 160) : null,
                'status' => $event->status === 'completed' ? 'done' : 'running',
                'sources' => $sources !== [] ? $sources : null,
            ], fn ($value) => $value !== null));

            return;
        }

        if ($event->type === 'server_tool_use' && ($data['name'] ?? null) === 'web_search') {
            $query = $data['input']['query'] ?? null;

            $this->put($toolCallId, 'search:'.$event->itemId, array_filter([
                'kind' => 'search',
                'query' => is_string($query) && $query !== '' ? mb_substr($query, 0, 160) : null,
                'status' => 'running',
            ], fn ($value) => $value !== null));

            return;
        }

        if ($event->type === 'web_search_tool_result') {
            $results = is_array($data['content'] ?? null) ? $data['content'] : [];
            $key = 'search:'.$event->itemId;

            $this->put($toolCallId, $key, [
                'kind' => 'search',
                'status' => 'done',
                'count' => count(array_filter($results, fn ($r) => is_array($r) && isset($r['url']))),
                'sources' => self::sources($results),
            ]);
        }
    }

    /**
     * Merge into an entry (so a search's query and its results end up on one row), keeping the newest few.
     *
     * @param  array<string, mixed>  $entry
     */
    private function put(string $toolCallId, string $key, array $entry): void
    {
        $existing = $this->entries[$toolCallId][$key] ?? [];
        $this->entries[$toolCallId][$key] = [...$existing, ...$entry];

        if (count($this->entries[$toolCallId]) > self::MAX_ENTRIES) {
            $this->entries[$toolCallId] = array_slice($this->entries[$toolCallId], -self::MAX_ENTRIES, null, true);
        }
    }

    /**
     * The first few result titles or hosts.
     *
     * @param  array<int, mixed>  $results
     * @return list<string>
     */
    private static function sources(array $results): array
    {
        $found = [];

        foreach ($results as $result) {
            $url = is_array($result) ? ($result['url'] ?? null) : (is_string($result) ? $result : null);
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
            $label = is_array($result) && is_string($result['title'] ?? null) && $result['title'] !== '' ? $result['title'] : (is_string($host) ? preg_replace('/^www\./', '', $host) : null);

            if (is_string($label) && $label !== '' && ! in_array($label, $found, true)) {
                $found[] = mb_substr($label, 0, 80);
            }

            if (count($found) === 3) {
                break;
            }
        }

        return $found;
    }
}
