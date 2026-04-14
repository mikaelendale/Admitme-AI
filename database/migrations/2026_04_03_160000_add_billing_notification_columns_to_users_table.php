<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notified_expiry_at')->nullable()->after('platform_pass_ends_at');
            $table->timestamp('payment_failed_notified_at')->nullable()->after('notified_expiry_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notified_expiry_at', 'payment_failed_notified_at']);
        });
    }
};
