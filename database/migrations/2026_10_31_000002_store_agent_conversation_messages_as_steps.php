<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Migrations\AiMigration;

/**
 * Laravel AI 1.0 replaced the `tool_calls` / `tool_results` columns with one `steps` entry per model
 * round trip (each tool call carrying its own result) plus a `status` column.
 *
 * Based on the package's backfill migration, hardened for rows written by laravel/ai 0.4:
 *  - Stored `reasoning_id` / `reasoning_summary` values are dropped. 1.x no longer stores them, and
 *    replaying a stale `rs_` reference makes the OpenAI Responses API reject the whole request.
 *  - String-encoded tool arguments are decoded, because 1.x rehydrates them into an `array` property.
 *
 * Our tables never had the 0.10 `approval_state` column, so there is nothing to drop for it.
 *
 * @see https://github.com/laravel/ai/blob/v1.3.0/UPGRADE.md#conversation-messages-now-store-steps
 */
return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->schema()->table($this->messagesTable(), function (Blueprint $table) {
            $table->longText('steps')->nullable();
            $table->string('status', 25)->default(MessageStatus::Completed->value);
        });

        $this->query()->where('role', 'user')->update(['steps' => '[]']);

        $this->query()
            ->select('conversation_id')
            ->distinct()
            ->orderBy('conversation_id')
            ->chunk(100, function (Collection $conversations) {
                foreach ($conversations as $conversation) {
                    $this->backfill($conversation->conversation_id);
                }
            });

        // Any row that is neither a user nor an assistant turn still needs a value before the column becomes required...
        $this->query()->whereNull('steps')->update(['steps' => '[]']);

        $this->schema()->table($this->messagesTable(), function (Blueprint $table) {
            $table->longText('steps')->nullable(false)->change();
            $table->dropColumn(['tool_calls', 'tool_results']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores the 0.x columns from the stored steps. Reasoning ids dropped by up() cannot be restored.
     */
    public function down(): void
    {
        $this->schema()->table($this->messagesTable(), function (Blueprint $table) {
            $table->text('tool_calls')->nullable();
            $table->text('tool_results')->nullable();
        });

        $this->query()->chunkById(500, function (Collection $rows) {
            foreach ($rows as $row) {
                $calls = collect($this->decoded($row->steps))
                    ->flatMap(fn (mixed $step) => is_array($step) ? ($step['tool_calls'] ?? []) : [])
                    ->filter(fn (mixed $call) => is_array($call))
                    ->values();

                $this->query()->where('id', $row->id)->update([
                    'tool_calls' => $calls
                        ->map(fn (array $call) => Arr::only($call, ['id', 'name', 'arguments', 'result_id']))
                        ->toJson(),
                    'tool_results' => $calls
                        ->filter(fn (array $call) => array_key_exists('result', $call))
                        ->map(fn (array $call) => Arr::only($call, ['id', 'name', 'arguments', 'result', 'result_id']))
                        ->values()
                        ->toJson(),
                ]);
            }
        });

        $this->schema()->table($this->messagesTable(), function (Blueprint $table) {
            $table->text('tool_calls')->nullable(false)->change();
            $table->text('tool_results')->nullable(false)->change();
            $table->dropColumn(['steps', 'status']);
        });
    }

    /**
     * Rewrite one conversation's assistant rows as steps, each result landing on the call that made it.
     */
    protected function backfill(string $conversationId): void
    {
        $rows = $this->query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        $results = $rows->flatMap(fn (object $row) => $this->decoded($row->tool_results))
            ->filter(fn (mixed $result) => is_array($result) && isset($result['id']))
            ->keyBy('id');

        foreach ($rows as $row) {
            $meta = $this->decoded($row->meta);

            $calls = collect($this->decoded($row->tool_calls))
                ->filter(fn (mixed $call) => is_array($call) && $results->has($call['id'] ?? ''))
                ->map(fn (array $call) => [
                    ...Arr::except($call, ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content']),
                    'arguments' => $this->arguments($call['arguments'] ?? []),
                    'result' => $results[$call['id']]['result'] ?? null,
                    ...array_filter([
                        'denied' => $results[$call['id']]['denied'] ?? false,
                        'failed' => $results[$call['id']]['failed'] ?? false,
                    ]),
                ])
                ->values()
                ->all();

            $content = (string) $row->content;

            $steps = $calls !== [] && $content !== ''
                ? [$this->step('', $calls), $this->step($content, [], $meta['reasoning'] ?? '')]
                : [$this->step($content, $calls, $meta['reasoning'] ?? '')];

            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

            $this->query()->where('id', $row->id)->update([
                'steps' => json_encode($steps),
                'meta' => json_encode($meta),
            ]);
        }
    }

    /**
     * Build a conversation step from its content, tool calls, and reasoning.
     *
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    protected function step(string $content, array $calls = [], string $reasoning = ''): array
    {
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'reasoning' => $reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];
    }

    /**
     * Normalize stored tool arguments into an array.
     *
     * @return array<string, mixed>
     */
    protected function arguments(mixed $arguments): array
    {
        if (is_string($arguments)) {
            $arguments = json_decode($arguments, true);
        }

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * Decode a JSON value into an array, returning an empty array for invalid or empty input.
     *
     * @return array<array-key, mixed>
     */
    protected function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }

    /**
     * Create a query builder for the conversation messages table.
     */
    protected function query(): Builder
    {
        return DB::connection($this->getConnection())->table($this->messagesTable());
    }

    protected function schema(): SchemaBuilder
    {
        return Schema::connection($this->getConnection());
    }

    protected function messagesTable(): string
    {
        return config('ai.conversations.tables.messages', 'agent_conversation_messages');
    }
};
