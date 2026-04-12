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
        Schema::create('onboarding_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->enum('age_range', ['13_14', '15_16', '17_18', '19plus'])->nullable();
            $table->boolean('is_age_13_or_older_confirmed')->default(false);
            $table->timestamp('legal_accepted_at')->nullable();
            $table->string('legal_accepted_version')->nullable();
            $table->string('current_grade')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->string('curriculum')->nullable();
            $table->decimal('gpa_value', 5, 2)->nullable();
            $table->decimal('gpa_scale', 5, 2)->nullable();
            $table->enum('gpa_type', ['weighted', 'unweighted'])->nullable();
            $table->unsignedSmallInteger('sat_score')->nullable();
            $table->unsignedSmallInteger('act_score')->nullable();
            $table->json('intended_majors_json')->nullable();
            $table->json('target_countries_json')->nullable();
            $table->string('budget_band')->nullable();
            $table->unsignedTinyInteger('profile_completeness_score')->default(0);
            $table->text('student_summary')->nullable();
            $table->json('open_questions_json')->nullable();
            $table->timestamp('last_ai_structured_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_profiles');
    }
};
