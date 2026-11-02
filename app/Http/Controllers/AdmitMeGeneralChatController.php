<?php

namespace App\Http\Controllers;

use App\Ai\Context\ChatMode;
use App\Ai\Streaming\MentorChatStreamProtocol;
use App\Domain\Mentor\MentorTurnService;
use App\Domain\Observability\RunInstrumentation;
use App\Http\Requests\AdmitMeGeneralChatRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;

class AdmitMeGeneralChatController extends Controller
{
    public function __invoke(AdmitMeGeneralChatRequest $request): Response|JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $turns = app(MentorTurnService::class);

        if (($refused = $turns->refusal($user, ! empty($validated['conversation_id']) ? $validated['conversation_id'] : null)) !== null) {
            return $refused;
        }

        // One mentor turn at a time per conversation, so two replies never plan over each other (Phase 8).
        $turnLock = null;

        if (! empty($validated['conversation_id'])) {
            $turnLock = Cache::lock('mentor-turn:'.$validated['conversation_id'], 120);

            if (! $turnLock->get()) {
                return response()->json([
                    'message' => 'The mentor is still answering in this conversation. Wait for that reply to finish, then send your message.',
                    'error' => 'turn_in_progress',
                ], 409);
            }
        }

        try {
            [$agent, $message] = $turns->agentFor(
                $user,
                ChatMode::fromInput($validated['mode'] ?? null),
                $validated['message'],
                array_values((array) ($validated['document_ids'] ?? [])),
                $validated['conversation_id'] ?? null,
            );
            $started = hrtime(true);

            return $agent->stream($message)
                ->usingProtocol(new MentorChatStreamProtocol($user))
                ->then(function (StreamedAgentResponse $response) use ($agent, $user, $turnLock, $started, $turns): void {
                    $turnLock?->release();
                    $conversationId = $response->conversationId ?? $agent->currentConversation();

                    $turns->recordContextSnapshot($agent, $user, $conversationId, 'completed', $response->usage?->toArray(), $started, $response);
                    $turns->queueSummaryIfNeeded($agent, $user, $conversationId);
                })
                ->catch(function (\Throwable $e) use ($agent, $user, $request, $turnLock, $started, $turns): void {
                    $turnLock?->release();
                    $turns->recordContextSnapshot($agent, $user, $agent->currentConversation(), 'failed', null, $started);
                    $this->logChatFailure(
                        'AdmitMe general chat stream failed during SSE',
                        $e,
                        $user->id,
                        $request,
                        ['conversation_id' => $agent->currentConversation()],
                    );
                })
                ->toResponse($request);
        } catch (\Throwable $e) {
            $turnLock?->release();
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
            // Provider errors can echo keys or the student's text back: redact before logging (Phase 11).
            'error' => RunInstrumentation::redact($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'trace' => RunInstrumentation::redact($e->getTraceAsString()),
        ]);

        if ($e instanceof RequestException && $e->response !== null) {
            $payload['upstream_http_status'] = $e->response->status();
            $payload['upstream_body'] = RunInstrumentation::redact(Str::limit($e->response->body(), 8000, '…'));
        }

        Log::error($message, $payload);
    }
}
