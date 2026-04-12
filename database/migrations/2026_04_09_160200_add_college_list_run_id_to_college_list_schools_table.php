<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->foreignUlid('college_list_run_id')
                ->nullable()
                ->after('user_id')
                ->constrained('college_list_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('college_list_schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('college_list_run_id');
        });
    }
};
