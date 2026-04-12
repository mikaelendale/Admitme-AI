<?php

namespace Tests\Unit;

use App\Events\CollegeListCouncilBroadcast;
use App\Models\CollegeListRun;
use App\Models\CollegeListRunEvent;
use App\Models\User;
use App\Services\CollegeListCouncil\CollegeListRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CollegeListRunRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_status_persists_and_dispatches_broadcast(): void
    {
        Event::fake([CollegeListCouncilBroadcast::class]);

        $user = User::factory()->create();
        $run = CollegeListRun::factory()->create([
            'user_id' => $user->id,
            'status' => CollegeListRun::STATUS_QUEUED,
        ]);

        $recorder = app(CollegeListRunRecorder::class);
        $recorder->transitionStatus($run, CollegeListRun::STATUS_PREFLIGHT);

        $this->assertSame(CollegeListRun::STATUS_PREFLIGHT, $run->fresh()->status);

        Event::assertDispatched(CollegeListCouncilBroadcast::class, function (CollegeListCouncilBroadcast $e) use ($run) {
            return $e->runId === (string) $run->id
                && $e->type === 'status'
                && ($e->data['status'] ?? null) === CollegeListRun::STATUS_PREFLIGHT;
        });
    }

    public function test_append_event_orders_rows_and_dispatches_insight(): void
    {
        Event::fake([CollegeListCouncilBroadcast::class]);

        $user = User::factory()->create();
        $run = CollegeListRun::factory()->create(['user_id' => $user->id]);

        $recorder = app(CollegeListRunRecorder::class);
        $recorder->appendEvent($run, 'preflight', CollegeListRunEvent::SEVERITY_INFO, 'A', 'body-a');
        $recorder->appendEvent($run, 'scout', CollegeListRunEvent::SEVERITY_MATCH, 'B', 'body-b');

        $titles = $run->events()->orderBy('id')->pluck('title')->all();
        $this->assertSame(['A', 'B'], $titles);

        Event::assertDispatched(CollegeListCouncilBroadcast::class, function (CollegeListCouncilBroadcast $e) {
            return $e->type === 'insight' && ($e->data['title'] ?? null) === 'B';
        });
    }

    public function test_complete_and_fail_set_terminal_state(): void
    {
        Event::fake([CollegeListCouncilBroadcast::class]);

        $user = User::factory()->create();
        $run = CollegeListRun::factory()->create(['user_id' => $user->id]);

        $recorder = app(CollegeListRunRecorder::class);
        $recorder->complete($run, 'Done.');
        $this->assertTrue($run->fresh()->isTerminal());
        $this->assertSame('Done.', $run->fresh()->summary_text);

        $run2 = CollegeListRun::factory()->create(['user_id' => $user->id]);
        $recorder->fail($run2, 'Oops.');
        $this->assertSame(CollegeListRun::STATUS_FAILED, $run2->fresh()->status);
        $this->assertSame('Oops.', $run2->fresh()->error_message);
    }
}
