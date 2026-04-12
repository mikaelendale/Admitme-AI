<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public static function defaults(): array
    {
        return [
            'deadline_reminders' => true,
            'weekly_progress' => true,
            'profile_updates' => true,
            'essay_feedback' => true,
            'marketing' => false,
        ];
    }

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];

        return Inertia::render('settings/notifications', [
            'preferences' => array_merge(self::defaults(), $stored),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'notification_preferences' => $request->preferencesPayload(),
        ])->save();

        return to_route('settings.notifications.edit')->with('status', 'notifications-updated');
    }
}
