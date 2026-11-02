<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: student data exports — a consistent snapshot captured at request time, built into a private
 * archive by a queued job, downloadable by its owner until it expires, then deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // queued | building | ready | failed | expired | deleted
            $table->string('status', 16)->default('queued');
            // What the student chose: sections plus document and writing ids.
            $table->json('selection');
            $table->string('schema_version', 16);
            // Revisions and immutable version ids captured at request time (what the archive must match).
            $table->json('snapshot');
            // workspace | chat
            $table->string('requested_via', 16);
            $table->string('disk', 32)->default('local');
            // Private working folder; the payload captured at request time lives here until the build.
            $table->string('directory', 255);
            $table->string('archive_path', 255)->nullable();
            $table->unsignedBigInteger('archive_size')->nullable();
            $table->string('archive_sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->text('status_reason')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
