<?php

namespace App\Jobs;

use App\Domain\Memory\ConversationSummaryService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the derived summary for turns that fell out of replayed history. Unique per conversation so
 * two finishing turns cannot summarize the same messages twice.
 */
class SummarizeConversationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(
        public int $userId,
        public string $conversationId,
        public string $throughMessageId,
    ) {}

    public function uniqueId(): string
    {
        return $this->conversationId;
    }

    public function handle(ConversationSummaryService $summaries): void
    {
        $student = User::query()->find($this->userId);

        if ($student === null) {
            return;
        }

        $summaries->summarize($student, $this->conversationId, $this->throughMessageId);
    }
}
