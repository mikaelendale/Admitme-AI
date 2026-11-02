<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * College choices move through suggested → shortlisted → applying → archived (see docs/admitme/decisions.md D-003).
 *
 * Existing rows are already on the student's saved list, so they start as `shortlisted`; rows the student
 * marked as applying (or beyond) become `applying`, and soft-removed rows become `archived`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->string('choice_status', 20)->default('shortlisted');
            $table->unsignedInteger('revision')->default(1);

            $table->index(['user_id', 'choice_status']);
        });

        DB::table('college_list_schools')
            ->whereIn('application_status', ['applying', 'submitted', 'accepted', 'rejected', 'waitlisted'])
            ->update(['choice_status' => 'applying']);

        DB::table('college_list_schools')
            ->where('is_removed', true)
            ->update(['choice_status' => 'archived']);
    }

    public function down(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'choice_status']);
            $table->dropColumn(['choice_status', 'revision']);
        });
    }
};
