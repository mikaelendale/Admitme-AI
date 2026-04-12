<?php

use App\Models\User;
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
        Schema::create('onboarding_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignId('onboarding_session_id')
                ->constrained('onboarding_sessions')
                ->cascadeOnDelete();
            $table->string('step_key');
            $table->string('question_key');
            $table->enum('source', ['form', 'freeform_paste', 'chat'])->default('form');
            $table->text('answer_text')->nullable();
            $table->json('answer_json')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'onboarding_session_id', 'question_key'], 'uniq_onboarding_answer_per_session');
            $table->index(['user_id', 'step_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_responses');
    }
};
