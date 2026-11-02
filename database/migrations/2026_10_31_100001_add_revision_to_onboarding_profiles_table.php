<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimistic-concurrency revision for the canonical student profile (see docs/admitme/decisions.md D-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
