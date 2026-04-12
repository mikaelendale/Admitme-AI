<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CollegeListCouncilBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $runId,
        public string $type,
        public array $data = [],
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('college-list-runs.'.$this->runId);
    }

    public function broadcastAs(): string
    {
        return 'council.broadcast';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'type' => $this->type,
            'data' => $this->data,
        ];
    }
}
