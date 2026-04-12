<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow billing rows to use source "stripe" (FinalizeOnboardingCheckoutSession).
     * SQLite implements enum() as a CHECK; widening to string preserves existing values.
     */
    public function up(): void
    {
        Schema::table('onboarding_responses', function (Blueprint $table): void {
            $table->string('source', 32)->default('form')->change();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_responses', function (Blueprint $table): void {
            $table->enum('source', ['form', 'freeform_paste', 'chat', 'stripe'])
                ->default('form')
                ->change();
        });
    }
};
