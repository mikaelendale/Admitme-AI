<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Billing\ApplyPlatformPassFromCheckoutSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StartBillingCheckoutRequest;
use App\Http\Requests\Settings\StartBillingSubscriptionCheckoutRequest;
use App\Http\Requests\Settings\SwapSubscriptionRequest;
use App\Models\User;
use App\Support\BillingDisplay;
use App\Support\BillingPlanCatalog;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\SessionKey;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use Laravel\Cashier\Exceptions\SubscriptionUpdateFailure;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $this->maybeVerifyBillingCheckoutReturn($request, $user);

        $hasStripeCustomer = $user->hasStripeId();

        $subscription = $user->subscription('default');
        $subscribed = $subscription && $subscription->valid();

        $planLine = 'No active subscription';
        $nextBilling = null;
        $paymentMethodSummary = null;
        $currentPriceId = null;
        $recurringInterval = null;
        $canSwapPlan = false;
        $swapBlockedReason = null;

        if ($subscribed && $subscription !== null) {
            $stripeSub = $subscription->asStripeSubscription();
            $currentPriceId = self::effectiveSubscriptionPriceId($subscription);
            $recurringInterval = self::stripeFirstItemInterval($stripeSub);
            $isAnnual = isset(config('billing.prices')['pro_annual'])
                && config('billing.prices.pro_annual') === $currentPriceId;

            $planLine = BillingDisplay::shortPlanLine($currentPriceId, $isAnnual);

            if ($stripeSub->current_period_end) {
                $nextBilling = Carbon::createFromTimestamp(
                    $stripeSub->current_period_end,
                )->toFormattedDateString();
            }

            try {
                $pm = $user->defaultPaymentMethod();
                if ($pm && $pm->card) {
                    $brand = $pm->card->brand ?? 'Card';
                    $last4 = $pm->card->last4 ?? '****';
                    $paymentMethodSummary = ucfirst((string) $brand).' •••• '.$last4;
                }
            } catch (\Throwable) {
                $paymentMethodSummary = null;
            }

            $swappableConfigured = BillingPlanCatalog::allowedSwapPriceIds() !== [];
            $singleBillableItem = $subscription->hasSinglePrice()
                || $subscription->items->count() === 1;

            if (! $singleBillableItem) {
                $swapBlockedReason = 'This subscription uses multiple prices. Use the billing portal to make changes.';
            } elseif (! $swappableConfigured) {
                $swapBlockedReason = 'Plan changes are not configured yet (add STRIPE_PRICE_* in .env).';
            } else {
                $canSwapPlan = true;
            }
        } else {
            $passEnds = $user->platform_pass_ends_at;
            if ($passEnds !== null && Carbon::parse($passEnds)->isFuture()) {
                $until = Carbon::parse($passEnds)->toFormattedDateString();
                $planLine = match ($user->platform_pass_plan) {
                    'trial' => '$1 trial — full access until '.$until,
                    'sprint' => '90-day Admissions Sprint ($28) — full access until '.$until,
                    default => 'Time-limited access until '.$until,
                };
            } elseif ($passEnds !== null) {
                $planLine = 'Access expired — subscribe to Pro or buy a 90-day pass below';
            }
        }

        $plans = BillingPlanCatalog::plansForSettings($currentPriceId, $recurringInterval);

        $hasActiveSprintPass = ! $subscribed
            && $user->platform_pass_plan === 'sprint'
            && $user->platform_pass_ends_at !== null
            && Carbon::parse($user->platform_pass_ends_at)->isFuture();

        return Inertia::render('settings/billing', [
            'hasStripeCustomer' => $hasStripeCustomer,
            'subscribed' => $subscribed,
            'planLine' => $planLine,
            'nextBilling' => $nextBilling,
            'paymentMethodSummary' => $paymentMethodSummary,
            'plans' => $plans,
            'canSwapPlan' => $canSwapPlan,
            'swapBlockedReason' => $swapBlockedReason,
            'currentPlanTier' => BillingPlanCatalog::resolvedUserTier($currentPriceId, $recurringInterval),
            'access_required' => $request->boolean('access_required'),
            'checkout_cancelled' => $request->query('checkout') === 'cancel',
            'sprint_checkout_configured' => $this->priceConfigured('sprint'),
            'has_active_sprint_pass' => $hasActiveSprintPass,
        ]);
    }

    public function subscribe(StartBillingSubscriptionCheckoutRequest $request): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();

        if ($user->subscribed('default')) {
            return back()->withErrors([
                'billing' => 'You already have an active subscription. Use plan change or the billing portal.',
            ]);
        }

        $cycle = $request->validated('billing_cycle');
        $priceId = $cycle === 'annual'
            ? config('billing.prices.pro_annual')
            : config('billing.prices.pro_monthly');

        if (! is_string($priceId) || $priceId === '') {
            return back()->withErrors([
                'billing' => 'This plan is not configured yet. Add STRIPE_PRICE_PRO_MONTHLY or STRIPE_PRICE_PRO_ANNUAL in .env.',
            ]);
        }

        $successUrl = route('billing.edit').'?checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = route('billing.edit').'?checkout=cancel';

        $checkoutOptions = [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan' => 'pro',
                'billing_cycle' => $cycle,
            ],
        ];

        $checkout = $user->newSubscription('default', $priceId)->checkout($checkoutOptions);

        return Inertia::location($checkout->url);
    }

    public function checkout(StartBillingCheckoutRequest $request): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();
        $plan = $request->validated('plan');

        $priceId = match ($plan) {
            'sprint' => config('billing.prices.sprint'),
            'boost' => config('billing.prices.boost'),
            default => null,
        };

        if (! is_string($priceId) || $priceId === '') {
            return back()->withErrors([
                'billing' => 'This plan is not configured yet. Add the matching STRIPE_PRICE_* key in .env.',
            ]);
        }

        $isBoost = $plan === 'boost';
        $successUrl = $isBoost
            ? route('addons').'?checkout=success&session_id={CHECKOUT_SESSION_ID}'
            : route('billing.edit').'?checkout=success&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $isBoost
            ? route('addons').'?checkout=cancel'
            : route('billing.edit').'?checkout=cancel';

        $checkoutOptions = [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan' => $plan,
                'billing_cycle' => 'one_time',
            ],
        ];

        $checkout = $user->checkout([$priceId => 1], $checkoutOptions);

        return Inertia::location($checkout->url);
    }

    private function maybeVerifyBillingCheckoutReturn(Request $request, User $user): void
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

        app(ApplyPlatformPassFromCheckoutSession::class)($user, $stripeSession->toArray());
    }

    private function priceConfigured(string $key): bool
    {
        $id = config('billing.prices.'.$key);

        return is_string($id) && $id !== '';
    }

    /**
     * Cashier leaves `subscriptions.stripe_price` null for some rows even when Stripe has one item.
     */
    private static function effectiveSubscriptionPriceId(Subscription $subscription): ?string
    {
        if (is_string($subscription->stripe_price) && $subscription->stripe_price !== '') {
            return $subscription->stripe_price;
        }

        $first = $subscription->items->first();

        return is_string($first?->stripe_price) && $first->stripe_price !== ''
            ? $first->stripe_price
            : null;
    }

    private static function stripeFirstItemInterval(\Stripe\Subscription $stripeSub): ?string
    {
        $data = $stripeSub->items->data[0] ?? null;
        $interval = $data?->price?->recurring?->interval ?? null;

        return is_string($interval) && $interval !== '' ? $interval : null;
    }

    public function swap(SwapSubscriptionRequest $request): RedirectResponse
    {
        $user = $request->user();
        $subscription = $user->subscription('default');

        if (! $subscription || ! $subscription->valid()) {
            return back()->withErrors([
                'billing' => 'No eligible subscription to change. Use the billing portal or complete checkout first.',
            ]);
        }

        $singleBillableItem = $subscription->hasSinglePrice()
            || $subscription->items->count() === 1;

        if (! $singleBillableItem) {
            return back()->withErrors([
                'billing' => 'No eligible subscription to change. Use the billing portal or complete checkout first.',
            ]);
        }

        $priceId = $request->validated('price_id');

        if ($subscription->stripe_price === $priceId) {
            $request->session()->flash(SessionKey::FLASH_DATA, [
                'status' => 'billing-plan-unchanged',
            ]);

            return back();
        }

        try {
            $subscription->swapAndInvoice($priceId);
        } catch (IncompletePayment $exception) {
            return redirect()->route('cashier.payment', [
                $exception->payment->id,
                'redirect' => route('billing.edit'),
            ]);
        } catch (SubscriptionUpdateFailure $exception) {
            return back()->withErrors([
                'billing' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'billing' => 'Could not update your plan. Try the billing portal or contact support.',
            ]);
        }

        $request->session()->flash(SessionKey::FLASH_DATA, [
            'status' => 'billing-plan-updated',
        ]);

        return back();
    }

    public function portal(Request $request): RedirectResponse|SymfonyResponse
    {
        $user = $request->user();

        try {
            $url = $user->billingPortalUrl(route('billing.edit'), array_filter([
                'configuration' => env('STRIPE_BILLING_PORTAL_CONFIGURATION'),
            ]));

            return Inertia::location($url);
        } catch (InvalidCustomer) {
            return back()->withErrors([
                'billing' => 'No billing account yet. Complete a purchase first, or contact support.',
            ]);
        }
    }
}
