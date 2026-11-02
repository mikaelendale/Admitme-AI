<?php

namespace App\Ai\Streaming;

use App\Ai\Agents\AdmitMeGeneralAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Results of the mentor's tools as each one finishes (from the SDK's ToolInvoked event).
 *
 * The SDK reports all of a step's tool results only once every tool in the step has finished — so a quick
 * proposal sat "running" while a specialist in the same step worked for a minute. MentorChatStreamProtocol
 * sends these results (and their cards) as soon as it can, and skips the SDK's copies when they arrive.
 */
final class EarlyToolResults
{
    /** @var list<array{name: string, arguments: array<string, mixed>, result: string}> */
    private array $finished = [];

    public function record(ToolInvoked $event): void
    {
        // Specialists stream their own output; other agents' tools never reach the mentor's chat.
        if ($event->tool instanceof AgentTool || ! $event->agent instanceof AdmitMeGeneralAgent) {
            return;
        }

        $this->finished[] = [
            'name' => ToolNameResolver::resolve($event->tool),
            'arguments' => $event->arguments,
            'result' => (string) $event->result,
        ];
    }

    /**
     * The results recorded since the last call.
     *
     * @return list<array{name: string, arguments: array<string, mixed>, result: string}>
     */
    public function take(): array
    {
        $finished = $this->finished;
        $this->finished = [];

        return $finished;
    }
}
