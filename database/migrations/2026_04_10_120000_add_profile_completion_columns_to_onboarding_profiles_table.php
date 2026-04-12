<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table): void {
            $table->longText('profile_dump_text')->nullable()->after('student_summary');
            $table->json('extraction_draft_json')->nullable()->after('profile_dump_text');
            $table->string('extraction_source_hash', 64)->nullable()->after('extraction_draft_json');
            $table->timestamp('last_profile_extraction_at')->nullable()->after('extraction_source_hash');
            $table->json('profile_modes_json')->nullable()->after('last_profile_extraction_at');
            $table->json('profile_completion_state')->nullable()->after('profile_modes_json');
            $table->json('completeness_callouts_json')->nullable()->after('profile_completion_state');
            $table->decimal('gpa_normalized_4', 4, 2)->nullable()->after('gpa_type');
            $table->string('test_optional_status', 32)->nullable()->after('act_score');
            $table->string('citizenship_residency', 64)->nullable()->after('test_optional_status');
            $table->string('location_country', 120)->nullable()->after('citizenship_residency');
            $table->string('location_region', 120)->nullable()->after('location_country');
            $table->json('dream_schools_json')->nullable()->after('location_region');
            $table->json('region_preferences_json')->nullable()->after('dream_schools_json');
            $table->string('campus_size_pref', 32)->nullable()->after('region_preferences_json');
            $table->unsignedTinyInteger('financial_aid_need_percent')->nullable()->after('campus_size_pref');
            $table->json('activities_extracted_json')->nullable()->after('financial_aid_need_percent');
            $table->json('awards_extracted_json')->nullable()->after('activities_extracted_json');
            $table->string('grade_trend', 16)->nullable()->after('awards_extracted_json');
            $table->text('spike_summary')->nullable()->after('grade_trend');
            $table->boolean('resume_pdf_unreadable')->default(false)->after('spike_summary');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_dump_text',
                'extraction_draft_json',
                'extraction_source_hash',
                'last_profile_extraction_at',
                'profile_modes_json',
                'profile_completion_state',
                'completeness_callouts_json',
                'gpa_normalized_4',
                'test_optional_status',
                'citizenship_residency',
                'location_country',
                'location_region',
                'dream_schools_json',
                'region_preferences_json',
                'campus_size_pref',
                'financial_aid_need_percent',
                'activities_extracted_json',
                'awards_extracted_json',
                'grade_trend',
                'spike_summary',
                'resume_pdf_unreadable',
            ]);
        });
    }
};
