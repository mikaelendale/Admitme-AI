<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Agents\Subagents\ApplicationPlannerSubagent;
use App\Ai\Agents\Subagents\CollegeScoutSubagent;
use App\Ai\Agents\Subagents\MentorSubagent;
use App\Ai\Agents\Subagents\ResearcherSubagent;
use App\Ai\Agents\Subagents\SubagentActivity;
use App\Ai\Agents\Subagents\SubagentTool;
use App\Ai\Agents\Subagents\WritingCoachSubagent;
use App\Ai\AiModelResolver;
use App\Ai\Context\ChatMode;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Streaming\EarlyToolResults;
use App\Ai\Streaming\MentorChatStreamProtocol;
use App\Ai\Tools\ReadStudentProfileTool;
use App\Domain\Approvals\ProposalService;
use App\Models\OnboardingProfile;
use App\Models\User;
use App\Support\MentorChatConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Events\ToolInvoked;
use Tests\TestCase;

/**
 * A mentor reply keeps its order (text, the tools used between paragraphs, the cards they produced), an
 * approval card's data arrives with the reply instead of a second request, and the specialists the mentor
 * delegates to are offered in both modes on the mentor's model.
 */
class MentorReplyPartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_turn_comes_back_in_the_order_it_happened(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create();
        $proposal = app(ProposalService::class)->proposeProfileChanges($student, ['sat_score' => ['value' => 1490]], 'test-agent');
        $conversation = $this->conversation($student);

        $this->storeTurn($student, $conversation, [
            $this->step('Let me check your profile first.', [
                ['id' => 'call_1', 'name' => 'ReadStudentProfileTool', 'arguments' => [], 'result' => json_encode(['ok' => true])],
            ]),
            $this->step('', [
                ['id' => 'call_2', 'name' => 'researcher', 'arguments' => ['task' => 'Find Purdue Fall 2027 deadlines'], 'result' => "Purdue EA is Nov 1.\n\n- [Purdue](https://purdue.edu)"],
                ['id' => 'call_3', 'name' => 'ProposeProfileChangesTool', 'arguments' => [], 'result' => json_encode(['status' => 'pending_approval', 'proposal_id' => $proposal->id])],
            ]),
            $this->step('It is waiting for your approval.', []),
        ]);

        $messages = MentorChatConversation::messagesForDisplay($conversation, $student->id);
        $parts = $messages[0]['parts'];

        $this->assertSame(['text', 'tool', 'tool', 'tool', 'proposal', 'text'], array_column($parts, 'type'));
        $this->assertSame('ReadStudentProfileTool', $parts[1]['name']);
        $this->assertSame('researcher', $parts[2]['name']);
        $this->assertSame('Find Purdue Fall 2027 deadlines', $parts[2]['detail']);
        $this->assertStringContainsString('Purdue EA is Nov 1.', $parts[2]['output']);
        $this->assertSame($proposal->id, $parts[4]['id']);
        $this->assertSame('pending', $parts[4]['proposal']['status'], 'The card data comes with the conversation.');
        $this->assertSame([$proposal->id], $messages[0]['proposal_ids'], 'The older field is still sent.');
    }

    public function test_another_students_proposal_is_never_filled_in(): void
    {
        $student = User::factory()->create();
        $other = User::factory()->create();
        OnboardingProfile::factory()->for($other)->create();
        $foreign = app(ProposalService::class)->proposeProfileChanges($other, ['sat_score' => ['value' => 1490]], 'test-agent');
        $conversation = $this->conversation($student);

        $this->storeTurn($student, $conversation, [
            $this->step('Done.', [
                ['id' => 'call_1', 'name' => 'ProposeProfileChangesTool', 'arguments' => [], 'result' => json_encode(['status' => 'pending_approval', 'proposal_id' => $foreign->id])],
            ]),
        ]);

        $proposalPart = collect(MentorChatConversation::messagesForDisplay($conversation, $student->id)[0]['parts'])->firstWhere('type', 'proposal');

        $this->assertNotNull($proposalPart);
        $this->assertArrayNotHasKey('proposal', $proposalPart);
    }

    public function test_the_stream_sends_the_card_data_right_after_the_tool_for_the_students_own_proposal_only(): void
    {
        $student = User::factory()->create();
        $other = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create();
        OnboardingProfile::factory()->for($other)->create();
        $own = app(ProposalService::class)->proposeProfileChanges($student, ['sat_score' => ['value' => 1490]], 'test-agent');
        $foreign = app(ProposalService::class)->proposeProfileChanges($other, ['sat_score' => ['value' => 1490]], 'test-agent');

        $protocol = new class($student) extends MentorChatStreamProtocol
        {
            /**
             * @return list<array<string, mixed>>
             */
            public function cardsFor(mixed $output): array
            {
                return iterator_to_array($this->cardParts($output), false);
            }
        };

        $parts = $protocol->cardsFor(json_encode(['status' => 'pending_approval', 'proposal_id' => $own->id]));
        $this->assertSame('data-proposal', $parts[0]['type']);
        $this->assertSame($own->id, $parts[0]['data']['proposal']['id']);

        $this->assertSame([], $protocol->cardsFor(json_encode(['status' => 'pending_approval', 'proposal_id' => $foreign->id])));
        $this->assertSame([], $protocol->cardsFor('A sub-agent is still writing…'));
    }

    public function test_specialists_are_offered_in_both_modes_and_run_on_the_mentors_model(): void
    {
        config([
            'admissions.general_agent_web_search' => true,
            'admitme.mentor.provider' => 'anthropic',
            'admitme.mentor.model' => null,
            'admitme.mentor.models' => ['anthropic' => 'claude-sonnet-5-5'],
            'admitme.ai.provider' => 'openai',
            'admitme.ai.models.openai' => 'gpt-5-nano',
        ]);
        $student = User::factory()->create();
        $policy = app(MentorToolPolicy::class);

        foreach ([ChatMode::Ask, ChatMode::Agent] as $mode) {
            $names = collect($policy->toolsFor($student, $mode))
                ->filter(fn (object $tool) => $tool instanceof SubagentTool && $tool->agent() instanceof MentorSubagent)
                ->map(fn (SubagentTool $tool) => $tool->name())
                ->values()
                ->all();

            $this->assertSame(['researcher', 'college_scout', 'application_planner', 'writing_coach'], $names, $mode->value);
        }

        $resolver = app(AiModelResolver::class);

        foreach ([ResearcherSubagent::class, CollegeScoutSubagent::class, ApplicationPlannerSubagent::class, WritingCoachSubagent::class] as $class) {
            $this->assertSame('anthropic', $resolver->providerFor($class), $class);
            $this->assertSame('claude-sonnet-5-5', $resolver->modelFor($class), $class);
            $this->assertNotSame('', trim((string) (new $class)->instructions()));
        }

        $this->assertSame(MentorChatConversation::SUBAGENTS, ['researcher', 'college_scout', 'application_planner', 'writing_coach']);
    }

    public function test_without_web_search_only_the_offline_specialists_remain(): void
    {
        config(['admissions.general_agent_web_search' => false]);

        $names = collect(app(MentorToolPolicy::class)->toolsFor(User::factory()->create(), ChatMode::Ask))
            ->filter(fn (object $tool) => $tool instanceof SubagentTool)
            ->map(fn (SubagentTool $tool) => $tool->name())
            ->values()
            ->all();

        $this->assertSame(['application_planner', 'writing_coach'], $names);
    }

    public function test_a_specialists_activity_rides_on_its_report_and_comes_back_apart(): void
    {
        $activity = [
            ['kind' => 'search', 'query' => 'Purdue early action 2027', 'status' => 'done', 'count' => 5, 'sources' => ['admissions.purdue.edu']],
        ];

        $stored = SubagentActivity::footer('Purdue EA is Nov 1.', $activity);
        [$report, $back] = SubagentActivity::split($stored);

        $this->assertSame('Purdue EA is Nov 1.', $report);
        $this->assertSame($activity, $back);
        $this->assertSame(['Plain report', []], SubagentActivity::split('Plain report'), 'A report without activity is left alone.');

        $student = User::factory()->create();
        $conversation = $this->conversation($student);
        $this->storeTurn($student, $conversation, [
            $this->step('Here is what the researcher found.', [
                ['id' => 'call_9', 'name' => 'researcher', 'arguments' => ['task' => 'Purdue deadlines'], 'result' => $stored],
            ]),
        ]);

        $part = collect(MentorChatConversation::messagesForDisplay($conversation, $student->id)[0]['parts'])->firstWhere('name', 'researcher');
        $this->assertSame('Purdue EA is Nov 1.', $part['output']);
        $this->assertSame($activity, $part['activity']);
    }

    public function test_a_tool_that_finished_is_sent_at_once_even_while_a_specialist_in_its_step_still_works(): void
    {
        $student = User::factory()->create();
        OnboardingProfile::factory()->for($student)->create();
        $proposal = app(ProposalService::class)->proposeProfileChanges($student, ['sat_score' => ['value' => 1490]], 'test-agent');
        $result = json_encode(['status' => 'pending_approval', 'proposal_id' => $proposal->id]);

        // The SDK reports the quick tool as invoked the moment it returns...
        app(EarlyToolResults::class)->record(new ToolInvoked('run', 'tool-run', new AdmitMeGeneralAgent($student), new ReadStudentProfileTool($student), ['section' => 'all'], $result, 3.0));

        $protocol = new class($student) extends MentorChatStreamProtocol
        {
            /**
             * @param  array<string, array{0: string, 1: array<string, mixed>}>  $pending
             * @return array{0: list<array<string, mixed>>, 1: array<string, true>}
             */
            public function early(array $pending): array
            {
                $sent = [];
                $parts = iterator_to_array($this->earlyResults($pending, $sent), false);

                return [$parts, $sent];
            }
        };

        // ...and it is matched to its call by name and arguments; the specialist's call stays waiting.
        [$parts, $sent] = $protocol->early([
            'call_quick' => ['ReadStudentProfileTool', ['section' => 'all']],
            'call_slow' => ['researcher', ['task' => 'Find deadlines']],
        ]);

        $this->assertSame(['tool-output-available', 'data-proposal'], array_column($parts, 'type'));
        $this->assertSame('call_quick', $parts[0]['toolCallId']);
        $this->assertSame(['call_quick' => true], $sent);
        $this->assertSame([], app(EarlyToolResults::class)->take(), 'Each result is sent once.');
    }

    private function conversation(User $user): string
    {
        $id = (string) Str::uuid();

        DB::table('agent_conversations')->insert([
            'id' => $id,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'T',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    private function step(string $text, array $calls): array
    {
        return ['content' => $text, 'tool_calls' => $calls, 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function storeTurn(User $user, string $conversation, array $steps): void
    {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => AdmitMeGeneralAgent::class,
            'role' => 'assistant',
            'content' => implode("\n\n", array_filter(array_column($steps, 'content'))),
            'attachments' => '[]',
            'steps' => json_encode($steps),
            'usage' => '{}',
            'meta' => '{}',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
