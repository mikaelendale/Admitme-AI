import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CircleNotch } from '@phosphor-icons/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { addons } from '@/routes';

type PlanCard = {
    key: string;
    price_id: string | null;
    price_configured: boolean;
    headline: string;
    price_line: string;
    summary: string;
    badge: string | null;
    tier: number;
    is_current: boolean;
};

type Props = {
    hasStripeCustomer: boolean;
    subscribed: boolean;
    planLine: string;
    nextBilling: string | null;
    paymentMethodSummary: string | null;
    plans: PlanCard[];
    canSwapPlan: boolean;
    swapBlockedReason: string | null;
    currentPlanTier: number | null;
    access_required: boolean;
    checkout_cancelled: boolean;
    sprint_checkout_configured: boolean;
    trial_only_mode: boolean;
    has_active_sprint_pass: boolean;
    /** True when the user completed onboarding via "Skip for now" (no checkout). */
    billing_skipped_onboarding: boolean;
};

function swapButtonLabel(
    plan: PlanCard,
    canSwap: boolean,
    currentTier: number | null,
): string {
    if (plan.is_current) {
        return 'Current plan';
    }
    if (!canSwap) {
        return 'Unavailable';
    }
    if (currentTier === null) {
        return 'Switch plan (prorated)';
    }
    if (plan.tier > currentTier) {
        return 'Upgrade (prorated)';
    }
    if (plan.tier < currentTier) {
        return 'Downgrade (prorated)';
    }
    return 'Switch plan (prorated)';
}

function portalFallbackLabel(
    plan: PlanCard,
    currentTier: number | null,
): string {
    if (plan.tier > (currentTier ?? 0)) {
        return 'Upgrade to annual (billing portal)';
    }
    if (plan.tier < (currentTier ?? 999)) {
        return 'Switch in billing portal';
    }
    return 'Open billing portal';
}

export default function Billing({
    hasStripeCustomer,
    subscribed,
    planLine,
    nextBilling,
    paymentMethodSummary,
    plans,
    canSwapPlan,
    swapBlockedReason,
    currentPlanTier,
    access_required,
    checkout_cancelled,
    sprint_checkout_configured,
    trial_only_mode,
    has_active_sprint_pass,
    billing_skipped_onboarding,
}: Props) {
    const { errors } = usePage<{ errors: { billing?: string } }>().props;
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    const [billingAction, setBillingAction] = useState<string | null>(null);
    const billingBusy = billingAction !== null;

    function visitOptions(key: string): {
        preserveScroll: boolean;
        onStart: () => void;
        onFinish: () => void;
    } {
        return {
            preserveScroll: true,
            onStart: () => setBillingAction(key),
            onFinish: () => setBillingAction(null),
        };
    }

    function actionDisabled(
        key: string,
        processing: boolean,
        extra?: boolean,
    ): boolean {
        return (
            (extra ?? false) ||
            processing ||
            (billingBusy && billingAction !== key)
        );
    }

    return (
        <>
            <Head title="Plan & billing" />

            <h1 className="sr-only">Plan and billing</h1>

            <div className="space-y-8">
                <Heading
                    variant="small"
                    title="Plan & billing"
                    description={
                        trial_only_mode
                            ? 'Your current access is below. The $1 trial is only available during onboarding; Sprint, Pro, and other checkouts are paused while we limit costs.'
                            : 'Subscribe to Pro, buy a 90-day pass, or manage billing in Stripe. The $1 trial is only available during onboarding.'
                    }
                />
                <p className="-mt-4 text-sm text-muted-foreground">
                    <Link
                        href={addons.url()}
                        className="font-medium text-primary underline-offset-2 hover:underline"
                    >
                        Add-ons
                    </Link>{' '}
                    — Boost Pack and future one-time services (separate from
                    access time).
                </p>

                {flash?.status === 'billing-plan-updated' ? (
                    <p className="text-sm text-emerald-600 dark:text-emerald-400">
                        Plan updated. Prorated charges or credits appear on your
                        Stripe invoice.
                    </p>
                ) : null}
                {flash?.status === 'billing-plan-unchanged' ? (
                    <p className="text-sm text-muted-foreground">
                        You are already on this plan.
                    </p>
                ) : null}

                {access_required ? (
                    <Alert variant="destructive">
                        <AlertTitle>Your access has expired</AlertTitle>
                        <AlertDescription>
                            {trial_only_mode ? (
                                <>
                                    New purchases are paused right now. Contact
                                    support if you need workspace access, or use
                                    the $1 trial during onboarding on a new
                                    account if that fits your situation.
                                </>
                            ) : (
                                <>
                                    Subscribe to Pro or purchase a 90-day pass
                                    to use the dashboard and workspace again.
                                    The $1 trial is only available during
                                    onboarding.
                                </>
                            )}
                        </AlertDescription>
                    </Alert>
                ) : null}

                {checkout_cancelled ? (
                    <Alert>
                        <AlertTitle>Checkout cancelled</AlertTitle>
                        <AlertDescription>
                            No charges were made. You can try again below.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <div className="space-y-4 rounded-lg border border-border/60 bg-muted/20 p-4">
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">
                                Current plan
                            </dt>
                            <dd className="font-medium text-foreground">
                                {planLine}
                            </dd>
                        </div>
                        {subscribed && nextBilling ? (
                            <div>
                                <dt className="text-muted-foreground">
                                    Next billing
                                </dt>
                                <dd className="font-medium text-foreground">
                                    {nextBilling}
                                </dd>
                            </div>
                        ) : null}
                        {paymentMethodSummary ? (
                            <div className="sm:col-span-2">
                                <dt className="text-muted-foreground">
                                    Payment method
                                </dt>
                                <dd className="font-medium text-foreground">
                                    {paymentMethodSummary}
                                    <span className="ml-1 text-sm font-normal text-muted-foreground">
                                        · Update in portal
                                    </span>
                                </dd>
                            </div>
                        ) : null}
                    </dl>

                    {hasStripeCustomer ? (
                        <div className="flex flex-wrap gap-2">
                            <Form
                                action="/settings/billing/portal"
                                method="post"
                                options={visitOptions('portal')}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="secondary"
                                        disabled={actionDisabled(
                                            'portal',
                                            processing,
                                        )}
                                    >
                                        {processing
                                            ? 'Opening…'
                                            : 'View billing history & invoices'}
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {trial_only_mode
                                ? 'No Stripe billing profile yet. Paid checkouts are paused — contact support if you need an exception.'
                                : 'Subscribe to Pro or buy a 90-day pass below to create your Stripe billing account. Contact support if you believe this is an error.'}
                        </p>
                    )}

                    <InputError message={errors.billing} className="text-sm" />
                </div>

                {!subscribed && !has_active_sprint_pass && trial_only_mode ? (
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold text-foreground">
                            Your plan
                        </h2>
                        <Card size="sm" className="max-w-lg">
                            <CardHeader className="border-b border-border/60 pb-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <CardTitle className="text-sm">
                                        Current access
                                    </CardTitle>
                                    <Badge variant="secondary">No paid plan</Badge>
                                </div>
                                <CardDescription className="text-sm font-semibold text-foreground">
                                    {billing_skipped_onboarding
                                        ? 'You finished onboarding without checkout.'
                                        : 'No active trial, pass, or subscription on file.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="pt-4">
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {billing_skipped_onboarding ? (
                                        <>
                                            Sprint, Pro, and other upgrades are
                                            hidden while checkouts are paused.
                                            The{' '}
                                            <span className="font-medium text-foreground">
                                                $1 trial
                                            </span>{' '}
                                            is only offered on the onboarding
                                            billing step — contact us if you need
                                            access.
                                        </>
                                    ) : (
                                        <>
                                            Paid passes and Pro signup are
                                            paused while we limit costs. The{' '}
                                            <span className="font-medium text-foreground">
                                                $1 trial
                                            </span>{' '}
                                            is only available during onboarding
                                            — contact us if you need an exception.
                                        </>
                                    )}
                                </p>
                            </CardContent>
                        </Card>
                    </section>
                ) : null}

                {!subscribed && !has_active_sprint_pass && !trial_only_mode ? (
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold text-foreground">
                            One-time access
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            The $1 trial is only offered during onboarding.
                            Buy the 90-day Admissions Sprint here, or subscribe
                            to Pro below.
                        </p>
                        <div className="grid gap-4 md:max-w-md">
                            <Card size="sm">
                                <CardHeader className="border-b border-border/60 pb-4">
                                    <CardTitle className="text-sm">
                                        90-day pass
                                    </CardTitle>
                                    <CardDescription className="text-sm font-semibold text-foreground">
                                        Admissions sprint
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="pt-4">
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        Full access for the configured sprint
                                        period (default 90 days). Stacks if you
                                        already have time left.
                                    </p>
                                </CardContent>
                                <CardFooter className="border-t border-border/60 pt-4">
                                    <Form
                                        action="/settings/billing/checkout"
                                        method="post"
                                        options={visitOptions('sprint')}
                                        className="w-full"
                                    >
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="plan"
                                                    value="sprint"
                                                />
                                                <Button
                                                    type="submit"
                                                    className="w-full"
                                                    variant="secondary"
                                                    disabled={actionDisabled(
                                                        'sprint',
                                                        processing,
                                                        !sprint_checkout_configured,
                                                    )}
                                                >
                                                    {processing ? (
                                                        <>
                                                            <CircleNotch
                                                                className="mr-2 inline size-4 animate-spin"
                                                                aria-hidden
                                                            />
                                                            Redirecting to
                                                            checkout...
                                                        </>
                                                    ) : sprint_checkout_configured ? (
                                                        'Checkout 90-day pass'
                                                    ) : (
                                                        'Not configured'
                                                    )}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </CardFooter>
                            </Card>
                        </div>
                    </section>
                ) : null}

                {plans.length > 0 ? (
                    <section className="space-y-3">
                        <h2 className="text-sm font-semibold text-foreground">
                            Change Pro plan
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Upgrades and downgrades use Stripe proration: unused
                            time on your current price becomes a credit, and the
                            new price is charged for the rest of the period. You
                            will see an invoice right away for any amount due.
                        </p>
                        {subscribed && swapBlockedReason ? (
                            <p className="text-sm text-amber-700 dark:text-amber-400">
                                {swapBlockedReason}
                            </p>
                        ) : null}
                        {!subscribed ? (
                            <p className="text-sm text-muted-foreground">
                                {has_active_sprint_pass
                                    ? 'You have an active 90-day Admissions Sprint. Subscribe to Pro below anytime — listed prices apply once your Stripe price IDs are configured.'
                                    : 'Subscribe to Pro here with Stripe Checkout, or buy the 90-day pass above. Listed prices apply once your Stripe price IDs are configured.'}
                            </p>
                        ) : null}

                        <div className="grid gap-4 md:grid-cols-2">
                            {plans.map((plan) => {
                                const label = swapButtonLabel(
                                    plan,
                                    canSwapPlan,
                                    currentPlanTier,
                                );
                                const portalLabel = portalFallbackLabel(
                                    plan,
                                    currentPlanTier,
                                );
                                const showSwap =
                                    plan.price_configured &&
                                    canSwapPlan &&
                                    subscribed &&
                                    !plan.is_current;
                                const showPortalFallback =
                                    !plan.is_current &&
                                    subscribed &&
                                    hasStripeCustomer &&
                                    (!plan.price_configured || !canSwapPlan);
                                const showSubscribe =
                                    !subscribed &&
                                    plan.price_configured &&
                                    (plan.key === 'pro_monthly' ||
                                        plan.key === 'pro_annual');
                                const subscribeCycle =
                                    plan.key === 'pro_annual'
                                        ? 'annual'
                                        : 'monthly';
                                const disabledOutline =
                                    plan.is_current ||
                                    (!showSwap &&
                                        !showPortalFallback &&
                                        !showSubscribe &&
                                        subscribed) ||
                                    (!subscribed &&
                                        !plan.is_current &&
                                        !showSubscribe);

                                return (
                                    <Card
                                        key={plan.key}
                                        size="sm"
                                        className={cn(
                                            plan.is_current &&
                                                'ring-2 ring-primary/40',
                                        )}
                                    >
                                        <CardHeader className="border-b border-border/60 pb-4">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <CardTitle className="text-sm">
                                                    {plan.headline}
                                                </CardTitle>
                                                {plan.badge ? (
                                                    <Badge variant="secondary">
                                                        {plan.badge}
                                                    </Badge>
                                                ) : null}
                                                {plan.is_current ? (
                                                    <Badge>Current</Badge>
                                                ) : null}
                                                {!plan.price_configured ? (
                                                    <Badge variant="outline">
                                                        Portal or env
                                                    </Badge>
                                                ) : null}
                                            </div>
                                            <CardDescription className="text-sm font-semibold text-foreground">
                                                {plan.price_line}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="pt-4">
                                            <p className="text-sm leading-relaxed text-muted-foreground">
                                                {plan.summary}
                                            </p>
                                        </CardContent>
                                        <CardFooter className="border-t border-border/60 pt-4">
                                            {showSwap ? (
                                                <Form
                                                    action="/settings/billing/swap"
                                                    method="post"
                                                    options={visitOptions(
                                                        `swap:${plan.key}`,
                                                    )}
                                                    className="w-full"
                                                >
                                                    {({ processing }) => (
                                                        <>
                                                            <input
                                                                type="hidden"
                                                                name="price_id"
                                                                value={
                                                                    plan.price_id ??
                                                                    ''
                                                                }
                                                            />
                                                            <Button
                                                                type="submit"
                                                                className="w-full"
                                                                disabled={actionDisabled(
                                                                    `swap:${plan.key}`,
                                                                    processing,
                                                                )}
                                                            >
                                                                {processing ? (
                                                                    <>
                                                                        <CircleNotch
                                                                            className="mr-2 inline size-4 animate-spin"
                                                                            aria-hidden
                                                                        />
                                                                        Updating…
                                                                    </>
                                                                ) : (
                                                                    label
                                                                )}
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                            ) : showPortalFallback ? (
                                                <Form
                                                    action="/settings/billing/portal"
                                                    method="post"
                                                    options={visitOptions(
                                                        'portal',
                                                    )}
                                                    className="w-full"
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            className="w-full"
                                                            variant="secondary"
                                                            disabled={actionDisabled(
                                                                'portal',
                                                                processing,
                                                            )}
                                                        >
                                                            {processing
                                                                ? 'Opening…'
                                                                : portalLabel}
                                                        </Button>
                                                    )}
                                                </Form>
                                            ) : showSubscribe ? (
                                                <Form
                                                    action="/settings/billing/subscribe"
                                                    method="post"
                                                    options={visitOptions(
                                                        `subscribe:${plan.key}`,
                                                    )}
                                                    className="w-full"
                                                >
                                                    {({ processing }) => (
                                                        <>
                                                            <input
                                                                type="hidden"
                                                                name="billing_cycle"
                                                                value={
                                                                    subscribeCycle
                                                                }
                                                            />
                                                            <Button
                                                                type="submit"
                                                                className="w-full"
                                                                disabled={actionDisabled(
                                                                    `subscribe:${plan.key}`,
                                                                    processing,
                                                                )}
                                                                variant={
                                                                    plan.key ===
                                                                    'pro_annual'
                                                                        ? 'secondary'
                                                                        : 'default'
                                                                }
                                                            >
                                                                {processing ? (
                                                                    <>
                                                                        <CircleNotch
                                                                            className="mr-2 inline size-4 animate-spin"
                                                                            aria-hidden
                                                                        />
                                                                        Redirecting
                                                                        to
                                                                        checkout...
                                                                    </>
                                                                ) : subscribeCycle ===
                                                                  'annual' ? (
                                                                    'Subscribe — $150/year'
                                                                ) : (
                                                                    'Subscribe — $15/month'
                                                                )}
                                                            </Button>
                                                        </>
                                                    )}
                                                </Form>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    className="w-full"
                                                    variant={
                                                        plan.is_current
                                                            ? 'secondary'
                                                            : 'outline'
                                                    }
                                                    disabled={disabledOutline}
                                                >
                                                    {plan.is_current
                                                        ? label
                                                        : !plan.price_configured
                                                          ? 'Not configured'
                                                          : label}
                                                </Button>
                                            )}
                                        </CardFooter>
                                    </Card>
                                );
                            })}
                        </div>
                    </section>
                ) : null}

                {subscribed && hasStripeCustomer ? (
                    <div className="space-y-3 rounded-lg bg-background border border-dashed border-border/80 p-4">
                        <p className="text-sm text-muted-foreground">
                            Cancel subscription, dispute payment methods, or
                            manage tax IDs in the Stripe customer portal.
                        </p>
                        <Form
                            action="/settings/billing/portal"
                            method="post"
                            options={visitOptions('portal')}
                            className="w-full "
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="w-full"
                                    disabled={actionDisabled(
                                        'portal',
                                        processing,
                                    )}
                                >
                                    {processing
                                        ? 'Opening…'
                                        : 'Open billing portal'}
                                </Button>
                            )}
                        </Form>
                    </div>
                ) : null}
            </div>
        </>
    );
}

Billing.layout = {
    breadcrumbs: [
        {
            title: 'Billing',
            href: '/settings/billing',
        },
    ],
};
