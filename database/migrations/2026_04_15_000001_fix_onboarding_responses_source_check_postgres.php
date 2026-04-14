<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL: widening `source` with ->change() can leave the original enum
     * CHECK constraint (onboarding_responses_source_check), which still only
     * allows form | freeform_paste | chat — so billing.checkout_verified rows
     * with source "stripe" fail.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE onboarding_responses DROP CONSTRAINT IF EXISTS onboarding_responses_source_check');

        DB::statement('ALTER TABLE onboarding_responses ALTER COLUMN source TYPE VARCHAR(32) USING (source::text)');

        DB::statement("ALTER TABLE onboarding_responses ALTER COLUMN source SET DEFAULT 'form'");
    }

    public function down(): void
    {
        // Restoring the old CHECK would reject stripe rows; no safe down.
    }
};
