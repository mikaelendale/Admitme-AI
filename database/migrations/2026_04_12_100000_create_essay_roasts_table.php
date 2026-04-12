<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('essay_roasts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('essay_run_id')->nullable()->constrained('essay_runs')->nullOnDelete();
            $table->string('essay_text_hash', 64);
            $table->json('payload');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('essay_text_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('essay_roasts');
    }
};
