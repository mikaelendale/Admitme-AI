<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mentor replies run as background turns: the request only starts the turn, a queued job runs the agent, and the
 * stream parts are kept in order so the chat can follow them — and pick them up again after a page refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentor_turns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 64)->nullable()->index();
            $table->string('mode', 16);
            $table->text('message');
            $table->json('document_ids')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('mentor_turn_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('mentor_turn_id')->constrained('mentor_turns')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->longText('payload');
            $table->timestamp('created_at')->nullable();

            $table->unique(['mentor_turn_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentor_turn_events');
        Schema::dropIfExists('mentor_turns');
    }
};
