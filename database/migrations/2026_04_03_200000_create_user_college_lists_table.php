<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_college_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('school_name');
            $table->enum('list_type', ['reach', 'target', 'safety']);
            $table->unsignedTinyInteger('fit_score')->nullable();
            $table->text('ai_reasoning')->nullable();
            $table->enum('status', [
                'not_started',
                'researching',
                'applying',
                'submitted',
                'accepted',
                'rejected',
                'waitlisted',
            ])->default('not_started');
            $table->date('deadline')->nullable();
            $table->text('notes')->nullable();
            $table->json('ai_metadata')->nullable();
            $table->timestamp('added_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['user_id', 'school_name']);
            $table->index(['user_id', 'list_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_college_lists');
    }
};
