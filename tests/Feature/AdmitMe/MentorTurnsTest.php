<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Mentor\MentorTurnService;
use App\Jobs\RunMentorTurnJob;
use App\Models\MentorTurn;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mentor replies run as background turns: starting one returns at once, the reply's parts are kept in order and
 * read back with short requests (so pages keep loading and a refresh picks the reply up again), and the finished
 * reply is stored in the conversation even if the browser went away.
 */
class MentorTurnsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create();
    }

    public function test_a_reply_runs_in_the_background_keeps_its_parts_in_order_and_is_stored(): void
    {
        AdmitMeGeneralAgent::fake(['Your next deadline is Purdue on Nov 1.']);

        // The queue runs jobs inline in tests, so the turn has finished when the request returns.
        $turn = $this->actingAs($this->student)->postJson(route('dashboard.chat.turns.store'), ['message' => 'Which deadline is next?', 'mode' => 'ask'])
            ->assertStatus(202)
            ->assertJsonPath('turn.message', 'Which deadline is next?')
            ->json('turn');

        $read = $this->getJson(route('dashboard.chat.turns.events', ['mentorTurn' => $turn['id'], 'after' => 0]))->assertOk();
        $events = $read->json('events');
        $text = collect($events)->where('type', 'text-delta')->pluck('delta')->implode('');

        $this->assertSame('completed', $read->json('turn.status'));
        $this->assertStringContainsString('Your next deadline is Purdue on Nov 1.', $text);
        $this->assertSame('data-conversation-id', collect($events)->last()['type']);
        $this->assertSame(count($events), $read->json('cursor'));

        // Reading on from the cursor returns nothing new.
        $this->getJson(route('dashboard.chat.turns.events', ['mentorTurn' => $turn['id'], 'after' => $read->json('cursor')]))
            ->assertOk()
            ->assertJsonCount(0, 'events');

        // The reply is in the conversation, so reopening the chat shows it.
        $conversationId = $read->json('turn.conversation_id');
        $this->assertNotNull($conversationId);
        $this->assertSame(2, DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count());
        $this->getJson(route('dashboard.chat.turns.active'))->assertOk()->assertJsonPath('turn', null);
    }

    public function test_a_reply_still_running_is_found_again_after_a_refresh(): void
    {
        Queue::fake();

        $turn = $this->actingAs($this->student)->postJson(route('dashboard.chat.turns.store'), ['message' => 'Plan my week', 'mode' => 'agent'])
            ->assertStatus(202)
            ->json('turn');

        Queue::assertPushedOn('mentor', RunMentorTurnJob::class);

        $this->getJson(route('dashboard.chat.turns.active'))
            ->assertOk()
            ->assertJsonPath('turn.id', $turn['id'])
            ->assertJsonPath('turn.status', 'queued')
            ->assertJsonPath('turn.message', 'Plan my week')
            ->assertJsonPath('turn.mode', 'agent')
            // A new conversation is not stored until its first reply ends, so there is nothing more to show yet.
            ->assertJsonPath('conversation', null);
    }

    public function test_a_reply_in_a_stored_conversation_comes_back_with_its_earlier_messages(): void
    {
        $conversationId = (string) Str::uuid();
        DB::table('agent_conversations')->insert(['id' => $conversationId, 'participant_type' => $this->student->getMorphClass(), 'participant_id' => $this->student->id, 'title' => 'Deadlines', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['user', 'Which deadline is next?', 2], ['assistant', 'Purdue on Nov 1.', 1]] as [$role, $content, $minutesAgo]) {
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid7(), 'conversation_id' => $conversationId,
                'participant_type' => $this->student->getMorphClass(), 'participant_id' => $this->student->id,
                'agent' => AdmitMeGeneralAgent::class, 'role' => $role, 'content' => $content,
                'attachments' => '[]', 'steps' => '[]', 'usage' => '{}', 'meta' => '{}', 'status' => 'completed',
                'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        Queue::fake();
        $this->actingAs($this->student)->postJson(route('dashboard.chat.turns.store'), ['message' => 'And after that?', 'conversation_id' => $conversationId])->assertStatus(202);

        $active = $this->getJson(route('dashboard.chat.turns.active'))
            ->assertOk()
            ->assertJsonPath('turn.conversation_id', $conversationId)
            ->assertJsonPath('conversation.conversation_id', $conversationId)
            ->assertJsonPath('conversation.title', 'Deadlines');

        $this->assertSame(['Which deadline is next?', 'Purdue on Nov 1.'], array_column($active->json('conversation.messages'), 'content'));

        // Someone else's conversation id never brings its messages along.
        $other = User::factory()->create();
        OnboardingProfile::factory()->for($other)->create();
        MentorTurn::query()->create(['user_id' => $other->id, 'conversation_id' => $conversationId, 'mode' => 'ask', 'message' => 'Hi', 'status' => MentorTurn::QUEUED]);
        $this->actingAs($other)->getJson(route('dashboard.chat.turns.active'))->assertOk()->assertJsonPath('conversation', null);
    }

    public function test_a_first_reply_stopped_before_anything_was_stored_does_not_lock_the_student_out(): void
    {
        // The reply had its new conversation's id, but was stopped before the conversation was stored.
        $pending = (string) Str::uuid();
        MentorTurn::query()->create(['user_id' => $this->student->id, 'conversation_id' => $pending, 'mode' => 'ask', 'message' => 'First try', 'status' => MentorTurn::CANCELLED]);

        AdmitMeGeneralAgent::fake(['Here is your answer.']);

        $turn = $this->actingAs($this->student)->postJson(route('dashboard.chat.turns.store'), ['message' => 'Second try', 'conversation_id' => $pending])
            ->assertStatus(202)
            ->json('turn');

        // It starts a new conversation (stored once the reply ends) instead of answering 403.
        $read = $this->getJson(route('dashboard.chat.turns.events', ['mentorTurn' => $turn['id']]))->assertOk();
        $conversationId = $read->json('turn.conversation_id');
        $this->assertNotNull($conversationId);
        $this->assertNotSame($pending, $conversationId);
        $this->assertTrue($read->json('turn.conversation_stored'));
        $this->getJson(route('dashboard.chat.messages', ['conversation' => $conversationId]))->assertOk();

        // The stopped turn reports that nothing was stored, so the chat does not remember it.
        $stopped = MentorTurn::query()->where('conversation_id', $pending)->firstOrFail();
        $this->getJson(route('dashboard.chat.turns.events', ['mentorTurn' => $stopped->id]))->assertOk()->assertJsonPath('turn.conversation_stored', false);

        // An id from someone else's turn is still refused.
        $other = User::factory()->create();
        OnboardingProfile::factory()->for($other)->create();
        $this->actingAs($other)->postJson(route('dashboard.chat.turns.store'), ['message' => 'Hi', 'conversation_id' => $pending])->assertStatus(403);
    }

    public function test_one_reply_at_a_time(): void
    {
        Queue::fake();

        $this->actingAs($this->student)->postJson(route('dashboard.chat.turns.store'), ['message' => 'First'])->assertStatus(202);
        $this->postJson(route('dashboard.chat.turns.store'), ['message' => 'Second'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'turn_in_progress');
    }

    public function test_stopping_a_reply_that_has_not_started_cancels_it_at_once(): void
    {
        Queue::fake();

        $turn = $this->actingAs($this->student)->postJson(route('dashboard.chat.turns.store'), ['message' => 'Never mind'])->json('turn');

        $this->postJson(route('dashboard.chat.turns.cancel', ['mentorTurn' => $turn['id']]))
            ->assertOk()
            ->assertJsonPath('turn.status', 'cancelled');

        $events = $this->getJson(route('dashboard.chat.turns.events', ['mentorTurn' => $turn['id']]))->json('events');
        $this->assertSame('*Generation stopped.*', $events[0]['delta']);

        // The job finds the turn cancelled and does nothing.
        AdmitMeGeneralAgent::fake()->preventStrayPrompts();
        (new RunMentorTurnJob($turn['id']))->handle(app(MentorTurnService::class));
        AdmitMeGeneralAgent::assertNeverPrompted();
    }

    public function test_a_running_reply_that_stopped_writing_is_closed_so_the_student_can_go_on(): void
    {
        $turn = MentorTurn::query()->create([
            'user_id' => $this->student->id,
            'mode' => 'ask',
            'message' => 'Hello?',
            'status' => MentorTurn::RUNNING,
        ]);
        MentorTurn::query()->whereKey($turn->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->actingAs($this->student)->getJson(route('dashboard.chat.turns.active'))->assertOk()->assertJsonPath('turn', null);
        $this->assertSame(MentorTurn::FAILED, $turn->fresh()->status);

        Queue::fake();
        $this->postJson(route('dashboard.chat.turns.store'), ['message' => 'Try again'])->assertStatus(202);
    }

    public function test_turns_are_private_to_their_student(): void
    {
        Queue::fake();

        $turn = $this->actingAs($this->student)->postJson(route('dashboard.chat.turns.store'), ['message' => 'Mine'])->json('turn');
        $other = User::factory()->create();

        $this->actingAs($other)->getJson(route('dashboard.chat.turns.events', ['mentorTurn' => $turn['id']]))->assertNotFound();
        $this->actingAs($other)->postJson(route('dashboard.chat.turns.cancel', ['mentorTurn' => $turn['id']]))->assertNotFound();
        $this->actingAs($other)->getJson(route('dashboard.chat.turns.active'))->assertOk()->assertJsonPath('turn', null);
    }

    public function test_another_students_conversation_cannot_be_continued(): void
    {
        Queue::fake();
        $other = User::factory()->create();
        $conversationId = (string) Str::uuid();

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'participant_type' => $other->getMorphClass(),
            'participant_id' => $other->id,
            'title' => 'Theirs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->student)
            ->postJson(route('dashboard.chat.turns.store'), ['message' => 'Hi', 'conversation_id' => $conversationId])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }
}
