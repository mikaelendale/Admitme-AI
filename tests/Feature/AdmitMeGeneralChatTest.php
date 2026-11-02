<?php

namespace Tests\Feature;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\Context\ChatMode;
use App\Ai\Tools\GetCollegeListRunArtifactsTool;
use App\Ai\Tools\RunCollegeListAgentTool;
use App\Ai\Tools\StartCollegeListCouncilJobTool;
use App\Ai\Tools\StartEssayRunTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\TestCase;

class AdmitMeGeneralChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_use_dashboard_chat(): void
    {
        $this->postJson(route('dashboard.chat'), [
            'message' => 'Hello',
        ])->assertUnauthorized();
    }

    public function test_message_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('dashboard.chat'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    public function test_authenticated_user_receives_vercel_sse_stream(): void
    {
        AdmitMeGeneralAgent::fake(['Hello from AdmitMe']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('dashboard.chat'), [
            'message' => 'Hi',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));

        $raw = $response->streamedContent();
        $this->assertStringContainsString('text-delta', $raw);
        $this->assertStringContainsString('Hello', $raw);
        $this->assertStringContainsString('"type":"finish"', $raw);
        $this->assertStringContainsString('"type":"data-conversation-id"', $raw);
        $this->assertStringEndsWith("data: [DONE]\n\n", $raw);
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    public function test_general_agent_registers_council_tools_when_enabled_without_sync_fallback(): void
    {
        Config::set('college_list_council.enabled', true);
        Config::set('college_list_council.sync_fallback', false);

        $user = User::factory()->create();
        $tools = iterator_to_array((new AdmitMeGeneralAgent($user, ChatMode::Agent))->tools());
        $classes = array_map(fn ($t) => $t::class, $tools);

        $this->assertContains(StartCollegeListCouncilJobTool::class, $classes);
        $this->assertContains(GetCollegeListRunArtifactsTool::class, $classes);
        $this->assertContains(StartEssayRunTool::class, $classes);
        $this->assertNotContains(RunCollegeListAgentTool::class, $classes);
    }

    public function test_general_agent_registers_sync_list_tool_when_council_disabled(): void
    {
        Config::set('college_list_council.enabled', false);

        $user = User::factory()->create();
        $tools = iterator_to_array((new AdmitMeGeneralAgent($user, ChatMode::Agent))->tools());
        $classes = array_map(fn ($t) => $t::class, $tools);

        $this->assertContains(RunCollegeListAgentTool::class, $classes);
        $this->assertContains(StartEssayRunTool::class, $classes);
        $this->assertNotContains(StartCollegeListCouncilJobTool::class, $classes);
    }

    public function test_general_agent_includes_web_search_tool_by_default(): void
    {
        Config::set('college_list_council.enabled', true);
        Config::set('college_list_council.sync_fallback', false);

        $user = User::factory()->create();
        $tools = iterator_to_array((new AdmitMeGeneralAgent($user, ChatMode::Agent))->tools());
        $classes = array_map(fn ($t) => $t::class, $tools);

        $this->assertContains(WebSearch::class, $classes);
    }

    public function test_general_agent_omits_web_search_when_config_disabled(): void
    {
        Config::set('admissions.general_agent_web_search', false);
        Config::set('college_list_council.enabled', true);
        Config::set('college_list_council.sync_fallback', false);

        $user = User::factory()->create();
        $tools = iterator_to_array((new AdmitMeGeneralAgent($user, ChatMode::Agent))->tools());
        $classes = array_map(fn ($t) => $t::class, $tools);

        $this->assertNotContains(WebSearch::class, $classes);
    }
}
