<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: admissions knowledge (institutions, sources with versions, passages, structured requirements
 * with evidence) and the student's stored research answers.
 *
 * Official and reference sources are shared by every student with that college. A page a student supplies
 * themselves is private to them (owner_user_id), as are its passages and requirements (D-016).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->string('country', 120)->nullable();
            // The institution's verified web domain (set by an operator, never by a student); pages on it are official.
            $table->string('website_domain', 190)->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            // Null = shared knowledge; set = only this student can see the source and anything derived from it.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // One row per URL per visibility scope ("i{institution}" or "i{institution}:u{user}").
            $table->string('scope_key', 80);
            $table->string('url', 2048);
            $table->string('url_hash', 64);
            $table->string('host', 190);
            $table->string('title', 300)->nullable();
            // official | reference | student_provided
            $table->string('source_type', 24);
            // The student said this is the official site; recorded, never trusted as verification.
            $table->boolean('claimed_official')->default(false);
            // active | failed
            $table->string('status', 16)->default('active');
            $table->text('failure_reason')->nullable();
            $table->string('current_version_id', 26)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_key', 'url_hash']);
            $table->index(['institution_id', 'owner_user_id', 'status']);
        });

        Schema::create('knowledge_source_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('knowledge_source_id')->constrained('knowledge_sources')->cascadeOnDelete();
            $table->longText('content_text');
            $table->string('content_hash', 64);
            $table->unsignedSmallInteger('http_status');
            $table->timestamp('fetched_at');
            // pending | extracted | failed — requirement extraction state for this version.
            $table->string('extraction_status', 16)->default('pending');
            $table->timestamps();
        });

        Schema::create('knowledge_passages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('knowledge_source_version_id')->constrained('knowledge_source_versions')->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            // Copied from the source so visibility is filtered in the query itself.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('text');

            $table->index(['institution_id', 'owner_user_id', 'knowledge_source_version_id']);
        });

        Schema::create('admission_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained()->cascadeOnDelete();
            // Copied from the source: null = shared, set = private to that student.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Null program = applies to all programmes.
            $table->string('program', 200)->nullable();
            $table->string('cycle_label', 64)->nullable();
            // deadline | fee | test_policy | english_test | document | essay | recommendation | interview | cost | aid | other
            $table->string('requirement_type', 32);
            $table->string('label', 200);
            $table->text('value_text')->nullable();
            $table->date('deadline_date')->nullable();
            $table->string('deadline_time', 8)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('conditions')->nullable();
            // all | international | domestic
            $table->string('applicant_scope', 16)->default('all');
            $table->foreignUlid('knowledge_source_id')->constrained('knowledge_sources')->cascadeOnDelete();
            $table->foreignUlid('knowledge_source_version_id')->constrained('knowledge_source_versions')->cascadeOnDelete();
            $table->text('quote');
            // unverified | verified. Conflicts are worked out per viewer from the sources they can see.
            $table->string('review_status', 16)->default('unverified');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            // Set when a newer source version no longer supports it.
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'owner_user_id', 'requirement_type']);
        });

        Schema::create('research_answers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('college_list_school_id', 26)->nullable()->index();
            $table->text('question');
            $table->longText('answer');
            // [{text, citations: [label], status: supported|unresolved|conflicting, cycle_label}]
            $table->json('claims');
            $table->json('unresolved');
            // label => {kind: source|requirement|document, id, url?, title?, quote, checked_at?}
            $table->json('evidence');
            $table->string('cycle_label', 64)->nullable();
            $table->string('model', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_answers');
        Schema::dropIfExists('admission_requirements');
        Schema::dropIfExists('knowledge_passages');
        Schema::dropIfExists('knowledge_source_versions');
        Schema::dropIfExists('knowledge_sources');
        Schema::dropIfExists('institutions');
    }
};
