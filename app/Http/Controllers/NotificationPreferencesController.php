<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferencesController extends Controller
{
    /**
     * @return array<int, string>
     */
    private static function allowedKeys(): array
    {
        return [
            'email_essay_complete',
            'email_college_list',
            'email_gap_fix',
            'email_deadlines',
            'email_pass_expiry',
            'email_payment_failed',
        ];
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(self::allowedKeys())],
            'value' => ['required', 'boolean'],
        ]);

        $existing = is_array($user->notification_preferences)
            ? $user->notification_preferences
            : [];

        $existing[$validated['key']] = (bool) $validated['value'];

        $user->forceFill([
            'notification_preferences' => $existing,
        ])->save();

        return response()->json(['ok' => true]);
    }
}
