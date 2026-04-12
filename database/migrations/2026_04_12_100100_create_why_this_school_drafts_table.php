<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('why_this_school_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('college_list_school_id')
                ->constrained('college_list_schools')
                ->cascadeOnDelete();
            $table->json('payload');
            $table->timestamps();

            $table->index(['user_id', 'college_list_school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('why_this_school_drafts');
    }
};
