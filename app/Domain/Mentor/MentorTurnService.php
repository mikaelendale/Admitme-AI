<?php

namespace App\Domain\Mentor;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\AiModelResolver;
use App\Ai\Context\ChatMode;
use App\Ai\Context\MessageAttachments;
use App\Ai\Streaming\MentorChatStreamProtocol;
use App\Domain\Memory\ConversationSummaryService;
use App\Domain\Observability\RunInstrumentation;
use App\Jobs\RunMentorTurnJob;
use App\Jobs\SummarizeConversationJob;
use App\Models\AiContextSnapshot;
use App\Models\MentorTurn;
use App\Models\MentorTurnEvent;
use App\Models\User;
use App\Support\ConversationOwnership;
use App\Support\FeatureQuotaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Throwable;

/**
 * A mentor reply, from the checks before it starts to the record of what it was given. Used by the streaming
 * chat endpoint and by background turns: a turn runs in a queued job and keeps its stream parts in order
 * (mentor_turn_events), so the chat follows it with short requests — the page stays usable and a refresh
 * picks the reply up again.
 */
final class MentorTurnService
{
    /** A running turn that has written nothing for this long has lost its worker. */
    public const STALLED_AFTER_SECONDS = 240;

    /**
     * Why the student may not start a reply now (another student's conversation, plan limits), or null.
     */
    /**
     * The conversation a new message continues. A new conversation is stored when its first reply ends, so one
     * whose first reply was stopped or failed before anything was stored does not exist: a message sent to it
     * (or a retry) starts a new conversation instead of being refused. Only the student's own turns count.
     */
    public function conversationFor(User $user, ?string $conversationId): ?string
    {
        if ($conversationId === null || ConversationOwnership::userOwnsConversation((int) $user->getKey(), $conversationId)) {
            return $conversationId;
        }

        $neverStored = MentorTurn::query()
            ->where('user_id', $user->getKey())
            ->where('conversation_id', $conversationId)
            ->exists();

        return $neverStored ? null : $conversationId;
    }

    public function refusal(User $user, ?string $conversationId): ?JsonResponse
    {
        if ($conversationId !== null && Gate::forUser($user)->denies('access-conversation', $conversationId)) {
            return response()->json(['message' => 'Invalid conversation.'], 403);
        }

        $quota = app(FeatureQuotaManager::class);
        $bucket = $quota->resolvePlanBucket($user);

        if ($bucket === 'trial') {
            if ($conversationId === null) {
                if (! $quota->canUse($user, 'chat_conversation')) {
                    return response()->json([
                        'message' => 'Conversation limit reached for your trial.',
                        'quota' => $quota->featureStatus($user, 'chat_conversation'),
                    ], 429);
                }
            } elseif (! $quota->canUse($user, 'chat_message_per_convo', $conversationId)) {
                return response()->json([
                    'message' => 'Message limit reached for this chat.',
                    'quota' => $quota->featureStatus($user, 'chat_message_per_convo', $conversationId),
                ], 429);
            }
        } elseif (! $quota->canUse($user, 'chat_message')) {
            return response()->json([
                'message' => 'Monthly mentor message limit reached.',
                'quota' => $quota->featureStatus($user, 'chat_message'),
            ], 429);
        }

        return null;
    }

    /**
     * The mentor for one turn and the message it is given (attachments add a stored "[Attached: …]" line).
     *
     * @param  list<string>  $documentIds
     * @return array{0: AdmitMeGeneralAgent, 1: string, 2: MessageAttachments}
     */
    public function agentFor(User $user, ChatMode $mode, string $message, array $documentIds, ?string $conversationId): array
    {
        $attachments = MessageAttachments::resolve($user, $documentIds);
        $text = $attachments->isEmpty() ? $message : $message."\n\n".$attachments->storedLine();

        $agent = AdmitMeGeneralAgent::make(user: $user, mode: $mode, attachments: $attachments)
            ->continueOrStart($conversationId, $user);

        return [$agent, $text, $attachments];
    }

    /**
     * The student's reply still running, if any (a stalled one is closed first).
     */
    public function activeTurn(User $user): ?MentorTurn
    {
        $turn = MentorTurn::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', MentorTurn::ACTIVE)
            ->latest('created_at')
            ->first();

        if ($turn !== null && $turn->status === MentorTurn::RUNNING && $turn->updated_at?->lt(now()->subSeconds(self::STALLED_AFTER_SECONDS))) {
            $this->finish($turn, MentorTurn::FAILED, 'The reply stopped before it finished.');
            $this->append($turn, [['type' => 'text-delta', 'delta' => MentorChatStreamProtocol::FAILURE_MESSAGE]]);

            return null;
        }

        return $turn;
    }

    /**
     * Start a reply in the background. The caller has already checked refusal() and activeTurn().
     *
     * @param  list<string>  $documentIds
     */
    public function start(User $user, ChatMode $mode, string $message, array $documentIds, ?string $conversationId): MentorTurn
    {
        $attachments = MessageAttachments::resolve($user, $documentIds);

        $turn = MentorTurn::query()->create([
            'user_id' => $user->getKey(),
            'conversation_id' => $conversationId,
            'mode' => $mode->value,
            'message' => $message,
            'document_ids' => $documentIds === [] ? null : $documentIds,
            'attachments' => $attachments->isEmpty() ? null : $attachments->names(),
            'status' => MentorTurn::QUEUED,
        ]);

        RunMentorTurnJob::dispatch((string) $turn->getKey());

        return $turn->refresh();
    }

    /**
     * Run a queued turn: stream the mentor's reply and keep every part, in order, as it is produced.
     */
    public function run(MentorTurn $turn): void
    {
        $user = $turn->user;

        if ($user === null) {
            $this->finish($turn, MentorTurn::FAILED, 'The student no longer exists.');

            return;
        }

        $turn->update(['status' => MentorTurn::RUNNING, 'started_at' => now()]);

        $mode = ChatMode::fromInput($turn->mode);
        [$agent, $text] = $this->agentFor($user, $mode, $turn->message, array_values((array) $turn->document_ids), $turn->conversation_id);
        $started = hrtime(true);
        $writer = new TurnEventWriter($turn, $this);
        $protocol = new MentorChatStreamProtocol($user);
        $cancelled = false;

        try {
            $response = $agent->stream($text)
                ->then(function (StreamedAgentResponse $response) use ($agent, $user, $started): void {
                    $conversationId = $response->conversationId ?? $agent->currentConversation();

                    $this->recordContextSnapshot($agent, $user, $conversationId, 'completed', $response->usage?->toArray(), $started, $response);
                    $this->queueSummaryIfNeeded($agent, $user, $conversationId);
                });

            // A new conversation's id is known before the reply starts, so a refresh can find it.
            if ($turn->conversation_id === null && filled($response->conversationId)) {
                $turn->update(['conversation_id' => $response->conversationId]);
            }

            foreach ($protocol->partsFor($response) as $part) {
                if ($part['type'] === 'data-conversation-id' && is_string($part['data']['conversationId'] ?? null)) {
                    $turn->conversation_id = $part['data']['conversationId'];
                }

                $writer->push($part);

                if ($writer->cancelRequested()) {
                    $cancelled = true;

                    break;
                }
            }
        } catch (Throwable $e) {
            report($e);
            $writer->push(['type' => 'text-delta', 'delta' => MentorChatStreamProtocol::FAILURE_MESSAGE]);
            $this->recordContextSnapshot($agent, $user, $agent->currentConversation(), 'failed', null, $started);
            $writer->flush();
            $this->finish($turn, MentorTurn::FAILED, RunInstrumentation::redact($e->getMessage()));

            return;
        }

        if ($cancelled) {
            $writer->push(['type' => 'text-delta', 'delta' => "\n\n*Generation stopped.*"]);
            $writer->flush();
            $this->finish($turn, MentorTurn::CANCELLED);

            return;
        }

        $writer->flush();
        $this->finish($turn, $protocol->failed() ? MentorTurn::FAILED : MentorTurn::COMPLETED);
    }

    /**
     * Append stream parts after the turn's last one.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    public function append(MentorTurn $turn, array $parts): void
    {
        if ($parts === []) {
            return;
        }

        $seq = (int) $turn->event_count;
        $now = now();

        MentorTurnEvent::query()->insert(array_map(function (array $part) use ($turn, &$seq, $now) {
            return [
                'mentor_turn_id' => $turn->getKey(),
                'seq' => ++$seq,
                'payload' => json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'created_at' => $now,
            ];
        }, $parts));

        // Also a heartbeat: a running turn that stops writing is treated as stalled after a while.
        $turn->forceFill(['event_count' => $seq, 'updated_at' => $now])->save();
    }

    public function finish(MentorTurn $turn, string $status, ?string $error = null): void
    {
        $turn->forceFill([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
            'conversation_id' => $turn->conversation_id,
        ])->save();
    }

    /**
     * Record what the run was given (mode, profile revision, instruction version, tools, context size) and the
     * provider's usage. Never throws: instrumentation must not break a reply.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public function recordContextSnapshot(AdmitMeGeneralAgent $agent, User $user, ?string $conversationId, string $outcome, ?array $usage, ?int $started = null, ?StreamedAgentResponse $response = null): void
    {
        $context = $agent->lastContext;

        if ($context === null) {
            return;
        }

        try {
            $window = $agent->historyWindow();

            AiContextSnapshot::query()->create([
                'user_id' => $user->getKey(),
                'conversation_id' => $conversationId,
                'agent' => $agent::class,
                'mode' => $agent->mode->value,
                'profile_revision' => $context->profileRevision,
                'instruction_version' => $context->instructionVersion,
                'estimated_tokens' => $context->estimatedTokens,
                'history_rows_included' => $window->rows,
                'history_rows_total' => $window->totalRows,
                'conversation_summary_id' => $context->summary?->getKey(),
                'tools' => array_map(fn (object $tool) => class_basename($tool), iterator_to_array($agent->tools())),
                'usage' => $usage,
                'outcome' => $outcome,
                'provider' => $response?->meta->provider ?? app(AiModelResolver::class)->providerFor($agent::class),
                'model' => $response?->meta->model ?? $agent->model(),
                'duration_ms' => $started !== null ? RunInstrumentation::elapsedMs($started) : null,
                'tool_calls' => $response !== null ? RunInstrumentation::toolCalls($response->toolResults) : null,
                'evidence' => $response !== null ? RunInstrumentation::citedUrls($response->meta) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function queueSummaryIfNeeded(AdmitMeGeneralAgent $agent, User $user, ?string $conversationId): void
    {
        $window = $agent->historyWindow();

        if ($conversationId === null || ! app(ConversationSummaryService::class)->shouldSummarize($conversationId, $window)) {
            return;
        }

        SummarizeConversationJob::dispatch((int) $user->getKey(), $conversationId, (string) $window->newestExcludedMessageId);
    }
}
