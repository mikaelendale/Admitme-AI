<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ApplyPlatformPassFromCheckoutSession;
use App\Actions\Billing\FinalizeOnboardingCheckoutSession;
use App\Domain\Documents\DocumentService;
use App\Domain\Documents\DocumentTextExtractor;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\GuidedOnboarding;
use App\Domain\Students\GuidedProfileSetup;
use App\Http\Requests\Onboarding\CompleteOnboardingRequest;
use App\Http\Requests\Onboarding\StartOnboardingCheckoutRequest;
use App\Jobs\BuildUserAiContextJob;
use App\Models\Document;
use App\Models\OnboardingProfile;
use App\Models\OnboardingResponse;
use App\Models\OnboardingSession;
use App\Models\StudentFact;
use App\Models\User;
use App\Support\PlatformAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * First-run onboarding, in the order of the build plan's Phase 4: consent, then situation & intake,
 * curriculum & grades, interests, destinations, budget/currency & aid, optional documents, and a review
 * that asks only the questions still blocking the next tasks. Every answer is saved as the student goes
 * (resumable after a reload) through the same profile service as every other edit, and "don't know" is
 * always a valid answer. A plan step appears only when billing enforcement applies to this student.
 */
class OnboardingController extends Controller
{
    private const MAX_UPLOAD_KB = 10240;

    /** @var array<string, string> */
    public const AGE_RANGES = [
        '13_14' => '13–14',
        '15_16' => '15–16',
        '17_18' => '17–18',
        '19plus' => '19 or older',
    ];

    public function show(Request $request, DocumentTextExtractor $extractor): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $profile = OnboardingProfile::firstOrCreate(['user_id' => $user->id], ['status' => 'draft']);
        $session = $this->resolveInProgressSession($user->id);
        $this->maybeVerifyCheckoutReturn($request, $user, $session);

        $consented = $this->consented($profile);
        $state = GuidedOnboarding::state($profile);
        $billing = $this->billingState($user, $session, $request);

        $steps = [[
            'key' => 'consent',
            'title' => 'Before we start',
            'intro' => 'AdmitMe is for students aged 13 and over. Confirm your age range and the terms to begin.',
            'completed' => $consented,
            'fields' => [],
        ]];
        foreach (GuidedProfileSetup::sections($profile) as $section) {
            $steps[] = $section;
        }
        if ($billing['needed']) {
            $steps[] = [
                'key' => 'plan',
                'title' => 'Your plan',
                'intro' => 'Choose how you want to continue. You can also decide later from Settings → Billing.',
                'completed' => $billing['checkout_verified'] || $billing['has_access'],
                'fields' => [],
            ];
        }

        $keys = array_column($steps, 'key');
        $requested = $request->query('step');
        $current = match (true) {
            ! $consented => 'consent',
            is_string($requested) && $requested !== 'consent' && in_array($requested, $keys, true) => $requested,
            default => $state['current'],
        };

        $missing = GuidedProfileSetup::missingQuestions($profile);

        return Inertia::render('onboarding', [
            'steps' => $steps,
            'current' => $current,
            'consent' => [
                'done' => $consented,
                'ageRange' => $profile->age_range,
                'ageRanges' => self::AGE_RANGES,
            ],
            'revision' => (int) ($profile->revision ?? 0),
            'readiness' => GuidedOnboarding::readiness($profile),
            'missing' => $missing,
            'missingFields' => GuidedProfileSetup::fields($profile, array_values(array_unique(array_merge([], ...array_column($missing, 'keys'))))),
            'summary' => GuidedProfileSetup::summary($profile),
            'documents' => $this->documents($user),
            'kinds' => Document::KINDS,
            'maxUploadMb' => intdiv(self::MAX_UPLOAD_KB, 1024),
            'ocrAvailable' => $extractor->ocrAvailable(),
            'billing' => $billing,
        ]);
    }

    /**
     * Age range and acceptance of the terms; required before anything else is saved.
     */
    public function consent(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'age_range' => ['required', Rule::in(array_keys(self::AGE_RANGES))],
            'legal_accepted' => ['required', 'accepted'],
        ]);

        $profile = OnboardingProfile::firstOrCreate(['user_id' => $user->id], ['status' => 'draft']);
        $profile->update([
            'age_range' => $validated['age_range'],
            'is_age_13_or_older_confirmed' => true,
            'legal_accepted_at' => now(),
            'legal_accepted_version' => config('legal.documents_version'),
        ]);

        $session = $this->resolveInProgressSession($user->id);
        $session->update([
            'started_at' => $session->started_at ?? now(),
            'started_step_key' => $session->started_step_key ?? 'consent',
            'last_step_key' => 'situation',
        ]);

        return redirect()->route('onboarding');
    }

    /**
     * Save one guided section and move to the next.
     */
    public function saveSection(Request $request, string $section, GuidedProfileSetup $setup): RedirectResponse
    {
        abort_unless(isset(GuidedOnboarding::SECTIONS[$section]), 404);
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }
        if (! $this->consented(OnboardingProfile::query()->where('user_id', $user->id)->first())) {
            return redirect()->route('onboarding');
        }

        $keys = GuidedOnboarding::SECTIONS[$section]['keys'];
        GuidedProfileSetup::normaliseLists($request, $keys);
        $validated = $request->validate(GuidedProfileSetup::rules($keys));

        $setup->save($user, $keys, $request, $validated, "onboarding.{$section}", $section);

        $next = GuidedOnboarding::state(OnboardingProfile::query()->where('user_id', $user->id)->first())['current'];
        $this->resolveInProgressSession($user->id)->update(['last_step_key' => $next]);

        return redirect()->route('onboarding', ['step' => $next]);
    }

    /**
     * Answers to the targeted questions on the review step.
     */
    public function saveAnswers(Request $request, GuidedProfileSetup $setup): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $keys = array_values(array_intersect(GuidedProfileSetup::allKeys(), (array) $request->input('keys', [])));
        abort_if($keys === [], 422, 'No answers to save.');

        GuidedProfileSetup::normaliseLists($request, $keys);
        $validated = $request->validate(GuidedProfileSetup::rules($keys));

        $setup->save($user, $keys, $request, $validated, 'onboarding.review');

        return redirect()->route('onboarding', ['step' => 'review']);
    }

    /**
     * Upload a transcript or test report during onboarding. It goes through the normal document pipeline
     * (private storage, versioning, reading in the background); values read from it wait for the student's
     * review on the Documents page and are never saved to the profile automatically.
     */
    public function uploadDocument(Request $request, DocumentService $documents): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'file' => ['required', File::types(['pdf', 'docx', 'txt', 'png', 'jpg', 'jpeg'])->max(self::MAX_UPLOAD_KB)],
            'kind' => ['required', Rule::in(array_keys(Document::KINDS))],
        ]);

        $documents->upload($user, $request->file('file'), $validated['kind'], null, null, ChangeSource::studentManual($user, 'onboarding.documents'));

        return redirect()->route('onboarding', ['step' => 'documents']);
    }

    public function skipBilling(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded) {
            return redirect()->route('dashboard');
        }

        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if (! $this->consented($profile)) {
            return back()->withErrors(['onboarding' => 'Confirm your age range and the terms first.']);
        }

        $session = $this->resolveInProgressSession($user->id);

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

        $this->finish($user, $profile, $session);

        return redirect()->route('dashboard');
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

        $successUrl = route('onboarding').'?step=plan&checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('onboarding').'?step=plan&checkout=cancel';

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

        $profile = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if (! $this->consented($profile)) {
            return back()->withErrors(['onboarding' => 'Confirm your age range and the terms first.']);
        }

        $this->finish($user, $profile, $this->resolveInProgressSession($user->id));

        return redirect()->route('dashboard');
    }

    private function finish(User $user, ?OnboardingProfile $profile, OnboardingSession $session): void
    {
        $profile?->update(['status' => 'completed']);

        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
            'last_step_key' => 'review',
        ]);

        $user->forceFill(['onboarded' => true])->save();

        BuildUserAiContextJob::dispatch($user->id, false);
    }

    private function consented(?OnboardingProfile $profile): bool
    {
        return (bool) ($profile?->is_age_13_or_older_confirmed) && $profile?->legal_accepted_at !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(User $user): array
    {
        $pending = StudentFact::query()
            ->where('user_id', $user->getKey())
            ->where('review_state', StudentFact::REVIEW_PENDING)
            ->get(['source_ref'])
            ->countBy(fn (StudentFact $fact) => $fact->source_ref['document_id'] ?? '');

        return Document::query()
            ->where('user_id', $user->getKey())
            ->with('currentVersion')
            ->latest('updated_at')
            ->get()
            ->map(fn (Document $document) => [
                'id' => (string) $document->id,
                'title' => $document->title,
                'name' => $document->currentVersion?->original_name ?? $document->title,
                'kindLabel' => Document::KINDS[$document->kind] ?? $document->kind,
                'status' => $document->currentVersion?->status->value,
                'statusLabel' => $document->currentVersion?->status->label(),
                'failureReason' => $document->currentVersion?->failure_reason,
                'pendingValues' => (int) ($pending[$document->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the plan step applies, and what it can offer (only plans with a configured Stripe price).
     *
     * @return array<string, mixed>
     */
    private function billingState(User $user, OnboardingSession $session, Request $request): array
    {
        $enforced = (bool) config('billing.enforcement.enabled', true);
        $hasAccess = PlatformAccess::hasFullAccess($user);
        $verified = OnboardingResponse::query()
            ->where('onboarding_session_id', $session->id)
            ->where('question_key', 'billing.checkout_verified')
            ->exists();
        $trialOnly = (bool) config('billing.trial_only_mode', false);
        $prices = (array) config('billing.prices', []);
        $catalog = (array) config('billing.plan_catalog', []);
        $passes = (array) config('billing.pass_display', []);
        $configured = fn (string $key) => is_string($prices[$key] ?? null) && $prices[$key] !== '';

        $plans = [[
            'plan' => 'trial',
            'cycle' => 'monthly',
            'headline' => '$1 trial',
            'priceLine' => ((int) config('billing.purchase_access_days.trial', 3)).' days of full access',
            'badge' => 'Start here',
            'configured' => $configured('trial'),
        ]];
        if (! $trialOnly) {
            $plans[] = ['plan' => 'sprint', 'cycle' => 'monthly', 'headline' => (string) ($passes['sprint']['headline'] ?? 'Admissions sprint'), 'priceLine' => (string) ($passes['sprint']['price_line'] ?? '90-day access'), 'badge' => null, 'configured' => $configured('sprint')];
            $plans[] = ['plan' => 'pro', 'cycle' => 'monthly', 'headline' => 'Pro · monthly', 'priceLine' => (string) ($catalog['pro_monthly']['price_line'] ?? ''), 'badge' => null, 'configured' => $configured('pro_monthly')];
            $plans[] = ['plan' => 'pro', 'cycle' => 'annual', 'headline' => 'Pro · yearly', 'priceLine' => (string) ($catalog['pro_annual']['price_line'] ?? ''), 'badge' => $catalog['pro_annual']['badge'] ?? null, 'configured' => $configured('pro_annual')];
        }

        return [
            'needed' => $enforced && (! $hasAccess || $verified),
            'has_access' => $hasAccess,
            'checkout_verified' => $verified,
            'cancelled' => $request->query('checkout') === 'cancel',
            'trial_only' => $trialOnly,
            'plans' => $plans,
        ];
    }

    private function resolveInProgressSession(int $userId): OnboardingSession
    {
        $session = OnboardingSession::query()
            ->where('user_id', $userId)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        return $session ?? OnboardingSession::create([
            'user_id' => $userId,
            'session_uuid' => (string) Str::uuid(),
            'status' => 'in_progress',
        ]);
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
