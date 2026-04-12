<?php

namespace App\Http\Controllers;

use App\Support\ConversationOwnership;
use App\Support\DashboardHubInsights;
use App\Support\MentorChatConversation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', $this->chatPageProps($request, null));
    }

    public function chat(Request $request, string $conversation): Response
    {
        $user = $request->user();
        if (! ConversationOwnership::userOwnsConversation((int) $user->id, $conversation)) {
            abort(403);
        }

        return Inertia::render('dashboard', $this->chatPageProps($request, $conversation));
    }

    public function chatsIndex(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('dashboard/chats-index', [
            'conversations' => MentorChatConversation::recentConversationsForUser((int) $user->id),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatPageProps(Request $request, ?string $conversationId): array
    {
        $user = $request->user();
        $messages = [];
        if ($conversationId !== null) {
            $messages = MentorChatConversation::messagesForDisplay($conversationId, (int) $user->id);
        }

        $hubStats = DashboardHubInsights::hubStats($user);

        return [
            'initial_conversation_id' => $conversationId,
            'initial_chat_messages' => $messages,
            'hub_stats' => $hubStats,
            'dashboard_nudges' => DashboardHubInsights::nudges($user, $hubStats),
        ];
    }
}
