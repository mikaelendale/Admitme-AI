<?php

namespace App\Ai\Streaming;

use App\Ai\Agents\Subagents\SubagentActivity;
use App\Domain\Approvals\ProposalPresenter;
use App\Domain\Tasks\TaskPresenter;
use App\Models\AgentTask;
use App\Models\Proposal;
use App\Models\User;
use Generator;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;
use Throwable;

/**
 * Laravel AI's Vercel UI message stream, plus what the mentor chat client relies on:
 * - a `data-proposal` / `data-task-goal` part right after a tool creates an approval or a background plan, so
 *   the card renders at once — a separate request for it would wait behind this stream on a one-worker server;
 * - a trailing `data-conversation-id` part so a new chat learns its id;
 * - a readable in-chat message (the client does not render `error` parts) when a run fails.
 */
class MentorChatStreamProtocol extends VercelDataProtocol
{
    public const FAILURE_MESSAGE = "\n\nSomething went wrong while generating a reply. Please try again.";

    public function __construct(private readonly ?User $user = null, ?string $messageId = null)
    {
        parent::__construct($messageId);
    }

    /**
     * {@inheritdoc}
     */
    protected function parts(StreamableAgentResponse $response): Generator
    {
        $hasText = false;
        $newStep = false;
        $sentActivity = [];
        // Tool calls of the current step still waiting for a result (id => [name, input]), and those already sent early.
        $pendingCalls = [];
        $sentEarly = [];

        foreach (parent::parts($response) as $part) {
            $type = $part['type'] ?? null;
            $toolCallId = is_string($part['toolCallId'] ?? null) ? $part['toolCallId'] : null;

            if ($type === 'tool-input-available' && $toolCallId !== null) {
                $pendingCalls[$toolCallId] = [(string) ($part['toolName'] ?? ''), (array) ($part['input'] ?? [])];
            }

            if (in_array($type, ['tool-output-available', 'tool-output-error', 'tool-output-denied'], true) && empty($part['preliminary']) && $toolCallId !== null) {
                unset($pendingCalls[$toolCallId]);

                // Already sent as soon as the tool finished: the SDK's copy adds nothing.
                if (isset($sentEarly[$toolCallId])) {
                    continue;
                }
            }

            if ($type === 'start-step' && $hasText) {
                $newStep = true;
            }

            // Separate each step's text the way the stored message does (TextDelta::combine joins steps with a blank line)...
            if ($type === 'text-delta' && is_string($part['delta'] ?? null) && trim($part['delta']) !== '') {
                if ($newStep) {
                    $part['delta'] = "\n\n".ltrim($part['delta']);
                    $newStep = false;
                }

                $hasText = true;
            }

            // A specialist's final report carries its activity as a comment: send the text and the activity apart.
            $activity = null;

            if ($type === 'tool-output-available' && empty($part['preliminary']) && is_string($part['output'] ?? null)) {
                [$part['output'], $activity] = SubagentActivity::split($part['output']);
            }

            yield $part;

            // Tools that finished while a slower one in the same step (a specialist) is still working.
            yield from $this->earlyResults($pendingCalls, $sentEarly);

            if ($type !== 'tool-output-available') {
                continue;
            }

            // A sub-agent's partial output (preliminary) is text in progress, never a card; send what it has done so far.
            if (! empty($part['preliminary'])) {
                $entries = app(SubagentActivity::class)->entries($toolCallId);

                if ($entries !== [] && $entries !== ($sentActivity[$toolCallId] ?? null)) {
                    $sentActivity[$toolCallId] = $entries;

                    yield ['type' => 'data-subagent-activity', 'id' => $toolCallId, 'data' => ['toolCallId' => $toolCallId, 'activity' => $entries]];
                }

                continue;
            }

            if ($activity !== null && $activity !== []) {
                yield ['type' => 'data-subagent-activity', 'id' => $toolCallId, 'data' => ['toolCallId' => $toolCallId, 'activity' => $activity]];
            }

            yield from $this->cardParts($part['output'] ?? null);
        }

        if (filled($response->conversationId)) {
            yield [
                'type' => 'data-conversation-id',
                'data' => ['conversationId' => $response->conversationId],
            ];
        }
    }

    /**
     * Results of the step's tools that have finished (EarlyToolResults), matched to their calls by name and
     * arguments, sent with their cards straight away.
     *
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $pendingCalls
     * @param  array<string, true>  $sentEarly
     */
    protected function earlyResults(array &$pendingCalls, array &$sentEarly): Generator
    {
        foreach (app(EarlyToolResults::class)->take() as $finished) {
            foreach ($pendingCalls as $id => [$name, $input]) {
                if ($name !== $finished['name'] || json_encode($input) !== json_encode($finished['arguments'])) {
                    continue;
                }

                unset($pendingCalls[$id]);
                $sentEarly[$id] = true;

                yield ['type' => 'tool-output-available', 'toolCallId' => $id, 'output' => $finished['result']];
                yield from $this->cardParts($finished['result']);

                break;
            }
        }
    }

    /**
     * A run's parts as arrays, for a background turn that stores them instead of streaming them. A run that fails
     * part-way ends with the same readable failure text as the HTTP stream.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function partsFor(StreamableAgentResponse $response): Generator
    {
        try {
            yield from $this->parts($response);
        } catch (Throwable $e) {
            $this->exception = $e;

            if (! $e instanceof StreamErrorException) {
                report($e);
            }

            if (! $this->errored) {
                yield from $this->maskedErrorParts();
            }
        }
    }

    /**
     * Whether the run behind partsFor() failed.
     */
    public function failed(): bool
    {
        return $this->exception !== null || $this->errored;
    }

    /**
     * The card data for a tool result that created an approval proposal or started a plan. Only the student's
     * own records are sent. A failure here never breaks the reply: the card then loads itself.
     */
    protected function cardParts(mixed $output): Generator
    {
        $result = is_string($output) ? json_decode($output, true) : $output;

        if ($this->user === null || ! is_array($result)) {
            return;
        }

        $status = $result['status'] ?? null;
        $part = null;

        try {
            if ($status === 'pending_approval' && is_string($result['proposal_id'] ?? null)) {
                $proposal = Proposal::query()->whereKey($result['proposal_id'])->where('user_id', $this->user->getKey())->first();

                if ($proposal !== null) {
                    $part = ['type' => 'data-proposal', 'id' => (string) $proposal->getKey(), 'data' => ['proposal' => ProposalPresenter::present($proposal)]];
                }
            }

            if (in_array($status, ['tasks_started', 'task_updated'], true) && is_string($result['goal_task_id'] ?? null)) {
                $goal = AgentTask::query()->whereKey($result['goal_task_id'])->where('user_id', $this->user->getKey())->first();

                if ($goal !== null) {
                    $part = ['type' => 'data-task-goal', 'id' => (string) $goal->getKey(), 'data' => ['goal' => TaskPresenter::goal($goal)]];
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        if ($part !== null) {
            yield $part;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function mapEvent(StreamEvent $event): ?array
    {
        if ($event instanceof Error) {
            return ['type' => 'text-delta', 'delta' => self::FAILURE_MESSAGE];
        }

        return parent::mapEvent($event);
    }

    /**
     * {@inheritdoc}
     */
    protected function maskedErrorParts(): Generator
    {
        yield from $this->yieldPart(['type' => 'text-delta', 'delta' => self::FAILURE_MESSAGE]);
    }

    /**
     * {@inheritdoc}
     */
    protected function headers(): array
    {
        // Without this, nginx buffers the body and the whole reply arrives at once...
        return [...parent::headers(), 'X-Accel-Buffering' => 'no'];
    }
}
