<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: review state for extracted candidate facts, and the situation / intake / budget fields
 * the guided onboarding asks for (with "unknown / not applicable" answers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_facts', function (Blueprint $table) {
            // Candidates only: pending | accepted | rejected | superseded
            $table->string('review_state', 16)->nullable();
            // Why a candidate is uncertain (e.g. an ambiguous grade), shown to the student.
            $table->text('note')->nullable();
        });

        Schema::table('onboarding_profiles', function (Blueprint $table) {
            // finishing_high_school | graduated | gap_year
            $table->string('applicant_situation', 32)->nullable();
            // yes | no
            $table->string('first_generation', 8)->nullable();
            $table->string('target_intake', 64)->nullable();
            // Yearly budget in the student's own currency; never converted silently.
            $table->unsignedInteger('budget_amount')->nullable();
            $table->string('budget_currency', 3)->nullable();
            // Field keys the student answered "don't know / not applicable".
            $table->json('unknown_fields_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table) {
            $table->dropColumn(['applicant_situation', 'first_generation', 'target_intake', 'budget_amount', 'budget_currency', 'unknown_fields_json']);
        });

        Schema::table('student_facts', function (Blueprint $table) {
            $table->dropColumn(['review_state', 'note']);
        });
    }
};
