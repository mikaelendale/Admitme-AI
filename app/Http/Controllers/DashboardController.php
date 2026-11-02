<?php

namespace App\Http\Controllers;

use App\Domain\Students\GuidedOnboarding;
use App\Models\Application;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Support\DashboardHubInsights;
use App\Support\DashboardInbox;
use App\Support\MentorChatConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::authorize('access-conversation', $conversation);

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
     * Recent conversations for the mentor panel's history list.
     */
    public function recentChats(Request $request): JsonResponse
    {
        return response()->json([
            'conversations' => MentorChatConversation::recentConversationsForUser((int) $request->user()->id, 30),
        ]);
    }

    /**
     * One conversation's messages, so the mentor panel can switch conversations without leaving the page.
     */
    public function chatMessages(Request $request, string $conversation): JsonResponse
    {
        Gate::authorize('access-conversation', $conversation);

        return response()->json(MentorChatConversation::forDisplay($conversation, (int) $request->user()->id));
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
        $nudges = DashboardHubInsights::nudges($user, $hubStats);
        $profile = OnboardingProfile::query()->where('user_id', $user->getKey())->first();

        return [
            'initial_conversation_id' => $conversationId,
            'initial_chat_messages' => $messages,
            'hub_stats' => $hubStats,
            'dashboard_nudges' => $nudges,
            'inbox' => DashboardInbox::for($user, $nudges),
            'readiness' => GuidedOnboarding::readiness($profile),
            'applications_summary' => $this->applicationsSummary($user),
        ];
    }

    /**
     * Counts by application status for the home summary.
     *
     * @return array{total: int, by_status: array<string, int>}
     */
    protected function applicationsSummary(User $user): array
    {
        $byStatus = Application::query()
            ->where('user_id', $user->getKey())
            ->get(['status'])
            ->countBy(fn (Application $application) => $application->status->value)
            ->all();

        return ['total' => array_sum($byStatus), 'by_status' => $byStatus];
    }
}
