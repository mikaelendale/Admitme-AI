<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7: durable task orchestration. A goal task owns typed steps with dependencies; every transition is
 * an event; each model call is an agent run. State lives here — never in a worker's memory or a chat stream.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable()->index();
            // Null for a goal; set for its steps.
            $table->foreignUlid('parent_id')->nullable()->constrained('agent_tasks')->cascadeOnDelete();
            // The step's key within its goal's plan.
            $table->string('step_key', 40)->nullable();
            // goal | college_research | affordability_check | propose_shortlist | refresh_sources
            $table->string('type', 40);
            $table->string('title', 200);
            $table->text('goal')->nullable();
            // queued | running | awaiting_approval | blocked | paused | completed | failed | cancelled
            $table->string('status', 24)->default('queued');
            $table->string('status_reason', 1000)->nullable();
            $table->json('input')->nullable();
            // What the task read when planned (e.g. profile revision), checked before its results are used.
            $table->json('input_revisions')->nullable();
            $table->json('result')->nullable();
            $table->string('proposal_id', 26)->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            // {max_model_calls: n}
            $table->json('budget')->nullable();
            // {model_calls, input_tokens, output_tokens}
            $table->json('usage')->nullable();
            // mentor | student
            $table->string('created_by', 40);
            $table->string('idempotency_key', 120)->nullable()->unique();
            // Not dispatched before this time (retry backoff).
            $table->timestamp('available_at')->nullable();
            // A worker holds a running task until then; an expired lease means the worker died.
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->unique(['parent_id', 'step_key']);
        });

        Schema::create('agent_task_dependencies', function (Blueprint $table) {
            $table->foreignUlid('task_id')->constrained('agent_tasks')->cascadeOnDelete();
            $table->foreignUlid('depends_on_id')->constrained('agent_tasks')->cascadeOnDelete();
            $table->primary(['task_id', 'depends_on_id']);
        });

        Schema::create('agent_task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('task_id')->constrained('agent_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('message', 1000);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['task_id', 'id']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained('agent_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent', 120);
            $table->string('model', 120)->nullable();
            // queued | running | waiting_for_tool | awaiting_approval | completed | failed | cancelled
            $table->string('status', 24);
            $table->json('usage')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('proposals', function (Blueprint $table) {
            // The task that created the proposal (actor/task ownership).
            $table->string('agent_task_id', 26)->nullable()->index();
        });

        Schema::table('college_list_schools', function (Blueprint $table) {
            // The orchestrated research task whose suggestion this is (its artifact).
            $table->string('agent_task_id', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->dropIndex(['agent_task_id']);
            $table->dropColumn('agent_task_id');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex(['agent_task_id']);
            $table->dropColumn('agent_task_id');
        });

        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_task_events');
        Schema::dropIfExists('agent_task_dependencies');
        Schema::dropIfExists('agent_tasks');
    }
};
