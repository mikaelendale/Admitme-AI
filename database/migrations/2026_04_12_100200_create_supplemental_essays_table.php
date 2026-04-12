<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplemental_essays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('college_list_school_id')->nullable()
                ->constrained('college_list_schools')
                ->nullOnDelete();
            $table->string('school_name', 255);
            $table->text('prompt_text');
            $table->unsignedSmallInteger('word_limit')->nullable();
            $table->string('essay_type', 32);
            $table->string('status', 32);
            $table->longText('content')->nullable();
            $table->text('notes')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplemental_essays');
    }
};
