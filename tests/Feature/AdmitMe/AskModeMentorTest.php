<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Context\ChatMode;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Streaming\MentorChatStreamProtocol;
use App\Ai\Tools\Contracts\ReadOnlyTool;
use App\Ai\Tools\ReadApplicationTool;
use App\Models\AiContextSnapshot;
use App\Models\Application;
use App\Models\CollegeListSchool;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Phase 2 gate: Ask mode is read-only on the server, chat continuity survives a reload, and every
 * run records what it was given.
 */
class AskModeMentorTest extends TestCase
{
    use RefreshDatabase;

    public function test_ask_mode_receives_only_read_only_tools(): void
    {
        config(['college_list_council.enabled' => true, 'college_list_council.sync_fallback' => true]);

        $student = User::factory()->create();
        $tools = app(MentorToolPolicy::class)->toolsFor($student, ChatMode::Ask);

        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $this->assertTrue($tool instanceof ReadOnlyTool || $tool instanceof WebSearch, $tool::class.' is not read-only.');
        }

        $agentTools = array_map(fn (object $tool) => class_basename($tool), app(MentorToolPolicy::class)->toolsFor($student, ChatMode::Agent));
        $this->assertContains('ProposeCollegeListAdditionsTool', $agentTools, 'Agent mode can act — through approval proposals.');
        $this->assertContains('StartEssayRunTool', $agentTools);
    }

    public function test_an_ask_mode_attempt_to_change_the_list_is_rejected_server_side(): void
    {
        $student = User::factory()->create();

        // The model tries to call a write tool that Ask mode never offered...
        AdmitMeGeneralAgent::fake([
            new ToolCall('call_1', 'UpsertStudentCollegeList', ['school_name' => 'Synthetic Fir College', 'list_type' => 'reach']),
            'Added it!',
        ]);

        $response = $this->actingAs($student)->postJson(route('dashboard.chat'), [
            'message' => 'Add Synthetic Fir College to my list',
            'mode' => 'ask',
        ]);

        $raw = $response->streamedContent();

        $this->assertSame(0, CollegeListSchool::query()->where('user_id', $student->id)->count());
        $this->assertStringContainsString(json_encode(MentorChatStreamProtocol::FAILURE_MESSAGE) ?: '', $raw);
        $this->assertStringNotContainsString('Added it!', $raw, 'No success is reported for an action that did not happen.');
        $this->assertSame('failed', AiContextSnapshot::query()->where('user_id', $student->id)->value('outcome'));
    }

    public function test_an_ask_mode_request_to_change_the_gpa_writes_and_proposes_nothing(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create(['gpa_value' => 3.4]);
        $revision = OnboardingProfile::query()->where('user_id', $student->id)->value('revision');

        // Plan regression scenario: Ask mode must not write — even through a proposal tool it was never given.
        AdmitMeGeneralAgent::fake([
            new ToolCall('call_1', 'ProposeProfileChangesTool', ['changes' => [['field' => 'gpa_value', 'value' => '3.9', 'reason' => 'Student said so']]]),
            'Updated your GPA to 3.9.',
        ]);

        $raw = $this->actingAs($student)->postJson(route('dashboard.chat'), ['message' => 'Change my GPA to 3.9', 'mode' => 'ask'])->streamedContent();

        $this->assertSame(3.4, (float) OnboardingProfile::query()->where('user_id', $student->id)->value('gpa_value'));
        $this->assertSame($revision, OnboardingProfile::query()->where('user_id', $student->id)->value('revision'));
        $this->assertSame(0, Proposal::query()->where('user_id', $student->id)->count());
        $this->assertStringNotContainsString('Updated your GPA', $raw, 'No success is reported for a change that did not happen.');
    }

    public function test_agent_mode_turns_the_same_request_into_a_pending_proposal(): void
    {
        $student = User::factory()->create();

        AdmitMeGeneralAgent::fake([
            new ToolCall('call_1', 'ProposeCollegeListAdditionsTool', ['colleges' => [
                ['school_name' => 'Synthetic Fir College', 'list_type' => 'reach', 'reason' => 'Student asked for it.'],
            ]]),
            'Synthetic Fir College is waiting for your approval.',
        ]);

        $this->actingAs($student)
            ->postJson(route('dashboard.chat'), ['message' => 'Add Synthetic Fir College', 'mode' => 'agent'])
            ->streamedContent();

        $this->assertSame(0, CollegeListSchool::query()->where('user_id', $student->id)->count(), 'Nothing is added before approval.');
        $this->assertSame('pending', Proposal::query()->where('user_id', $student->id)->sole()->status->value);
    }

    public function test_requests_without_a_mode_default_to_ask(): void
    {
        $student = User::factory()->create();
        AdmitMeGeneralAgent::fake(['Hi']);

        $this->actingAs($student)->postJson(route('dashboard.chat'), ['message' => 'Hello'])->streamedContent();

        AdmitMeGeneralAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent instanceof AdmitMeGeneralAgent && $prompt->agent->mode === ChatMode::Ask);
    }

    public function test_conversation_continues_with_earlier_turns_after_a_reload(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create();

        AdmitMeGeneralAgent::fake(['Computer science fits your interests.', 'Yes — as we discussed, computer science.']);

        // Capture the history each run actually replays, at the moment the run starts...
        $replayed = [];
        Event::listen(StreamingAgent::class, function (StreamingAgent $event) use (&$replayed): void {
            $replayed[] = collect($event->prompt->agent->messages())->map(fn ($message) => $message->content ?? '')->all();
        });

        $this->actingAs($student)
            ->postJson(route('dashboard.chat'), ['message' => 'What should I study?', 'mode' => 'ask'])
            ->streamedContent();

        $conversationId = DB::table('agent_conversations')->where('participant_id', $student->id)->value('id');
        $this->assertNotNull($conversationId);
        $firstReply = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->value('content');

        // A fresh request (as after a page reload) only carries the conversation id...
        $this->actingAs($student)
            ->postJson(route('dashboard.chat'), ['message' => 'Remind me what you suggested?', 'conversation_id' => $conversationId, 'mode' => 'ask'])
            ->streamedContent();

        $this->assertSame([], $replayed[0], 'A new conversation has no history.');
        $this->assertSame(['What should I study?', $firstReply], $replayed[1], 'The continued run replays the earlier turn.');

        $this->actingAs($student)
            ->get(route('dashboard.chat.show', ['conversation' => $conversationId]))
            ->assertInertia(fn ($page) => $page->has('initial_chat_messages', 4));
    }

    public function test_each_run_records_mode_profile_revision_tools_and_context_size(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create();
        AdmitMeGeneralAgent::fake(['Here is what I know.']);

        $this->actingAs($student)->postJson(route('dashboard.chat'), ['message' => 'What do you know about me?', 'mode' => 'ask'])->streamedContent();

        $snapshot = AiContextSnapshot::query()->where('user_id', $student->id)->sole();

        $this->assertSame('ask', $snapshot->mode);
        $this->assertSame('completed', $snapshot->outcome);
        $this->assertSame(OnboardingProfile::query()->where('user_id', $student->id)->value('revision'), $snapshot->profile_revision);
        $this->assertContains('ReadStudentProfileTool', $snapshot->tools);
        $this->assertNotContains('UpsertStudentCollegeList', $snapshot->tools);
        $this->assertGreaterThan(0, $snapshot->estimated_tokens['memory']);
        $this->assertLessThanOrEqual((int) config('admitme.context.target_tokens'), $snapshot->estimated_tokens['total']);
        $this->assertNotNull($snapshot->conversation_id);
    }

    public function test_read_tools_never_return_another_students_records(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $school = CollegeListSchool::factory()->for($owner)->create(['choice_status' => 'applying']);
        $application = Application::factory()->create(['user_id' => $owner->id, 'college_list_school_id' => $school->id, 'notes' => 'private']);

        $result = json_decode((string) (new ReadApplicationTool($other))->handle(new Request(['application_id' => $application->id])), true);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringNotContainsString('private', json_encode($result) ?: '');
    }
}
