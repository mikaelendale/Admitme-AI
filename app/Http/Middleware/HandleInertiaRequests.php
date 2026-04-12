<?php

namespace App\Http\Middleware;

use App\Models\OnboardingProfile;
use App\Support\PlatformAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'platform_access' => PlatformAccess::sharePayload($request->user()),
            'profile_completion' => $this->profileCompletionShare($request),
            'college_list_council' => $this->collegeListCouncilShare($request),
            'unread_notification_count' => $this->unreadNotificationCount($request),
        ];
    }

    private function unreadNotificationCount(Request $request): int
    {
        if ($request->user() === null) {
            return 0;
        }

        return (int) $request->user()
            ->unreadNotifications()
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileCompletionShare(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null || ! $user->onboarded) {
            return null;
        }

        $row = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if ($row === null) {
            return null;
        }

        $score = (int) $row->profile_completeness_score;
        $state = is_array($row->profile_completion_state) ? $row->profile_completion_state : [];
        $completed = isset($state['completed_steps']) && is_array($state['completed_steps'])
            ? $state['completed_steps']
            : [];
        $needsAttention = $score < 40 || count($completed) < 5;

        return [
            'completeness_score' => $score,
            'needs_attention' => $needsAttention,
            'completed_steps' => $completed,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function collegeListCouncilShare(Request $request): ?array
    {
        if ($request->user() === null || ! config('college_list_council.enabled')) {
            return null;
        }

        $reverb = config('broadcasting.connections.reverb', []);

        return [
            'enabled' => true,
            'reverb' => [
                'key' => is_array($reverb) ? ($reverb['key'] ?? '') : '',
                'wsHost' => env('REVERB_HOST', 'localhost'),
                'wsPort' => (int) env('REVERB_PORT', 8080),
                'wssPort' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
            ],
        ];
    }
}
