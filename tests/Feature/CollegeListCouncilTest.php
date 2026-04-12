<?php

namespace Tests\Feature;

use App\Events\CollegeListCouncilBroadcast;
use App\Jobs\RunCollegeListCouncilJob;
use App\Models\CollegeListRun;
use App\Models\CollegeListSchool;
use App\Models\User;
use App\Services\CollegeListCouncil\CollegeListCouncilBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CollegeListCouncilTest extends TestCase
{
    use RefreshDatabase;

    protected function useReverbBroadcasterForAuth(): void
    {
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb', [
            'driver' => 'reverb',
            'key' => 'local-test-key',
            'secret' => 'local-test-secret',
            'app_id' => 'local-test-id',
            'options' => [
                'host' => 'localhost',
                'port' => 443,
                'scheme' => 'https',
                'useTLS' => true,
            ],
            'client_options' => [],
        ]);

        $this->app->make(BroadcastManager::class)->forgetDrivers();

        // Channel authorizations are bound to the default broadcaster resolved at boot
        // (phpunit uses `null`); re-register on the Reverb/Pusher driver used for auth tests.
        require base_path('routes/channels.php');
    }

    public function test_store_returns_404_when_council_disabled(): void
    {
        Config::set('college_list_council.enabled', false);

        $user = User::factory()->create(['onboarded' => true]);

        $this->actingAs($user)
            ->postJson(route('college-lists.council-runs.store'), [
                'message' => 'Build my college list',
            ])
            ->assertNotFound();
    }

    public function test_store_creates_run_dispatches_job_and_show_returns_events_and_schools(): void
    {
        Config::set('college_list_council.enabled', true);

        Event::fake([CollegeListCouncilBroadcast::class]);

        $user = User::factory()->create(['onboarded' => true]);

        $store = $this->actingAs($user)
            ->postJson(route('college-lists.council-runs.store'), [
                'message' => 'Please build my college list for testing council.',
                'intent' => 'auto',
            ]);

        $store->assertCreated();
        $runId = (string) $store->json('run_id');
        $this->assertNotSame('', $runId);

        $run = CollegeListRun::query()->find($runId);
        $this->assertNotNull($run);
        $this->assertSame(
            CollegeListRun::STATUS_COMPLETED,
            $run->fresh()->status,
            'Run error: '.(string) $run->fresh()->error_message,
        );
        $this->assertGreaterThanOrEqual(3, $run->events()->count());

        $school = CollegeListSchool::query()
            ->where('user_id', $user->id)
            ->where('school_slug', 'test-safety-university')
            ->first();
        $this->assertNotNull($school);
        $this->assertSame($runId, $school->college_list_run_id);

        $first = $this->actingAs($user)
            ->getJson(route('college-lists.council-runs.show', ['run' => $runId]))
            ->assertOk()
            ->json('events');

        $second = $this->actingAs($user)
            ->getJson(route('college-lists.council-runs.show', ['run' => $runId]))
            ->assertOk()
            ->json('events');

        $this->assertSame($first, $second);

        Event::assertDispatched(CollegeListCouncilBroadcast::class, 8);
    }

    public function test_store_returns_409_when_user_has_active_run(): void
    {
        Config::set('college_list_council.enabled', true);

        $user = User::factory()->create(['onboarded' => true]);

        CollegeListRun::factory()->create([
            'user_id' => $user->id,
            'status' => CollegeListRun::STATUS_SCOUTING,
        ]);

        $this->actingAs($user)
            ->postJson(route('college-lists.council-runs.store'), [
                'message' => 'Build my college list',
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'A college list build is already in progress.']);
    }

    public function test_show_returns_forbidden_for_other_users_run(): void
    {
        Config::set('college_list_council.enabled', true);

        $owner = User::factory()->create(['onboarded' => true]);
        $other = User::factory()->create(['onboarded' => true]);

        $run = CollegeListRun::factory()->create([
            'user_id' => $owner->id,
            'status' => CollegeListRun::STATUS_COMPLETED,
        ]);

        $this->actingAs($other)
            ->getJson(route('college-lists.council-runs.show', ['run' => $run->id]))
            ->assertForbidden();
    }

    public function test_broadcasting_auth_denies_private_channel_for_non_owner(): void
    {
        $this->useReverbBroadcasterForAuth();

        Config::set('college_list_council.enabled', true);

        $owner = User::factory()->create(['onboarded' => true]);
        $intruder = User::factory()->create(['onboarded' => true]);

        $run = CollegeListRun::factory()->create([
            'user_id' => $owner->id,
            'status' => CollegeListRun::STATUS_SCOUTING,
        ]);

        $this->actingAs($intruder)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-college-list-runs.'.$run->id,
                'socket_id' => '123.456',
            ])
            ->assertForbidden();
    }

    public function test_broadcasting_auth_allows_private_channel_for_owner(): void
    {
        $this->useReverbBroadcasterForAuth();

        Config::set('college_list_council.enabled', true);

        $owner = User::factory()->create(['onboarded' => true]);

        $run = CollegeListRun::factory()->create([
            'user_id' => $owner->id,
            'status' => CollegeListRun::STATUS_SCOUTING,
        ]);

        $this->actingAs($owner)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-college-list-runs.'.$run->id,
                'socket_id' => '123.456',
            ])
            ->assertOk();
    }

    public function test_show_includes_preflight_fields_when_awaiting(): void
    {
        Config::set('college_list_council.enabled', true);

        $user = User::factory()->create(['onboarded' => true]);

        $run = CollegeListRun::factory()->create([
            'user_id' => $user->id,
            'status' => CollegeListRun::STATUS_AWAITING_PREFLIGHT,
            'meta' => [
                'preflight_questions_pending' => ['What is your budget?'],
            ],
        ]);

        $this->actingAs($user)
            ->getJson(route('college-lists.council-runs.show', ['run' => $run->id]))
            ->assertOk()
            ->assertJsonPath('awaiting_preflight', true)
            ->assertJsonPath('preflight_questions.0', 'What is your budget?');
    }

    public function test_preflight_reply_dispatches_job_when_awaiting(): void
    {
        Bus::fake([RunCollegeListCouncilJob::class]);

        Config::set('college_list_council.enabled', true);

        $user = User::factory()->create(['onboarded' => true]);

        $run = CollegeListRun::factory()->create([
            'user_id' => $user->id,
            'status' => CollegeListRun::STATUS_AWAITING_PREFLIGHT,
            'meta' => ['preflight_questions_pending' => ['Q1?']],
        ]);

        $this->actingAs($user)
            ->postJson(route('college-lists.council-runs.preflight-reply', ['run' => $run->id]), [
                'reply' => 'My budget is 30k.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Bus::assertDispatched(RunCollegeListCouncilJob::class);

        $run->refresh();
        $this->assertSame(CollegeListRun::STATUS_QUEUED, $run->status);
        $this->assertSame('My budget is 30k.', $run->meta['preflight_student_reply'] ?? null);
    }

    public function test_preflight_reply_returns_409_when_not_awaiting(): void
    {
        Config::set('college_list_council.enabled', true);

        $user = User::factory()->create(['onboarded' => true]);

        $run = CollegeListRun::factory()->create([
            'user_id' => $user->id,
            'status' => CollegeListRun::STATUS_COMPLETED,
        ]);

        $this->actingAs($user)
            ->postJson(route('college-lists.council-runs.preflight-reply', ['run' => $run->id]), [
                'reply' => 'x',
            ])
            ->assertStatus(409);
    }

    public function test_council_broadcast_carries_channel_payload_and_name(): void
    {
        $broadcaster = app(CollegeListCouncilBroadcaster::class);
        Event::fake([CollegeListCouncilBroadcast::class]);

        $broadcaster->insight('01hqxyzexample123456789012', [
            'title' => 'Scout note',
            'severity' => 'info',
        ]);

        Event::assertDispatched(CollegeListCouncilBroadcast::class, function (CollegeListCouncilBroadcast $e) {
            return $e->type === 'insight'
                && ($e->data['title'] ?? null) === 'Scout note'
                && $e->broadcastOn()->name === 'private-college-list-runs.01hqxyzexample123456789012';
        });
    }
}
