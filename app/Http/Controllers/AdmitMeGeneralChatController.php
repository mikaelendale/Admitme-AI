<?php

namespace App\Http\Controllers;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Http\Requests\AdmitMeGeneralChatRequest;
use App\Support\ConversationOwnership;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdmitMeGeneralChatController extends Controller
{
    public function __invoke(AdmitMeGeneralChatRequest $request): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if (! empty($validated['conversation_id']) && ! ConversationOwnership::userOwnsConversation((int) $user->id, $validated['conversation_id'])) {
            return response()->json(['message' => 'Invalid conversation.'], 403);
        }

        try {
            $agent = AdmitMeGeneralAgent::make(user: $user);

            if (! empty($validated['conversation_id'])) {
                $agent->continue($validated['conversation_id'], $user);
            } else {
                $agent->forUser($user);
            }

            /** @var StreamableAgentResponse $streamable */
            $streamable = $agent->stream($validated['message']);

            return $this->vercelSseResponse($streamable, $agent, $request, $user->id);
        } catch (\Throwable $e) {
            $this->logChatFailure(
                'AdmitMe general chat failed before or while opening stream',
                $e,
                $user->id,
                $request,
                ['conversation_id' => $validated['conversation_id'] ?? null],
            );

            throw $e;
        }
    }

    /**
     * Mirror Laravel AI's Vercel stream protocol and append conversation id for the client.
     */
    protected function vercelSseResponse(
        StreamableAgentResponse $streamable,
        AdmitMeGeneralAgent $agent,
        AdmitMeGeneralChatRequest $request,
        int|string|null $userId,
    ): StreamedResponse {
        $state = new class
        {
            public bool $streamStarted = false;

            /** @var array<string, true> */
            public array $toolCalls = [];

            public ?array $lastStreamEndEvent = null;
        };

        return response()->stream(function () use ($streamable, $agent, $state, $request, $userId): void {
            try {
                foreach ($streamable as $event) {
                    if ($event instanceof StreamStart) {
                        if ($state->streamStarted) {
                            continue;
                        }

                        $state->streamStarted = true;
                    }

                    if ($event instanceof ToolCall) {
                        $state->toolCalls[$event->toolCall->id] = true;
                    }

                    if ($event instanceof ToolResult && ! isset($state->toolCalls[$event->toolResult->id])) {
                        continue;
                    }

                    if ($event instanceof StreamEnd) {
                        $state->lastStreamEndEvent = $event->toVercelProtocolArray();

                        continue;
                    }

                    $data = $event->toVercelProtocolArray();
                    if (empty($data)) {
                        continue;
                    }

                    echo 'data: '.json_encode($data)."\n\n";
                    $this->flushStreamBuffer();
                }

                if ($state->lastStreamEndEvent !== null) {
                    echo 'data: '.json_encode($state->lastStreamEndEvent)."\n\n";
                    $this->flushStreamBuffer();
                }

                $conversationId = $agent->currentConversation();
                if (is_string($conversationId) && $conversationId !== '') {
                    echo 'data: '.json_encode([
                        'type' => 'data-conversation-id',
                        'data' => ['conversationId' => $conversationId],
                    ])."\n\n";
                    $this->flushStreamBuffer();
                }

                echo "data: [DONE]\n\n";
                $this->flushStreamBuffer();
            } catch (\Throwable $e) {
                $this->logChatFailure(
                    'AdmitMe general chat stream failed during SSE',
                    $e,
                    $userId,
                    $request,
                    ['conversation_id' => $agent->currentConversation()],
                );

                // Headers/body may already be flushing; do not rethrow (avoids "headers already sent" fatals).
                echo 'data: '.json_encode([
                    'type' => 'text-delta',
                    'delta' => "\n\nSomething went wrong while generating a reply. Please try again.",
                ])."\n\n";
                echo "data: [DONE]\n\n";
                $this->flushStreamBuffer();
            }
        }, 200, [
            'Cache-Control' => 'no-cache, no-transform',
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ]);
    }

    protected function flushStreamBuffer(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logChatFailure(
        string $message,
        \Throwable $e,
        int|string|null $userId,
        AdmitMeGeneralChatRequest $request,
        array $context = [],
    ): void {
        $payload = array_merge($context, [
            'user_id' => $userId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'trace' => $e->getTraceAsString(),
        ]);

        if ($e instanceof RequestException && $e->response !== null) {
            $payload['upstream_http_status'] = $e->response->status();
            $body = $e->response->body();
            $payload['upstream_body'] = Str::limit($body, 8000, '…');
            $json = $e->response->json();
            if (is_array($json)) {
                $payload['upstream_json'] = $json;
            }
        }

        Log::error($message, $payload);
    }
}
