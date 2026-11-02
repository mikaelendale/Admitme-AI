<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesConfiguredModel;
use App\Ai\Context\ChatMode;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\ConversationHistoryWindow;
use App\Ai\Context\HistoryWindow;
use App\Ai\Context\MentorContext;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Context\MessageAttachments;
use App\Domain\Browser\BrowserAgentService;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The AdmitMe mentor. One agent for both modes; the mode decides — server-side — which tools the run
 * receives (see MentorToolPolicy). Instructions come from trusted files in resources/ai plus a
 * student brief built from saved facts (see ContextBuilder).
 */
#[MaxSteps(16)]
class AdmitMeGeneralAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations, UsesConfiguredModel;

    /**
     * The context assembled for the current run, kept for instrumentation.
     */
    public ?MentorContext $lastContext = null;

    private ?HistoryWindow $historyWindow = null;

    public function __construct(
        public User $user,
        public ChatMode $mode = ChatMode::Ask,
        public ?MessageAttachments $attachments = null,
    ) {}

    /**
     * Model calls per reply (the SDK prefers this to the #[MaxSteps] attribute). Working through a portal in the
     * student's browser takes several steps per page, so a reply gets more of them while the student has given
     * the mentor control of a tab.
     */
    public function maxSteps(): int
    {
        $steps = (int) config('admitme.mentor.max_steps', 16);

        if ($this->mode === ChatMode::Agent && app(BrowserAgentService::class)->controlFor($this->user) !== null) {
            return max($steps, (int) config('admitme.mentor.browser_max_steps', 40));
        }

        return $steps;
    }

    public function instructions(): Stringable|string
    {
        $this->lastContext = app(ContextBuilder::class)->forMentor(
            $this->user,
            $this->mode,
            $this->currentConversation(),
            $this->historyWindow(),
        );

        $attached = $this->attachments?->instructions() ?? '';

        return $attached === '' ? $this->lastContext->instructions : $this->lastContext->instructions.'

'.$attached;
    }

    /**
     * Replay only the most recent whole turns that fit the history budget. Older turns are covered by
     * an attributed summary in the instructions.
     */
    public function messages(): iterable
    {
        $conversationId = $this->currentConversation();
        $window = $this->historyWindow();

        if ($conversationId === null || $window->rows === 0) {
            return [];
        }

        return resolve(ConversationStore::class)
            ->getLatestConversationMessages($conversationId, $window->rows)
            ->all();
    }

    /**
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return app(MentorToolPolicy::class)->toolsFor($this->user, $this->mode, $this->currentConversation());
    }

    public function historyWindow(): HistoryWindow
    {
        $conversationId = $this->currentConversation();

        if ($conversationId === null) {
            return HistoryWindow::empty();
        }

        return $this->historyWindow ??= app(ConversationHistoryWindow::class)->select($conversationId);
    }
}
