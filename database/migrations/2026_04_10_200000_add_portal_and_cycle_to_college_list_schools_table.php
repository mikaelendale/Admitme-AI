<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->string('application_portal_url', 2048)->nullable()->after('rd_deadline');
            $table->string('admissions_cycle_label', 64)->nullable()->after('application_portal_url');
        });
    }

    public function down(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->dropColumn(['application_portal_url', 'admissions_cycle_label']);
        });
    }
};
