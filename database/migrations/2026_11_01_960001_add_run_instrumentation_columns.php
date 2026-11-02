<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11: every model run records what produced it and how it went — provider, model, instruction
 * version, duration, which tools it called with what outcome, and the evidence it cited. Tool arguments
 * and results are not stored (they can hold private content); only names and outcome labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_context_snapshots', function (Blueprint $table) {
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // [{name, outcome}] — outcome is the tool's own status label (e.g. pending_approval), "failed" or "denied".
            $table->json('tool_calls')->nullable();
            // Public URLs the reply cited (web search); private sources are referenced by id elsewhere.
            $table->json('evidence')->nullable();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('provider', 40)->nullable();
            // A short hash of the specialist's code (its instructions live there), so results can be compared across versions.
            $table->string('instruction_version', 32)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['provider', 'instruction_version', 'duration_ms']);
        });

        Schema::table('ai_context_snapshots', function (Blueprint $table) {
            $table->dropColumn(['provider', 'model', 'duration_ms', 'tool_calls', 'evidence']);
        });
    }
};
