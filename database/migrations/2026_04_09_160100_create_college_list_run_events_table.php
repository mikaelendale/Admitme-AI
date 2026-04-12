<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('college_list_run_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('college_list_run_id')->constrained('college_list_runs')->cascadeOnDelete();
            $table->string('phase', 32);
            $table->string('severity', 24);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('school_slug', 160)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('college_list_run_events');
    }
};
