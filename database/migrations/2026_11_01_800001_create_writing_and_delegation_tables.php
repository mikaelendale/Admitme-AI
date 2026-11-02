<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: delegation envelopes on agent runs, the student-owned writing workspace (pieces, immutable
 * versions, anchored feedback), institution writing-assistance policies, and application form plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            // The server-built handoff the specialist received (scope, goal, evidence refs, contract, budget).
            $table->json('handoff')->nullable();
        });

        Schema::table('institutions', function (Blueprint $table) {
            // no_ai_assistance | feedback_only | light_editing — null means the configured default.
            $table->string('writing_policy', 32)->nullable();
        });

        Schema::create('writing_pieces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('college_list_school_id')->nullable()->constrained('college_list_schools')->nullOnDelete();
            $table->foreignUlid('application_item_id')->nullable()->constrained('application_items')->nullOnDelete();
            $table->string('title', 200);
            $table->text('prompt_text')->nullable();
            $table->unsignedSmallInteger('word_limit')->nullable();
            // personal_statement | supplement | short_answer | other
            $table->string('kind', 32)->default('supplement');
            // draft | final
            $table->string('status', 16)->default('draft');
            // The assistance policy in force when the piece was created (shown with every feedback request).
            $table->string('assistance_policy', 32);
            $table->string('current_version_id', 26)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('writing_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('writing_piece_id')->constrained('writing_pieces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->longText('body');
            $table->unsignedInteger('word_count');
            // Who wrote it: always the student; AdmitMe never writes a version.
            $table->string('author', 16)->default('student');
            $table->string('body_hash', 64);
            // Versions are never updated.
            $table->timestamp('created_at')->nullable();

            $table->unique(['writing_piece_id', 'number']);
        });

        Schema::create('writing_feedback', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('writing_piece_id')->constrained('writing_pieces')->cascadeOnDelete();
            $table->foreignUlid('writing_version_id')->constrained('writing_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('assistance_policy', 32);
            // [{quote, comment, kind, severity}] — every quote is verbatim from the version.
            $table->json('comments');
            $table->text('summary')->nullable();
            // How many comments were dropped because their quote was not in the student's text.
            $table->unsignedSmallInteger('dropped_comments')->default(0);
            $table->string('model', 120)->nullable();
            $table->json('usage')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('application_form_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent_task_id', 26)->nullable()->index();
            // Which form snapshot it was planned against (synthetic until the browser bridge exists).
            $table->string('form_key', 64);
            // [{section, field, value, basis: profile|document|requirement|validation|model_judgment|suggestion, note}]
            $table->json('entries');
            $table->unsignedInteger('profile_revision');
            $table->string('model', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_form_plans');
        Schema::dropIfExists('writing_feedback');
        Schema::dropIfExists('writing_versions');
        Schema::dropIfExists('writing_pieces');

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn('writing_policy');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('handoff');
        });
    }
};
