<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_pass_plan', 32)->nullable()->after('trial_ends_at');
            $table->timestamp('platform_pass_ends_at')->nullable()->after('platform_pass_plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['platform_pass_plan', 'platform_pass_ends_at']);
        });
    }
};
