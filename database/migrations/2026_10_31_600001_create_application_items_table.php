<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6: the application workspace checklist. Items come from source-backed requirements, sensible
 * defaults, or the student. Third-party work (recommendations, official score reports, school reports)
 * has an owner and is never complete just because the student asked for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Stable key, so rebuilding the checklist from requirements updates items instead of duplicating them.
            $table->string('item_key', 160);
            // deadline | form | fee | test | english_test | document | essay | recommendation | interview | financial_aid | portal_step | other
            $table->string('kind', 24);
            $table->string('title', 200);
            // Writing prompt, conditions or instructions.
            $table->text('details')->nullable();
            // requirement | default | manual
            $table->string('origin', 16);
            $table->foreignId('admission_requirement_id')->nullable()->constrained('admission_requirements')->nullOnDelete();
            // student | third_party
            $table->string('owner_type', 16)->default('student');
            // Who must do third-party work, e.g. "School counselor", "Teacher", "Testing agency".
            $table->string('owner_label', 120)->nullable();
            // student: todo | in_progress | done | blocked | not_applicable
            // third_party: not_requested | requested | received | blocked | not_applicable
            $table->string('status', 24);
            // Why it is blocked / not applicable, or a note on how it was completed.
            $table->text('status_note')->nullable();
            $table->date('due_date')->nullable();
            $table->string('due_time', 8)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            // student_reported | portal_shows_received | sender_confirmed — how we know it is done.
            $table->string('completion_basis', 32)->nullable();
            // Set when a rebuild no longer finds the requirement this item came from.
            $table->timestamp('source_missing_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['application_id', 'item_key']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('checklist_built_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('checklist_built_at');
        });

        Schema::dropIfExists('application_items');
    }
};
