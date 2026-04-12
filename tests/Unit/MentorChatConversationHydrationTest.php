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
        $convId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $convId,
            'user_id' => $user->id,
            'title' => 'T',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assistantId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $toolResults = json_encode([
            [
                'id' => 'call_1',
                'name' => 'StartCollegeListCouncilJobTool',
                'arguments' => ['message' => 'x', 'intent' => 'auto'],
                'result' => json_encode([
                    'ok' => true,
                    'council_started' => true,
                    'run_id' => $runId,
                    'status' => 'queued',
                ]),
                'result_id' => 'fc_1',
            ],
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => $assistantId,
            'conversation_id' => $convId,
            'user_id' => $user->id,
            'agent' => AdmitMeGeneralAgent::class,
            'role' => 'assistant',
            'content' => 'Council started.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => $toolResults,
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messages = MentorChatConversation::messagesForDisplay($convId, $user->id);
        $this->assertCount(1, $messages);
        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertArrayHasKey('council_feed', $messages[0]);
        $this->assertSame($runId, $messages[0]['council_feed']['run_id']);
        $this->assertSame('running', $messages[0]['council_feed']['status']);
    }

    public function test_messages_include_essay_job_hint_from_persisted_tool_results(): void
    {
        $user = User::factory()->create();
        $convId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $convId,
            'user_id' => $user->id,
            'title' => 'T',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assistantId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $toolResults = json_encode([
            [
                'id' => 'call_2',
                'name' => 'StartEssayRunTool',
                'arguments' => [],
                'result' => json_encode([
                    'ok' => true,
                    'essay_job_started' => true,
                    'run_id' => $runId,
                    'run_type' => 'brainstorm',
                    'status' => 'queued',
                ]),
                'result_id' => 'fc_2',
            ],
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => $assistantId,
            'conversation_id' => $convId,
            'user_id' => $user->id,
            'agent' => AdmitMeGeneralAgent::class,
            'role' => 'assistant',
            'content' => 'Essay job queued.',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => $toolResults,
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messages = MentorChatConversation::messagesForDisplay($convId, $user->id);
        $this->assertArrayHasKey('essay_job', $messages[0]);
        $this->assertSame($runId, $messages[0]['essay_job']['run_id']);
        $this->assertSame('brainstorm', $messages[0]['essay_job']['run_type']);
        $this->assertSame('running', $messages[0]['essay_job']['status']);
    }
}
