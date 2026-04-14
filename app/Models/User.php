<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'provider', 'provider_id', 'avatar', 'onboarded', 'notification_preferences', 'ai_preferences', 'platform_pass_plan', 'platform_pass_ends_at', 'notified_expiry_at', 'payment_failed_notified_at', 'platform_pass_applied_checkout_session_ids', 'profile_pdf_resume_parse_count'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable #implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'onboarded' => 'boolean',
            'notification_preferences' => 'array',
            'ai_preferences' => 'array',
            'platform_pass_ends_at' => 'datetime',
            'notified_expiry_at' => 'datetime',
            'payment_failed_notified_at' => 'datetime',
            'platform_pass_applied_checkout_session_ids' => 'array',
            'profile_pdf_resume_parse_count' => 'integer',
        ];
    }

    public function onboardingProfile(): HasOne
    {
        return $this->hasOne(OnboardingProfile::class);
    }

    public function onboardingSessions(): HasMany
    {
        return $this->hasMany(OnboardingSession::class);
    }

    public function onboardingResponses(): HasMany
    {
        return $this->hasMany(OnboardingResponse::class);
    }

    public function collegeListRuns(): HasMany
    {
        return $this->hasMany(CollegeListRun::class);
    }

    public function collegeListSchools(): HasMany
    {
        return $this->hasMany(CollegeListSchool::class);
    }

    public function essayRuns(): HasMany
    {
        return $this->hasMany(EssayRun::class);
    }

    public function gapFixAnalyses(): HasMany
    {
        return $this->hasMany(GapFixAnalysis::class);
    }

    public function essayRoasts(): HasMany
    {
        return $this->hasMany(EssayRoast::class);
    }

    public function whyThisSchoolDrafts(): HasMany
    {
        return $this->hasMany(WhyThisSchoolDraft::class);
    }

    public function supplementalEssays(): HasMany
    {
        return $this->hasMany(SupplementalEssay::class);
    }

    public function jobTrackings(): HasMany
    {
        return $this->hasMany(JobTracking::class);
    }

    public function featureUsages(): HasMany
    {
        return $this->hasMany(FeatureUsage::class);
    }

    public function notificationPreference(string $key): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return (bool) ($prefs[$key] ?? $this->defaultPreferences()[$key] ?? false);
    }

    /**
     * @return array<string, bool>
     */
    private function defaultPreferences(): array
    {
        return [
            'email_essay_complete' => true,
            'email_college_list' => true,
            'email_gap_fix' => false,
            'email_deadlines' => true,
            'email_pass_expiry' => true,
            'email_payment_failed' => true,
        ];
    }
}
