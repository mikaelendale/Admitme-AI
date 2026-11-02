<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

/**
 * Laravel AI 0.10 replaced the conversation tables' `user_id` with a polymorphic participant.
 * Every existing row belongs to a User, so the participant type is backfilled with its morph class.
 *
 * The final indexes match the package's 1.x conversation migration, including `agent` in `participant_index`.
 *
 * @see https://github.com/laravel/ai/blob/v1.3.0/UPGRADE.md#polymorphic-conversation-participants
 */
return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conversations = $this->conversationsTable();
        $messages = $this->messagesTable();

        $this->schema()->table($conversations, function (Blueprint $table) {
            $table->dropIndex(['user_id', 'updated_at']);
            $table->renameColumn('user_id', 'participant_id');
            $table->string('participant_type')->nullable();
        });

        $this->schema()->table($messages, function (Blueprint $table) {
            $table->dropIndex('conversation_index');
            $table->dropIndex(['user_id']);
            $table->renameColumn('user_id', 'participant_id');
            $table->string('participant_type')->nullable();
        });

        $participantType = (new User)->getMorphClass();

        foreach ([$conversations, $messages] as $table) {
            DB::connection($this->getConnection())
                ->table($table)
                ->whereNotNull('participant_id')
                ->update(['participant_type' => $participantType]);
        }

        $this->schema()->table($conversations, function (Blueprint $table) {
            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        $this->schema()->table($messages, function (Blueprint $table) {
            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $conversations = $this->conversationsTable();
        $messages = $this->messagesTable();

        $this->schema()->table($conversations, function (Blueprint $table) {
            $table->dropIndex('participant_updated_at_index');
            $table->dropColumn('participant_type');
            $table->renameColumn('participant_id', 'user_id');
        });

        $this->schema()->table($messages, function (Blueprint $table) {
            $table->dropIndex('conversation_index');
            $table->dropIndex('participant_index');
            $table->dropColumn('participant_type');
            $table->renameColumn('participant_id', 'user_id');
        });

        $this->schema()->table($conversations, function (Blueprint $table) {
            $table->index(['user_id', 'updated_at']);
        });

        $this->schema()->table($messages, function (Blueprint $table) {
            $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $table->index(['user_id']);
        });
    }

    protected function schema(): Builder
    {
        return Schema::connection($this->getConnection());
    }

    protected function conversationsTable(): string
    {
        return config('ai.conversations.tables.conversations', 'agent_conversations');
    }

    protected function messagesTable(): string
    {
        return config('ai.conversations.tables.messages', 'agent_conversation_messages');
    }
};
