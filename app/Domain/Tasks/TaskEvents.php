<?php

namespace App\Domain\Tasks;

use App\Models\AgentTask;
use App\Models\AgentTaskEvent;
use Illuminate\Support\Str;

/**
 * Append-only task history: every transition and notable step, with its reason.
 */
final class TaskEvents
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(AgentTask $task, string $type, string $message, array $data = []): AgentTaskEvent
    {
        return AgentTaskEvent::query()->create([
            'task_id' => $task->getKey(),
            'user_id' => $task->user_id,
            'type' => $type,
            'message' => Str::limit($message, 1000, '…'),
            'data' => $data !== [] ? $data : null,
        ]);
    }
}
