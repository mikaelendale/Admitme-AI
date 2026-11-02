<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: derived conversation summaries (with message references) and per-run context
 * instrumentation. Both are scoped to the student and deleted with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->index();
            // [{"text": "...", "message_ids": ["..."]}] — every point cites the stored messages it came from.
            $table->json('items');
            // The newest stored message the summary covers (messages are ordered by their uuid7 id).
            $table->string('covers_through_message_id', 36);
            $table->unsignedInteger('message_count');
            // Profile revision when written; a newer revision means facts may have changed since.
            $table->unsignedInteger('profile_revision');
            $table->timestamps();
        });

        Schema::create('ai_context_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->string('agent', 120);
            $table->string('mode', 16);
            $table->unsignedInteger('profile_revision');
            $table->string('instruction_version', 32);
            // Estimated tokens per part (characters / 4) — not provider counts.
            $table->json('estimated_tokens');
            $table->unsignedInteger('history_rows_included')->default(0);
            $table->unsignedInteger('history_rows_total')->default(0);
            $table->foreignId('conversation_summary_id')->nullable()->constrained('conversation_summaries')->nullOnDelete();
            $table->json('tools');
            // Provider-reported usage, filled in when the run finishes.
            $table->json('usage')->nullable();
            $table->string('outcome', 16)->default('started');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_context_snapshots');
        Schema::dropIfExists('conversation_summaries');
    }
};
