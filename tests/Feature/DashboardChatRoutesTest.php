<?php

namespace Tests\Feature;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardChatRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_chat_history_and_chat_show(): void
    {
        $id = (string) Str::uuid();

        $this->get(route('dashboard.chats.index'))->assertRedirect();
        $this->get(route('dashboard.chat.show', ['conversation' => $id]))->assertRedirect();
    }

    public function test_authenticated_user_sees_chat_history_page(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $convId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $convId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'My thread',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.chats.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard/chats-index')
                ->has('conversations', 1)
                ->where('conversations.0.id', $convId)
                ->where('conversations.0.title', 'My thread'));
    }

    public function test_owner_can_open_specific_conversation_with_messages_prop(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $convId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $convId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'T',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $msgId = (string) Str::uuid();
        DB::table('agent_conversation_messages')->insert([
            'id' => $msgId,
            'conversation_id' => $convId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => AdmitMeGeneralAgent::class,
            'role' => 'user',
            'content' => 'Hi mentor',
            'attachments' => '[]',
            'steps' => '[]',
            'status' => 'completed',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.chat.show', ['conversation' => $convId]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('initial_conversation_id', $convId)
                ->has('initial_chat_messages', 1)
                ->where('initial_chat_messages.0.role', 'user')
                ->where('initial_chat_messages.0.content', 'Hi mentor'));
    }

    public function test_other_user_cannot_open_foreign_conversation(): void
    {
        $owner = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);
        $other = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $convId = (string) Str::uuid();
        DB::table('agent_conversations')->insert([
            'id' => $convId,
            'participant_type' => $owner->getMorphClass(),
            'participant_id' => $owner->id,
            'title' => 'Private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($other)
            ->get(route('dashboard.chat.show', ['conversation' => $convId]))
            ->assertForbidden();
    }

    public function test_dashboard_without_conversation_has_null_initial_conversation(): void
    {
        $user = User::factory()->create([
            'onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('initial_conversation_id', null)
                ->where('initial_chat_messages', []));
    }
}
