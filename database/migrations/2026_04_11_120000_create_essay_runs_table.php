<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('essay_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('college_list_school_id')
                ->constrained('college_list_schools')
                ->cascadeOnDelete();
            $table->string('run_type', 32);
            $table->string('status', 32);
            $table->text('user_prompt');
            $table->longText('output_markdown')->nullable();
            $table->string('title_for_card', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'run_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('essay_runs');
    }
};
