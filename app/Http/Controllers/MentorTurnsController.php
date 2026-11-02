<?php

namespace App\Http\Controllers;

use App\Ai\Context\ChatMode;
use App\Domain\Mentor\MentorTurnService;
use App\Http\Requests\AdmitMeGeneralChatRequest;
use App\Models\MentorTurn;
use App\Models\MentorTurnEvent;
use App\Support\ConversationOwnership;
use App\Support\MentorChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Background mentor replies. Starting one returns at once; the chat then reads the reply's parts in order with
 * short requests, so pages keep loading while the mentor works, and a refreshed page picks the reply up again.
 */
class MentorTurnsController extends Controller
{
    /** At most this many parts per read; the chat asks again for the rest. */
    private const PAGE = 400;

    public function store(AdmitMeGeneralChatRequest $request, MentorTurnService $turns): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $conversationId = $turns->conversationFor($user, $validated['conversation_id'] ?? null);

        if (($refused = $turns->refusal($user, $conversationId)) !== null) {
            return $refused;
        }

        // One reply at a time, so two replies never plan over each other (Phase 8).
        if ($turns->activeTurn($user) !== null) {
            return response()->json([
                'message' => 'The mentor is still answering. Wait for that reply to finish (or stop it), then send your message.',
                'error' => 'turn_in_progress',
            ], 409);
        }

        $turn = $turns->start(
            $user,
            ChatMode::fromInput($validated['mode'] ?? null),
            $validated['message'],
            array_values((array) ($validated['document_ids'] ?? [])),
            $conversationId,
        );

        return response()->json(['turn' => $this->present($turn)], 202);
    }

    /**
     * The student's reply still running, so a refreshed page can show it and keep following it. Its conversation's
     * earlier messages come with it when the conversation is stored (a new one is stored when its first reply
     * ends), so the page needs no second request.
     */
    public function active(Request $request, MentorTurnService $turns): JsonResponse
    {
        $turn = $turns->activeTurn($request->user());

        return response()->json([
            'turn' => $turn !== null ? $this->present($turn) : null,
            'conversation' => $turn?->conversation_id !== null
                ? MentorChatConversation::forDisplay($turn->conversation_id, (int) $request->user()->getKey())
                : null,
        ]);
    }

    /**
     * The reply's parts after `after` (a sequence number), in order, and where the turn stands.
     */
    public function events(Request $request, MentorTurn $mentorTurn): JsonResponse
    {
        $this->authorizeOwner($request, $mentorTurn);

        $after = max(0, (int) $request->query('after', 0));

        $events = MentorTurnEvent::query()
            ->where('mentor_turn_id', $mentorTurn->getKey())
            ->where('seq', '>', $after)
            ->orderBy('seq')
            ->limit(self::PAGE)
            ->get(['seq', 'payload']);

        return response()->json([
            'turn' => $this->present($mentorTurn->refresh()),
            'events' => $events->map(fn (MentorTurnEvent $event) => json_decode($event->payload, true))->all(),
            'cursor' => (int) ($events->last()?->seq ?? $after),
        ]);
    }

    /**
     * Stop the reply. A turn that has not started yet is cancelled at once; a running one stops at its next batch.
     */
    public function cancel(Request $request, MentorTurn $mentorTurn, MentorTurnService $turns): JsonResponse
    {
        $this->authorizeOwner($request, $mentorTurn);

        if ($mentorTurn->status === MentorTurn::QUEUED) {
            $turns->append($mentorTurn, [['type' => 'text-delta', 'delta' => '*Generation stopped.*']]);
            $turns->finish($mentorTurn, MentorTurn::CANCELLED);
        } elseif ($mentorTurn->status === MentorTurn::RUNNING) {
            $mentorTurn->forceFill(['cancel_requested_at' => now()])->save();
        }

        return response()->json(['turn' => $this->present($mentorTurn->refresh())]);
    }

    private function authorizeOwner(Request $request, MentorTurn $turn): void
    {
        abort_unless((int) $turn->user_id === (int) $request->user()->getKey(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MentorTurn $turn): array
    {
        return [
            'id' => (string) $turn->getKey(),
            'status' => $turn->status,
            'conversation_id' => $turn->conversation_id,
            'mode' => $turn->mode,
            'message' => $turn->message,
            'attachments' => $turn->attachments ?? [],
            'event_count' => (int) $turn->event_count,
            'created_at' => $turn->created_at?->toIso8601String(),
            // Once the reply has ended: whether its conversation is stored (a new one is not when its first reply
            // was stopped or failed early), so the chat only remembers conversations that exist.
            'conversation_stored' => in_array($turn->status, [MentorTurn::QUEUED, MentorTurn::RUNNING], true) || $turn->conversation_id === null
                ? null
                : ConversationOwnership::userOwnsConversation((int) $turn->user_id, $turn->conversation_id),
        ];
    }
}
