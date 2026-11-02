<?php

namespace App\Http\Middleware;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Ai\AiModelResolver;
use App\Domain\Approvals\ProposalStatus;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Support\FeatureQuotaManager;
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
            'isSubscribed' => PlatformAccess::hasActiveSubscriptionOrPass($request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'platform_access' => PlatformAccess::sharePayload($request->user()),
            'profile_completion' => $this->profileCompletionShare($request),
            'college_list_council' => $this->collegeListCouncilShare($request),
            'unread_notification_count' => $this->unreadNotificationCount($request),
            'pending_approval_count' => $this->pendingApprovalCount($request),
            'feature_quotas' => $this->featureQuotasShare($request),
            'mentor' => fn () => $request->user() ? $this->mentorShare() : null,
            // Plain session flashes (->with('status', …)) for pages that show a "saved" notice; Inertia's own
            // flash (page.flash) only carries data flashed through Inertia.
            'flash' => fn () => ['status' => $request->hasSession() ? $request->session()->get('status') : null],
        ];
    }

    /**
     * Which model answers in the mentor panel, shown on the composer so the student knows what they are talking to.
     *
     * @return array{provider: string, model: string|null, label: string}
     */
    protected function mentorShare(): array
    {
        $resolver = app(AiModelResolver::class);
        $provider = $resolver->providerFor(AdmitMeGeneralAgent::class);

        try {
            $model = $resolver->modelFor(AdmitMeGeneralAgent::class);
        } catch (\Throwable) {
            $model = null;
        }

        return ['provider' => $provider, 'model' => $model, 'label' => $model !== null ? self::modelLabel($model) : ucfirst($provider)];
    }

    /**
     * "claude-haiku-5-5" → "Claude Haiku 5.5", "gpt-5-nano" → "GPT-5 nano", "gemini-3.7-flash" → "Gemini 3.7 Flash".
     */
    public static function modelLabel(string $model): string
    {
        $parts = array_values(array_filter(explode('-', $model), fn (string $part) => ! preg_match('/^\d{8}$/', $part)));

        if (($parts[0] ?? '') === 'claude') {
            $words = array_values(array_filter(array_slice($parts, 1), fn (string $part) => ! ctype_digit($part)));
            $digits = array_values(array_filter(array_slice($parts, 1), fn (string $part) => ctype_digit($part)));

            return trim('Claude '.implode(' ', array_map('ucfirst', $words)).' '.implode('.', $digits));
        }

        if (($parts[0] ?? '') === 'gpt') {
            return 'GPT-'.($parts[1] ?? '').(count($parts) > 2 ? ' '.implode(' ', array_slice($parts, 2)) : '');
        }

        if (($parts[0] ?? '') === 'gemini') {
            return 'Gemini '.implode(' ', array_map('ucfirst', array_slice($parts, 1)));
        }

        return $model;
    }

    private function pendingApprovalCount(Request $request): int
    {
        if ($request->user() === null) {
            return 0;
        }

        return Proposal::query()
            ->where('user_id', $request->user()->getKey())
            ->where('status', ProposalStatus::Pending)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function featureQuotasShare(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        return app(FeatureQuotaManager::class)->usageSummaryForInertia($user);
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
