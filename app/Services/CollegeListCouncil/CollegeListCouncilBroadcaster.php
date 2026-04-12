<?php

namespace App\Services\CollegeListCouncil;

use App\Events\CollegeListCouncilBroadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollegeListCouncilBroadcaster
{
    public function status(string $runId, string $status, array $extra = []): void
    {
        $this->send($runId, 'status', array_merge(['status' => $status], $extra));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function insight(string $runId, array $data): void
    {
        $this->send($runId, 'insight', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function completed(string $runId, array $data = []): void
    {
        $this->send($runId, 'completed', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function failed(string $runId, array $data = []): void
    {
        $this->send($runId, 'failed', $data);
    }

    /**
     * @param  list<string>  $questions
     */
    public function preflightQuestions(string $runId, array $questions, int $timeoutSeconds): void
    {
        $this->send($runId, 'preflight_questions', [
            'questions' => $questions,
            'timeout_seconds' => $timeoutSeconds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $runId, string $type, array $data): void
    {
        try {
            broadcast(new CollegeListCouncilBroadcast($runId, $type, $data));
        } catch (Throwable $e) {
            // Reverb/Pusher unreachable must not fail the council job: events and status are in the DB; the UI can poll.
            Log::warning('College list council broadcast skipped.', [
                'run_id' => $runId,
                'broadcast_type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
