<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: the browser bridge. A linked extension holds a scoped, revocable token; sessions are bound to
 * student, device, tab and origin; observations are filtered snapshots; commands come only from approved
 * proposals, bound to the snapshot they were planned against, leased, executed once and read back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('extension_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_label', 120);
            $table->string('token_hash', 64)->unique();
            // What the token may do; the bridge only ever grants "browser:session".
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('browser_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_token_id')->constrained('extension_tokens')->cascadeOnDelete();
            $table->foreignUlid('application_id')->nullable()->constrained('applications')->nullOnDelete();
            // live | simulated — a simulated session never completes a real application step.
            $table->string('transport', 16)->default('live');
            $table->string('origin', 255);
            $table->unsignedBigInteger('tab_id');
            // active | waiting_for_browser | closed
            $table->string('status', 24)->default('active');
            $table->string('latest_snapshot_id', 26)->nullable();
            // What the extension needs to resume after a reconnect.
            $table->json('checkpoint')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('browser_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('browser_session_id')->constrained('browser_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('origin', 255);
            $table->string('page_hash', 64);
            // [{field_id, label, name, type, value, options, required}] — filtered: no passwords, no hidden fields.
            $table->json('fields');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('browser_commands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('browser_session_id')->constrained('browser_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('proposal_id', 26)->nullable()->index();
            $table->unsignedInteger('proposal_revision')->nullable();
            // observe_page | fill_field | select_option | click_supported_control
            $table->string('type', 32);
            $table->json('payload');
            // Bound to exactly where it was planned.
            $table->string('snapshot_id', 26);
            $table->unsignedBigInteger('tab_id');
            $table->string('origin', 255);
            $table->string('idempotency_key', 120)->unique();
            // pending | leased | completed | failed | frozen | stale | cancelled
            $table->string('status', 16)->default('pending');
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            // What the extension observed after acting (read back).
            $table->json('result')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['browser_session_id', 'status']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            // Extra binding for proposals that act outside AdmitMe (e.g. browser session, snapshot, tab, origin).
            $table->json('context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('context');
        });

        Schema::dropIfExists('browser_commands');
        Schema::dropIfExists('browser_snapshots');
        Schema::dropIfExists('browser_sessions');
        Schema::dropIfExists('extension_tokens');
        Schema::dropIfExists('extension_link_codes');
    }
};
