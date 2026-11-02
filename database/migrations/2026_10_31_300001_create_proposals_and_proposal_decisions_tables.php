<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: durable approval proposals for agent-originated changes (see docs/admitme/decisions.md D-011).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            // Who proposed it, e.g. the agent class and tool.
            $table->string('actor', 120);
            // profile.update | college_list.add
            $table->string('type', 40);
            // pending | approved | rejected | expired | stale | applied | failed
            $table->string('status', 16)->default('pending');
            $table->string('title', 200);
            // [{key, label, before, after, reason, evidence}] — the exact reviewed payload.
            $table->json('items');
            // Revision of the target resource when proposed (e.g. the profile revision).
            $table->unsignedInteger('target_revision')->nullable();
            // Incremented when the student edits values before deciding.
            $table->unsignedInteger('revision')->default(1);
            $table->string('payload_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('proposal_decisions', function (Blueprint $table) {
            $table->id();
            // One decision per proposal: a retried or double-clicked approval cannot apply twice.
            $table->foreignUlid('proposal_id')->unique()->constrained('proposals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // approve | reject
            $table->string('decision', 16);
            $table->json('selected_keys');
            $table->unsignedInteger('proposal_revision');
            $table->string('payload_hash', 64);
            $table->string('idempotency_key', 80)->nullable()->unique();
            // Per-item outcome: applied | already_present | stale | skipped | rejected
            $table->json('receipt');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_decisions');
        Schema::dropIfExists('proposals');
    }
};
