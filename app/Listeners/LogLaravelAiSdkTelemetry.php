<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;

class LogLaravelAiSdkTelemetry
{
    public function handlePromptingAgent(PromptingAgent $event): void
    {
        $this->maybeLog('PromptingAgent', [
            'invocation_id' => $event->invocationId,
        ]);
    }

    public function handleStreamingAgent(StreamingAgent $event): void
    {
        $this->maybeLog('StreamingAgent', [
            'invocation_id' => $event->invocationId,
        ]);
    }

    public function handleAgentStreamed(AgentStreamed $event): void
    {
        $this->maybeLog('AgentStreamed', [
            'invocation_id' => $event->invocationId,
            'text_length' => strlen($event->response->text ?? ''),
        ]);
    }

    public function handleInvokingTool(InvokingTool $event): void
    {
        $this->maybeLog('InvokingTool', [
            'invocation_id' => $event->invocationId,
            'tool' => $event->tool::class,
        ]);
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        $this->maybeLog('ToolInvoked', [
            'invocation_id' => $event->invocationId,
            'tool' => $event->tool::class,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function maybeLog(string $label, array $context): void
    {
        if (! config('admissions.log_ai_sdk_events', false)) {
            return;
        }

        Log::debug("laravel.ai.{$label}", $context);
    }
}
