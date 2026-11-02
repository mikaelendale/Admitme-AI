<?php

namespace Tests\Unit;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Models\User;
use App\Support\MentorChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MentorChatConversationHydrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_include_council_feed_hint_from_persisted_tool_results(): void
    {
        $user = User::factory()->create();
        $convId = $this->createConversation($user);
        $runId = (string) Str::uuid();

        $this->storeAssistantTurn($user, $convId, 'Council started.', [
            [
                'id' => 'call_1',
                'name' => 'StartCollegeListCouncilJobTool',
                'arguments' => ['message' => 'x', 'intent' => 'auto'],
                'result_id' => 'fc_1',
                'result' => json_encode([
                    'ok' => true,
                    'council_started' => true,
                    'run_id' => $runId,
                    'status' => 'queued',
                ]),
            ],
        ]);

        $messages = MentorChatConversation::messagesForDisplay($convId, $user->id);
        $this->assertCount(1, $messages);
        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertSame('Council started.', $messages[0]['content']);
        $this->assertSame('Building your list', $messages[0]['activity'][0]['text']);
        $this->assertArrayHasKey('council_feed', $messages[0]);
        $this->assertSame($runId, $messages[0]['council_feed']['run_id']);
        $this->assertSame('running', $messages[0]['council_feed']['status']);
    }

    public function test_messages_include_essay_job_hint_from_persisted_tool_results(): void
    {
        $user = User::factory()->create();
        $convId = $this->createConversation($user);
        $runId = (string) Str::uuid();

        $this->storeAssistantTurn($user, $convId, 'Essay job queued.', [
            [
                'id' => 'call_2',
                'name' => 'StartEssayRunTool',
                'arguments' => [],
                'result_id' => 'fc_2',
                'result' => json_encode([
                    'ok' => true,
                    'essay_job_started' => true,
                    'run_id' => $runId,
                    'run_type' => 'brainstorm',
                    'status' => 'queued',
                ]),
            ],
        ]);

        $messages = MentorChatConversation::messagesForDisplay($convId, $user->id);
        $this->assertArrayHasKey('essay_job', $messages[0]);
        $this->assertSame($runId, $messages[0]['essay_job']['run_id']);
        $this->assertSame('brainstorm', $messages[0]['essay_job']['run_type']);
        $this->assertSame('running', $messages[0]['essay_job']['status']);
    }

    public function test_failed_assistant_turns_are_not_displayed(): void
    {
        $user = User::factory()->create();
        $convId = $this->createConversation($user);

        $this->storeAssistantTurn($user, $convId, 'Half a repl', [], status: 'failed');

        $this->assertSame([], MentorChatConversation::messagesForDisplay($convId, $user->id));
    }

    protected function createConversation(User $user): string
    {
        $convId = (string) Str::uuid();

        DB::table('agent_conversations')->insert([
            'id' => $convId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'T',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $convId;
    }

    /**
     * Store an assistant turn the way Laravel AI 1.x does: a tool-calling step followed by the reply.
     *
     * @param  list<array<string, mixed>>  $toolCalls
     */
    protected function storeAssistantTurn(User $user, string $convId, string $content, array $toolCalls, string $status = 'completed'): void
    {
        $step = fn (string $text, array $calls) => [
            'content' => $text,
            'tool_calls' => $calls,
            'reasoning' => '',
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];

        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $convId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => AdmitMeGeneralAgent::class,
            'role' => 'assistant',
            'content' => $content,
            'attachments' => '[]',
            'steps' => json_encode([$step('', $toolCalls), $step($content, [])]),
            'usage' => '{}',
            'meta' => '{}',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
