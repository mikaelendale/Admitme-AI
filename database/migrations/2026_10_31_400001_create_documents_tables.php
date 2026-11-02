<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: private student documents with immutable, hashed file versions and per-page extracted text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // transcript | test_score_report | resume | recommendation | financial | other
            $table->string('kind', 32);
            $table->string('title', 200);
            $table->string('current_version_id', 26)->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'kind']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            // queued | processing | needs_review | ready | failed | superseded
            $table->string('status', 20)->default('queued');
            $table->text('failure_reason')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version']);
            $table->index(['user_id', 'sha256']);
        });

        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('document_version_id')->constrained('document_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');
            $table->longText('text');

            $table->index(['document_version_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pages');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
