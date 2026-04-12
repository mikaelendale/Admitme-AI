<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('college_list_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->index();
            $table->string('intent', 24);
            $table->text('request_message');
            $table->text('error_message')->nullable();
            $table->json('preflight_payload')->nullable();
            $table->json('scout_payload')->nullable();
            $table->json('judge_payload')->nullable();
            $table->text('summary_text')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('college_list_runs');
    }
};
