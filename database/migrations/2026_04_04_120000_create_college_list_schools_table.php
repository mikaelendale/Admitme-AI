<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('college_list_schools', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('school_name');
            $table->string('school_slug');
            $table->enum('tier', ['reach', 'match', 'safety']);
            $table->unsignedTinyInteger('fit_score');
            $table->text('why_blurb');
            $table->decimal('acceptance_rate', 5, 2)->nullable();
            $table->decimal('median_gpa', 3, 2)->nullable();
            $table->unsignedSmallInteger('median_sat')->nullable();
            $table->unsignedTinyInteger('median_act')->nullable();
            $table->date('ea_deadline')->nullable();
            $table->date('ed_deadline')->nullable();
            $table->date('rd_deadline')->nullable();
            $table->enum('aid_estimate_band', ['strong', 'moderate', 'limited'])->nullable();
            $table->json('notable_programs')->nullable();
            $table->enum('campus_size', ['small', 'medium', 'large'])->nullable();
            $table->string('location_city')->nullable();
            $table->string('location_state', 2)->nullable();
            $table->boolean('is_removed')->default(false);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['user_id', 'school_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('college_list_schools');
    }
};
