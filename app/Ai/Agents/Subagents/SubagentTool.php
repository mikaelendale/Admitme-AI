<?php

namespace App\Ai\Agents\Subagents;

use App\Ai\Tools\Contracts\ReadOnlyTool;
use Generator;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * A specialist offered to the mentor as a sub-agent tool. Like the SDK's AgentTool it streams the specialist's
 * text (as preliminary tool results); it also records what the specialist does — its web searches and what they
 * found — so the chat can show its activity, and appends that activity to the final report (SubagentActivity).
 */
class SubagentTool extends AgentTool implements ReadOnlyTool
{
    public function __construct(MentorSubagent $agent)
    {
        parent::__construct($agent);
    }

    public function stream(Request $request): Generator
    {
        $toolCallId = $request->toolCallId() ?? 'subagent';
        $activity = app(SubagentActivity::class);

        try {
            $stream = $this->agent->stream((string) $request['task']);

            foreach ($stream as $event) {
                $activity->record($toolCallId, $event);

                yield $event;
            }

            return SubagentActivity::footer((string) $stream->text, $activity->entries($toolCallId));
        } catch (Throwable $throwable) {
            // Degraded to a result so the mentor's run survives, as the SDK's AgentTool does.
            return 'Agent failed: '.$throwable->getMessage();
        } finally {
            $activity->forget($toolCallId);
        }
    }
}
