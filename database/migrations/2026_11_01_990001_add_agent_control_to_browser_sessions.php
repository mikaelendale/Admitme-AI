<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mentor browser control: the student gives the mentor one tab from the extension's side panel. Such a session is
 * not bound to one origin or page (the mentor opens pages in it), so it records where the tab is instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_sessions', function (Blueprint $table) {
            // fill (Phase 10: approved fills on one origin) | agent (the mentor works in one tab)
            $table->string('mode', 16)->default('fill');
            $table->string('current_url', 2048)->nullable();
            $table->string('current_title', 300)->nullable();

            $table->index(['user_id', 'mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('browser_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'mode', 'status']);
            $table->dropColumn(['mode', 'current_url', 'current_title']);
        });
    }
};
