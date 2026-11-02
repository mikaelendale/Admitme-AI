<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for every tracked profile value, plus an audit log for student-owned changes
 * (see docs/admitme/decisions.md D-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 64);
            $table->json('value')->nullable();
            // confirmed | candidate | inferred | conflict (App\Domain\Students\FactStatus); superseded facts keep their status and get superseded_at
            $table->string('status', 20);
            // manual | agent_tool | agent_proposal | ai_extraction | legacy_flow | system (App\Domain\Shared\ChangeSource)
            $table->string('source_type', 32);
            $table->json('source_ref')->nullable();
            // student | agent | system
            $table->string('actor_type', 16);
            $table->unsignedInteger('profile_revision');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'field_key', 'status']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            // The student whose data changed; audit rows are scoped to them.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('actor_type', 16);
            $table->string('actor_id', 64)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64);
            $table->string('subject_id', 64);
            $table->unsignedInteger('revision_before')->nullable();
            $table->unsignedInteger('revision_after')->nullable();
            $table->json('changes');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('student_facts');
    }
};
