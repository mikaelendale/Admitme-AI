<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Agents\ConversationSummarizerAgent;
use App\Ai\Context\ChatMode;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\ConversationHistoryWindow;
use App\Ai\Tools\ReadStudentProfileTool;
use App\Domain\Memory\ConversationSummaryService;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\StudentProfileService;
use App\Jobs\SummarizeConversationJob;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Phase 2 gate: the mentor's memory is accurate and traceable, missing information is visible,
 * trimmed history keeps tool calls with their results, and corrected facts outrank older summaries.
 */
class MentorMemoryContextTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create([
            'sat_score' => null,
            'graduation_year' => null,
            'budget_band' => null,
        ]);

        $profiles = app(StudentProfileService::class);

        $profiles->update($this->student, ['sat_score' => 1510], null, ChangeSource::studentManual($this->student, 'test'));
        $profiles->update($this->student, ['curriculum' => 'IB Diploma'], null, new ChangeSource(
            ChangeSource::ACTOR_STUDENT, (string) $this->student->id, ChangeSource::SOURCE_AI_EXTRACTION, ['route' => 'profile-completion.dump'],
        ));
    }

    public function test_context_states_saved_facts_with_their_provenance_and_what_is_missing(): void
    {
        $context = app(ContextBuilder::class)->forMentor($this->student, ChatMode::Ask);

        $this->assertStringContainsString('SAT score: 1510 (confirmed by the student)', $context->instructions);
        $this->assertStringContainsString('Curriculum: IB Diploma (extracted by AI', $context->instructions);
        $this->assertMatchesRegularExpression('/### Not on file\n- .*Graduation year.*Budget band/s', $context->instructions);
        $this->assertStringContainsString('Mode: Ask (read-only)', $context->instructions);
        $this->assertSame(3, $context->profileRevision);
    }

    public function test_the_profile_read_tool_returns_the_same_values_and_provenance(): void
    {
        $result = json_decode((string) (new ReadStudentProfileTool($this->student))->handle(new Request(['section' => 'academics'])), true);

        $fields = collect($result['fields'])->keyBy('key');

        $this->assertSame(1510, $fields['sat_score']['value']);
        $this->assertTrue($fields['sat_score']['confirmed']);
        $this->assertFalse($fields['curriculum']['confirmed']);
        $this->assertContains('Graduation year', $result['missing']);
        $this->assertSame(3, $result['profile_revision']);
    }

    public function test_cached_memory_follows_the_profile_revision(): void
    {
        $tool = new ReadStudentProfileTool($this->student);
        $before = json_decode((string) $tool->handle(new Request(['section' => 'academics'])), true);

        app(StudentProfileService::class)->update($this->student, ['sat_score' => 1540], 3, ChangeSource::studentManual($this->student, 'test'));

        $after = json_decode((string) $tool->handle(new Request(['section' => 'academics'])), true);

        $this->assertSame(1510, collect($before['fields'])->firstWhere('key', 'sat_score')['value']);
        $this->assertSame(1540, collect($after['fields'])->firstWhere('key', 'sat_score')['value']);
    }

    public function test_trimmed_history_starts_on_a_turn_boundary_and_keeps_tool_calls_with_their_results(): void
    {
        config(['admitme.context.history_tokens' => 700, 'admitme.context.min_history_turns' => 2]);

        [$conversationId] = $this->conversationWithToolTurns(8);

        $agent = (new AdmitMeGeneralAgent($this->student))->continue($conversationId, $this->student);
        $window = $agent->historyWindow();
        $messages = array_values(iterator_to_array($agent->messages()));

        $this->assertGreaterThan(0, $window->excludedRows(), 'The budget should force older turns out.');
        $this->assertNotEmpty($messages);
        $this->assertNotInstanceOf(ToolResultMessage::class, $messages[0]);

        foreach ($messages as $index => $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            $previous = $messages[$index - 1] ?? null;
            $this->assertInstanceOf(AssistantMessage::class, $previous);

            $callIds = $previous->toolCalls->pluck('id')->all();
            foreach ($message->toolResults as $result) {
                $this->assertContains($result->id, $callIds, 'A tool result was replayed without its call.');
            }
        }
    }

    public function test_summaries_cite_real_messages_and_are_outranked_by_corrected_facts(): void
    {
        config(['admitme.context.history_tokens' => 700, 'admitme.context.min_history_turns' => 2]);

        [$conversationId, $ids] = $this->conversationWithToolTurns(8);

        ConversationSummarizerAgent::fake([[
            'items' => [
                ['text' => 'Student said their SAT was 1510 and asked about engineering.', 'message_ids' => [$ids[0], 'made-up-id']],
            ],
        ]]);

        $summary = app(ConversationSummaryService::class)->summarize($this->student, $conversationId, $ids[5]);

        $this->assertNotNull($summary);
        $this->assertSame([$ids[0]], $summary->items[0]['message_ids'], 'Invented message ids are dropped.');
        $this->assertSame($ids[5], $summary->covers_through_message_id);
        $this->assertSame(3, $summary->profile_revision);

        // The student corrects their score after the summary was written...
        app(StudentProfileService::class)->update($this->student, ['sat_score' => 1480], 3, ChangeSource::studentManual($this->student, 'test'));

        $agent = (new AdmitMeGeneralAgent($this->student))->continue($conversationId, $this->student);
        $context = app(ContextBuilder::class)->forMentor($this->student, ChatMode::Ask, $conversationId, $agent->historyWindow());

        $this->assertTrue($context->summaryOutdated);
        $this->assertStringContainsString('SAT score: 1480 (confirmed by the student)', $context->instructions);
        $this->assertStringContainsString('the profile is now at revision 4. Where this summary disagrees with the student brief, the brief is correct.', $context->instructions);
    }

    public function test_a_summary_is_queued_when_turns_fall_out_of_replayed_history(): void
    {
        config([
            'admitme.context.history_tokens' => 700,
            'admitme.context.min_history_turns' => 2,
            'admitme.summaries.min_unsummarized_turns' => 2,
        ]);

        Queue::fake([SummarizeConversationJob::class]);
        AdmitMeGeneralAgent::fake(['Sure.']);

        [$conversationId] = $this->conversationWithToolTurns(8);

        $this->actingAs($this->student)
            ->postJson(route('dashboard.chat'), ['message' => 'Continue', 'conversation_id' => $conversationId, 'mode' => 'ask'])
            ->streamedContent();

        Queue::assertPushed(SummarizeConversationJob::class, fn (SummarizeConversationJob $job) => $job->conversationId === $conversationId && $job->userId === $this->student->id);
    }

    public function test_history_window_inspects_stored_turns_only_for_the_given_conversation(): void
    {
        [$conversationId] = $this->conversationWithToolTurns(4);
        [$otherConversation] = $this->conversationWithToolTurns(6);

        $this->assertSame(8, app(ConversationHistoryWindow::class)->select($conversationId)->totalRows);
        $this->assertSame(12, app(ConversationHistoryWindow::class)->select($otherConversation)->totalRows);
    }

    /**
     * Store a synthetic conversation in the SDK 1.x format: alternating user turns and assistant turns,
     * every assistant turn calling a tool and answering. Ids sort in insertion order.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function conversationWithToolTurns(int $exchanges): array
    {
        static $sequence = 0;

        $conversationId = (string) Str::uuid();

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'participant_type' => $this->student->getMorphClass(),
            'participant_id' => $this->student->id,
            'title' => 'Synthetic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = [];
        $filler = str_repeat('Synthetic discussion about colleges and essays. ', 12);

        for ($i = 0; $i < $exchanges; $i++) {
            foreach (['user', 'assistant'] as $role) {
                $id = sprintf('0192f000-0000-7000-8000-%012d', ++$sequence);
                $ids[] = $id;

                $steps = $role === 'assistant' ? [
                    ['content' => '', 'tool_calls' => [[
                        'id' => "call_{$sequence}", 'name' => 'ReadCollegeListTool', 'arguments' => ['status' => 'active'],
                        'result_id' => "fc_{$sequence}", 'result' => '{"colleges":[]}',
                    ]], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
                    ['content' => "Answer {$i}. {$filler}", 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []],
                ] : [];

                DB::table('agent_conversation_messages')->insert([
                    'id' => $id,
                    'conversation_id' => $conversationId,
                    'participant_type' => $this->student->getMorphClass(),
                    'participant_id' => $this->student->id,
                    'agent' => AdmitMeGeneralAgent::class,
                    'role' => $role,
                    'content' => $role === 'user' ? "Question {$i}" : "Answer {$i}. {$filler}",
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

        return [$conversationId, $ids];
    }
}
