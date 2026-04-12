<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table): void {
            $table->json('ai_context')->nullable()->after('last_ai_structured_at');
            $table->timestamp('last_ai_context_at')->nullable()->after('ai_context');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table): void {
            $table->dropColumn(['ai_context', 'last_ai_context_at']);
        });
    }
};
