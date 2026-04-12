<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->enum('application_status', [
                'not_started',
                'researching',
                'applying',
                'submitted',
                'accepted',
                'rejected',
                'waitlisted',
            ])->nullable()->after('is_removed');
            $table->text('student_notes')->nullable()->after('application_status');
            $table->json('student_metadata')->nullable()->after('student_notes');
        });
    }

    public function down(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->dropColumn(['application_status', 'student_notes', 'student_metadata']);
        });
    }
};
