<?php

namespace App\Jobs;

use App\Ai\Streaming\MentorChatStreamProtocol;
use App\Domain\Mentor\MentorTurnService;
use App\Models\MentorTurn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one mentor reply in the background (see MentorTurnService). It has its own queue, so a long background job
 * (a council build, an essay) never makes the student wait for a chat reply.
 */
class RunMentorTurnJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $turnId)
    {
        $this->onQueue('mentor');
    }

    public function handle(MentorTurnService $turns): void
    {
        $turn = MentorTurn::query()->find($this->turnId);

        // Cancelled before it started, or already run.
        if ($turn === null || $turn->status !== MentorTurn::QUEUED) {
            return;
        }

        $turns->run($turn);
    }

    /**
     * The worker gave up on the job (timeout, crash): close the turn so the chat stops waiting.
     */
    public function failed(?Throwable $exception): void
    {
        $turn = MentorTurn::query()->find($this->turnId);

        if ($turn === null || ! $turn->isActive()) {
            return;
        }

        $turns = app(MentorTurnService::class);
        $turns->append($turn, [['type' => 'text-delta', 'delta' => MentorChatStreamProtocol::FAILURE_MESSAGE]]);
        $turns->finish($turn, MentorTurn::FAILED, 'The reply did not finish.');
    }
}
