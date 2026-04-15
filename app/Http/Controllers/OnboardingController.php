<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApplyPlatformPassFromCheckoutSession;
use App\Actions\Billing\FinalizeOnboardingCheckoutSession;
use App\Ai\Agents\NoCapAgent;
use App\Http\Requests\Onboarding\CompleteOnboardingRequest;
use App\Http\Requests\Onboarding\SaveStepRequest;
use App\Http\Requests\Onboarding\StartOnboardingCheckoutRequest;
use App\Jobs\BuildUserAiContextJob;
use App\Models\OnboardingProfile;
use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $profile = OnboardingProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'draft']
        );

        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if (! $session) {
            $session = OnboardingSession::create([
                'user_id' => $user->id,
                'session_uuid' => (string) Str::uuid(),
                'status' => 'in_progress',
            ]);
        }

        $this->maybeVerifyCheckoutReturn($request, $user, $session);

        $responses = OnboardingResponse::query()
            ->where('onboarding_session_id', $session->id)
            ->get()
            ->keyBy('question_key');

        $needsAgeGate = ! $profile->is_age_13_or_older_confirmed || ! $profile->legal_accepted_at;

        $checkoutVerified = OnboardingResponse::query()
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'billing.checkout_verified')
            ->exists();

        $lastStepKey = (int) ($session->last_step_key ?? '1');

        $verifiedRow = $responses->get('billing.checkout_verified');
        $intentRow = $responses->get('billing.checkout_intent');
        $plan = data_get($verifiedRow?->answer_json, 'plan') ?? data_get($intentRow?->answer_json, 'plan');
        $billingCycle = data_get($verifiedRow?->answer_json, 'billing_cycle')
            ?? data_get($intentRow?->answer_json, 'billing_cycle')
            ?? 'monthly';
        $planStr = is_string($plan) ? $plan : null;
        $cycleStr = is_string($billingCycle) ? $billingCycle : 'monthly';
        $billingSkipped = $session->status === 'completed'
            && ! $checkoutVerified
            && ! $user->subscribed('default');

        return Inertia::render('onboarding', [
            'sessionUuid' => $session->session_uuid,
            'sessionId' => $session->id,
            'initialStep' => max(1, min(5, $lastStepKey)),
            'needsAgeGate' => $needsAgeGate,
            'checkoutVerified' => $checkoutVerified,
            'billingSkipped' => $billingSkipped,
            'checkoutCancelled' => $request->query('checkout') === 'cancel',
            'selectedBilling' => [
                'plan' => $planStr,
                'billing_cycle' => $cycleStr,
                'label' => $this->billingPlanLabel($planStr, $cycleStr),
            ],
            'profile' => [
                'age_range' => $profile->age_range,
                'is_age_13_or_older_confirmed' => $profile->is_age_13_or_older_confirmed,
            ],
            'responses' => $responses->map(fn (OnboardingResponse $r) => [
                'answer_text' => $r->answer_text,
                'answer_json' => $r->answer_json,
            ]),
            'hasActiveSubscription' => $user->subscribed('default'),
            'maxProfileWords' => config('admissions.max_profile_words'),
            'trial_only_mode' => (bool) config('billing.trial_only_mode', false),
        ]);
    }

    public function refreshForOnboarding(Request $request): RedirectResponse
    {
        return redirect()->route('onboarding');
    }

    /**
     * Run the "No Cap" admissions read with structured JSON (OpenAI gpt-5-nano).
     *
     * Laravel AI does not support streaming structured output; this returns one JSON payload for charts + chips + markdown.
     */
    public function admissionsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            abort(403);
        }

        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if (! $session) {
            abort(404, 'No active onboarding session.');
        }

        $profileRow = OnboardingResponse::query()
            ->where('user_id', $user->id)
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'profile.freeform_text')
            ->first();

        $paste = $profileRow?->answer_text;
        if ($paste === null || trim($paste) === '') {
            abort(404, 'Add your profile on the previous step first.');
        }

        $sourceProfileHash = sha1(trim($paste));

        $existingReport = OnboardingResponse::query()
            ->where('user_id', $user->id)
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'report.no_cap_markdown')
            ->first();

        $cachedStructured = data_get($existingReport?->answer_json, 'structured');
        $cachedProfileHash = data_get($existingReport?->answer_json, 'source_profile_hash');

        if (is_array($cachedStructured) && is_string($cachedProfileHash) && hash_equals($cachedProfileHash, $sourceProfileHash)) {
            return response()->json(['report' => $cachedStructured]);
        }

        $prompt = "Student profile paste:\n\n---\n{$paste}\n---\n\nProduce the structured admissions read now.";

        try {
            $aiResponse = NoCapAgent::make()->prompt($prompt);

            if (! $aiResponse instanceof StructuredAgentResponse) {
                report(new \RuntimeException('NoCapAgent returned non-structured response.'));

                return response()->json([
                    'message' => 'Admissions read could not be generated.',
                ], 500);
            }

            $structured = $aiResponse->structured;

            OnboardingResponse::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'onboarding_session_id' => $session->id,
                    'question_key' => 'report.no_cap_markdown',
                ],
                [
                    'step_key' => 'report',
                    'source' => 'chat',
                    'answer_text' => $structured['headline'] ?? '',
                    'answer_json' => [
                        'agent' => 'no_cap',
                        'model' => 'gpt-5-nano',
                        'source_profile_hash' => $sourceProfileHash,
                        'structured' => $structured,
                    ],
                ]
            );

            return response()->json(['report' => $structured]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Admissions read could not be started. Check your AI provider configuration.',
            ], 503);
        }
    }

    public function saveStep(SaveStepRequest $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validated();
        $step = (int) $validated['step'];

        $profile = OnboardingProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'draft']
        );

        $session = $this->resolveInProgressSession($user->id);

        DB::transaction(function () use ($user, $session, $profile, $validated, $step): void {
            if ($session->started_at === null) {
                $session->update(['started_at' => now()]);
            }

            match ($step) {
                0 => $this->persistStep0($profile, $session, $validated),
                1 => $session->update(['last_step_key' => '2']),
                2 => $this->persistStep2($user, $session, $validated),
                3 => $this->persistStep3($user, $session, $validated),
                4 => $this->persistStep4($user, $session),
                default => null,
            };
        });

        return redirect()->route('onboarding');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistStep0(OnboardingProfile $profile, OnboardingSession $session, array $validated): void
    {
        $profile->update([
            'age_range' => $validated['age_range'],
            'is_age_13_or_older_confirmed' => true,
            'legal_accepted_at' => now(),
            'legal_accepted_version' => config('legal.documents_version'),
        ]);
        $session->update(['last_step_key' => '1', 'started_step_key' => '1']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistStep2(User $user, OnboardingSession $session, array $validated): void
    {
        OnboardingResponse::updateOrCreate(
            [
                'user_id' => $user->id,
                'onboarding_session_id' => $session->id,
                'question_key' => 'profile.freeform_text',
            ],
            [
                'step_key' => 'profile',
                'source' => 'freeform_paste',
                'answer_text' => $validated['profile_text'],
                'answer_json' => null,
            ]
        );
        $session->update(['last_step_key' => '3']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistStep3(User $user, OnboardingSession $session, array $validated): void
    {
        if (array_key_exists('report_acknowledged', $validated)) {
            OnboardingResponse::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'onboarding_session_id' => $session->id,
                    'question_key' => 'report.acknowledged',
                ],
                [
                    'step_key' => 'report',
                    'source' => 'form',
                    'answer_text' => $validated['report_acknowledged'] ? '1' : '0',
                    'answer_json' => ['acknowledged' => (bool) $validated['report_acknowledged']],
                ]
            );
        }
        $session->update(['last_step_key' => '4']);
    }

    private function persistStep4(User $user, OnboardingSession $session): void
    {
        $billingOk = $user->subscribed('default')
            || OnboardingResponse::query()
                ->where('onboarding_session_id', $session->id)
                ->where('question_key', 'billing.checkout_verified')
                ->exists();

        $session->update(['last_step_key' => '5']);
    }

    public function skipBilling(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $session = $this->resolveInProgressSession($user->id);

        $hasProfileText = OnboardingResponse::query()
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'profile.freeform_text')
            ->whereNotNull('answer_text')
            ->where('answer_text', '!=', '')
            ->exists();

        if (! $hasProfileText) {
            return back()->withErrors([
                'onboarding' => 'Please complete your profile text before skipping billing.',
            ]);
        }

        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if ($profile) {
            $profile->update(['status' => 'completed']);
        }

        OnboardingResponse::updateOrCreate(
            [
                'user_id' => $user->id,
                'onboarding_session_id' => $session->id,
                'question_key' => 'billing.skipped',
            ],
            [
                'step_key' => 'plans',
                'source' => 'form',
                'answer_text' => '1',
                'answer_json' => ['skipped' => true],
            ],
        );

        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
            'last_step_key' => '5',
        ]);

        $user->forceFill(['onboarded' => true])->save();

        BuildUserAiContextJob::dispatch($user->id, false);

        return redirect()->route('dashboard');
    }

    private function billingPlanLabel(?string $plan, string $billingCycle): string
    {
        return match ($plan) {
            'trial' => '$1 Trial (3 days)',
            'sprint' => 'Admissions Sprint',
            'boost' => 'Boost Pack',
            'pro' => $billingCycle === 'annual' ? 'Pro (annual)' : 'Pro (monthly)',
            default => 'Selected plan',
        };
    }

    /**
     * Stripe Checkout is external; use {@see Inertia::location()} so Inertia performs a full page visit.
     */
    public function checkout(StartOnboardingCheckoutRequest $request): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validated();
        $plan = $validated['plan'];
        $billingCycle = $validated['billing_cycle'] ?? 'monthly';

        $session = $this->resolveInProgressSession($user->id);

        $priceId = $this->resolveStripePriceId($plan, $billingCycle);
        if ($priceId === null || $priceId === '') {
            return back()->withErrors([
                'plan' => 'This plan is not configured yet. Add the matching STRIPE_PRICE_* key in .env.',
            ]);
        }

        OnboardingResponse::updateOrCreate(
            [
                'user_id' => $user->id,
                'onboarding_session_id' => $session->id,
                'question_key' => 'billing.checkout_intent',
            ],
            [
                'step_key' => 'plans',
                'source' => 'form',
                'answer_text' => $plan,
                'answer_json' => [
                    'plan' => $plan,
                    'billing_cycle' => $billingCycle,
                ],
            ]
        );

        $successUrl = route('onboarding').'?checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('onboarding').'?checkout=cancel';

        $checkoutOptions = [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'onboarding_session_id' => (string) $session->id,
                'plan' => $plan,
                'billing_cycle' => $billingCycle,
            ],
        ];

        $checkout = $plan === 'pro'
            ? $user->newSubscription('default', $priceId)->checkout($checkoutOptions)
            : $user->checkout([$priceId => 1], $checkoutOptions);

        return Inertia::location($checkout->url);
    }

    public function complete(CompleteOnboardingRequest $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $session = $this->resolveInProgressSession($user->id);

        $hasProfileText = OnboardingResponse::query()
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'profile.freeform_text')
            ->whereNotNull('answer_text')
            ->where('answer_text', '!=', '')
            ->exists();

        if (! $hasProfileText) {
            return back()->withErrors([
                'onboarding' => 'Please complete your profile text before finishing.',
            ]);
        }

        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if ($profile) {
            $profile->update(['status' => 'completed']);
        }

        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
            'last_step_key' => '5',
        ]);

        $user->forceFill(['onboarded' => true])->save();

        BuildUserAiContextJob::dispatch($user->id, false);

        return redirect()->route('dashboard');
    }

    private function resolveInProgressSession(int $userId): OnboardingSession
    {
        $session = OnboardingSession::query()
            ->where('user_id', $userId)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        if (! $session) {
            return OnboardingSession::create([
                'user_id' => $userId,
                'session_uuid' => (string) Str::uuid(),
                'status' => 'in_progress',
            ]);
        }

        return $session;
    }

    private function resolveStripePriceId(string $plan, string $billingCycle): ?string
    {
        $prices = config('billing.prices', []);

        return match ($plan) {
            'trial' => $prices['trial'] ?? null,
            'sprint' => $prices['sprint'] ?? null,
            'boost' => $prices['boost'] ?? null,
            'pro' => $billingCycle === 'annual'
                ? ($prices['pro_annual'] ?? null)
                : ($prices['pro_monthly'] ?? null),
            default => null,
        };
    }

    private function maybeVerifyCheckoutReturn(Request $request, User $user, OnboardingSession $session): void
    {
        if ($request->query('checkout') !== 'success' || ! $sessionId = $request->query('session_id')) {
            return;
        }

        try {
            $stripeSession = Cashier::stripe()->checkout->sessions->retrieve(
                is_string($sessionId) ? $sessionId : (string) $sessionId
            );
        } catch (ApiErrorException) {
            return;
        }

        $data = $stripeSession->toArray();

        app(FinalizeOnboardingCheckoutSession::class)($user, $session, $data);
        app(ApplyPlatformPassFromCheckoutSession::class)($user, $data);
    }
}
