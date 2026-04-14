<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'unread', 'read'], true)) {
            $filter = 'all';
        }

        $user = $request->user();
        $query = $user->notifications()->orderByDesc('created_at');

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->limit(100)->get()->map(fn (DatabaseNotification $n) => [
            'id' => $n->id,
            'title' => $this->notificationTitle($n),
            'body' => $this->notificationBody($n),
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
            'action_url' => $this->notificationActionUrl($n),
        ]);

        $defaults = [
            'email_essay_complete' => true,
            'email_college_list' => true,
            'email_gap_fix' => false,
            'email_deadlines' => true,
            'email_pass_expiry' => true,
            'email_payment_failed' => true,
        ];
        $stored = is_array($user->notification_preferences) ? $user->notification_preferences : [];

        return Inertia::render('notifications', [
            'filter' => $filter,
            'notifications' => $notifications,
            'unread_count' => (int) $user->unreadNotifications()->count(),
            'notification_preferences' => array_merge($defaults, $stored),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->ensureOwnedByUser($request, $notification);

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    private function ensureOwnedByUser(Request $request, DatabaseNotification $notification): void
    {
        if ((int) $notification->notifiable_id !== (int) $request->user()->id
            || $notification->notifiable_type !== $request->user()->getMorphClass()) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationData(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return is_array($data) ? $data : [];
    }

    private function notificationTitle(DatabaseNotification $notification): string
    {
        $data = $this->notificationData($notification);
        foreach (['title', 'subject', 'heading'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        $type = class_basename($notification->type);

        return preg_replace('/([a-z])([A-Z])/', '$1 $2', $type) ?: 'Notification';
    }

    private function notificationBody(DatabaseNotification $notification): ?string
    {
        $data = $this->notificationData($notification);
        foreach (['body', 'message', 'text', 'content'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    private function notificationActionUrl(DatabaseNotification $notification): ?string
    {
        $data = $this->notificationData($notification);
        if (isset($data['action_url']) && is_string($data['action_url']) && $data['action_url'] !== '') {
            return $data['action_url'];
        }

        return null;
    }
}
