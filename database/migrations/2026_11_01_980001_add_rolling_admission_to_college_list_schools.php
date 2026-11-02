<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rolling admission: a college that decides as applications arrive has no fixed deadline, which still counts
 * as knowing its deadline (the tracker asks for one of Early Action, Early Decision, Regular Decision or rolling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->boolean('rolling_admission')->default(false)->after('rd_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->dropColumn('rolling_admission');
        });
    }
};
