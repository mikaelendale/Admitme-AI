<?php

namespace App\Http\Requests\Settings;

use App\Support\FeatureQuotaManager;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deadline_reminders' => ['required', 'boolean'],
            'weekly_progress' => ['required', 'boolean'],
            'profile_updates' => ['required', 'boolean'],
            'essay_feedback' => ['required', 'boolean'],
            'marketing' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function preferencesPayload(): array
    {
        $user = $this->user();
        $existing = is_array($user->notification_preferences)
            ? $user->notification_preferences
            : [];

        $v = $this->validated();

        $manager = app(FeatureQuotaManager::class);

        $patch = [
            'deadline_reminders' => (bool) $v['deadline_reminders'],
            'weekly_progress' => (bool) $v['weekly_progress'],
            'profile_updates' => (bool) $v['profile_updates'],
            'essay_feedback' => (bool) $v['essay_feedback'],
            'marketing' => (bool) $v['marketing'],
        ];

        if (! $manager->hasBinaryFeature($user, 'deadline_reminders')) {
            $patch['deadline_reminders'] = false;
        }

        if (! $manager->hasBinaryFeature($user, 'weekly_progress_email')) {
            $patch['weekly_progress'] = false;
        }

        return array_merge($existing, $patch);
    }
}
