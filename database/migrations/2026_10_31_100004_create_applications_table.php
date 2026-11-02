<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One application per student per college choice (see docs/admitme/decisions.md D-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('college_list_school_id')->constrained('college_list_schools')->cascadeOnDelete();
            // preparing | ready_for_review | ready_to_submit | submitted | decision_received | closed
            $table->string('status', 32)->default('preparing');
            // common_app | coalition | ucas | institution_portal | other
            $table->string('application_system', 32)->nullable();
            $table->string('portal_url', 2048)->nullable();
            $table->string('cycle_label', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            // portal_receipt | student_confirmation
            $table->string('submission_evidence_type', 32)->nullable();
            $table->text('submission_evidence_note')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'college_list_school_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
