import { Form, Head, usePage } from '@inertiajs/react';
import {
    CircleNotch,
    Crown,
    CreditCard,
    Package,
    RocketLaunch,
    ShoppingBag,
    SquaresFour,
    Ticket,
} from '@phosphor-icons/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import {
    IconTile,
    KeyValueList,
    KeyValueRow,
    ListCard,
    ListRow,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import {
    Notice,
    pillButton,
    settingsBreadcrumbs,
} from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
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

/** A spinner before a button label while its request runs. */
function Busy({ children }: { children: string }) {
    return (
        <>
            <CircleNotch className="size-4 animate-spin" aria-hidden />
            {children}
        </>
    );
}

/** One plan or pass in the plans list: what it is on the left, its action on the right (below on phones). */
function PlanRow({
    icon,
    title,
    price,
    badges,
    summary,
    action,
    current,
}: {
    icon: ReactNode;
    title: string;
    price: string;
    badges?: ReactNode;
    summary: string;
    action: ReactNode;
    current?: boolean;
}) {
    return (
        <li
            className={cn(
                'flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center',
                current && 'bg-muted/50',
            )}
        >
            <div className="flex min-w-0 flex-1 items-start gap-4">
                <span className="mt-0.5 flex shrink-0">{icon}</span>
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[15px] font-medium text-foreground">
                        {title}
                        <span className="nums font-normal text-muted-foreground">
                            {price}
                        </span>
                        {badges}
                    </p>
                    <p className="mt-1 text-[13.5px] leading-snug text-muted-foreground">
                        {summary}
                    </p>
                </div>
            </div>
            <div className="shrink-0 pl-[52px] sm:pl-0">{action}</div>
        </li>
    );
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
    const page = usePage<{
        errors: { billing?: string };
        flash?: { status?: string };
    }>();
    const { errors } = page.props;
    // Inertia v3 sends flash data beside the props (page.flash); older responses put it in props.
    const flashStatus =
        (page.flash as { status?: string } | undefined)?.status ??
        page.props.flash?.status;

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

    const noPaidPlan = !subscribed && !has_active_sprint_pass;
    const showSprintOffer = noPaidPlan && !trial_only_mode;
    const planTone = subscribed
        ? 'violet'
        : has_active_sprint_pass
          ? 'blue'
          : 'slate';
    const PlanIcon = subscribed
        ? Crown
        : has_active_sprint_pass
          ? RocketLaunch
          : Ticket;
    const priceNote =
        'Listed prices apply once the Stripe prices are configured.';
    const plansNote = subscribed
        ? 'Stripe prorates changes: unused time on your current price becomes a credit, the new price covers the rest of the period, and any amount due is invoiced right away.'
        : showSprintOffer
          ? plans.length > 0
              ? `Buy the 90-day Admissions Sprint once, or subscribe to Pro. ${priceNote}`
              : 'Buy the 90-day Admissions Sprint once.'
          : has_active_sprint_pass
            ? `You have an active 90-day Admissions Sprint. Subscribe to Pro anytime. ${priceNote}`
            : `Subscribe to Pro with Stripe Checkout. ${priceNote}`;

    return (
        <>
            <Head title="Plan & billing" />

            <p className="-mt-3 mb-8 text-[14px] leading-relaxed text-muted-foreground">
                {trial_only_mode
                    ? 'Sprint, Pro and other checkouts are paused while we limit costs. The $1 trial is only offered during onboarding.'
                    : 'Subscribe to Pro, buy a 90-day pass, or manage billing in Stripe. The $1 trial is only offered during onboarding.'}
            </p>

            {flashStatus === 'billing-plan-updated' ||
            flashStatus === 'billing-plan-unchanged' ||
            access_required ||
            checkout_cancelled ? (
                <div className="mb-8 space-y-2">
                    {flashStatus === 'billing-plan-updated' ? (
                        <Notice tone="success">
                            Plan updated. Prorated charges or credits appear on
                            your Stripe invoice.
                        </Notice>
                    ) : null}
                    {flashStatus === 'billing-plan-unchanged' ? (
                        <Notice>You are already on this plan.</Notice>
                    ) : null}
                    {access_required ? (
                        <Notice tone="danger" title="Your access has expired">
                            {trial_only_mode
                                ? 'New purchases are paused right now. Contact support if you need workspace access, or use the $1 trial during onboarding on a new account if that fits your situation.'
                                : 'Subscribe to Pro or buy a 90-day pass to use the dashboard and workspace again. The $1 trial is only offered during onboarding.'}
                        </Notice>
                    ) : null}
                    {checkout_cancelled ? (
                        <Notice title="Checkout cancelled">
                            No charges were made. You can try again below.
                        </Notice>
                    ) : null}
                </div>
            ) : null}

            <Section title="Current plan" icon={CreditCard}>
                <div className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="flex items-center gap-4 p-5">
                        <IconTile
                            icon={PlanIcon}
                            tone={planTone}
                            variant="gradient"
                            size="lg"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="flex flex-wrap items-center gap-2 text-[15px] leading-snug font-semibold text-foreground">
                                {planLine}
                                {noPaidPlan && trial_only_mode ? (
                                    <StatusBadge>No paid plan</StatusBadge>
                                ) : null}
                            </p>
                            {noPaidPlan && trial_only_mode ? (
                                <p className="mt-1 text-[13.5px] leading-snug text-muted-foreground">
                                    {billing_skipped_onboarding
                                        ? 'You finished onboarding without checkout. Upgrades are hidden while checkouts are paused; the $1 trial is only offered on the onboarding billing step. Contact us if you need access.'
                                        : 'No active trial, pass, or subscription on file. Paid passes and Pro signup are paused while we limit costs; the $1 trial is only offered during onboarding. Contact us if you need an exception.'}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    {(subscribed && nextBilling) || paymentMethodSummary ? (
                        <KeyValueList className="border-t border-border px-5">
                            {subscribed && nextBilling ? (
                                <KeyValueRow
                                    label="Next billing"
                                    value={nextBilling}
                                />
                            ) : null}
                            {paymentMethodSummary ? (
                                <KeyValueRow
                                    label="Payment method"
                                    hint="Update it in the billing portal."
                                    value={paymentMethodSummary}
                                />
                            ) : null}
                        </KeyValueList>
                    ) : null}

                    <div className="flex flex-col gap-3 border-t border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        {hasStripeCustomer ? (
                            <>
                                <p className="text-[13px] leading-snug text-muted-foreground">
                                    {subscribed
                                        ? 'Billing history, invoices, payment methods, tax IDs and cancelling your subscription are in the Stripe portal.'
                                        : 'Your billing history and invoices are in the Stripe portal.'}
                                </p>
                                <Form
                                    action="/settings/billing/portal"
                                    method="post"
                                    options={visitOptions('portal')}
                                    className="shrink-0"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            className={pillButton}
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
                            </>
                        ) : (
                            <p className="text-[13px] leading-snug text-muted-foreground">
                                {trial_only_mode
                                    ? 'No Stripe billing profile yet. Paid checkouts are paused — contact support if you need an exception.'
                                    : 'Subscribe to Pro or buy a 90-day pass below to create your Stripe billing account. Contact support if you believe this is an error.'}
                            </p>
                        )}
                    </div>
                    {errors.billing ? (
                        <InputError
                            message={errors.billing}
                            className="border-t border-border px-5 py-3"
                        />
                    ) : null}
                </div>
            </Section>

            {showSprintOffer || plans.length > 0 ? (
                <Section
                    title={
                        plans.length > 0
                            ? subscribed
                                ? 'Change Pro plan'
                                : 'Plans'
                            : 'One-time access'
                    }
                    icon={SquaresFour}
                    description={plansNote}
                >
                    {subscribed && swapBlockedReason ? (
                        <Notice tone="warning" className="mb-3">
                            {swapBlockedReason}
                        </Notice>
                    ) : null}

                    <ListCard>
                        {showSprintOffer ? (
                            <PlanRow
                                icon={
                                    <IconTile icon={RocketLaunch} tone="blue" />
                                }
                                title="90-day pass"
                                price="Admissions sprint"
                                summary="Full access for the sprint period (90 days by default). Stacks if you already have time left."
                                action={
                                    <Form
                                        action="/settings/billing/checkout"
                                        method="post"
                                        options={visitOptions('sprint')}
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
                                                    variant="outline"
                                                    className={pillButton}
                                                    disabled={actionDisabled(
                                                        'sprint',
                                                        processing,
                                                        !sprint_checkout_configured,
                                                    )}
                                                >
                                                    {processing ? (
                                                        <Busy>
                                                            Redirecting to
                                                            checkout...
                                                        </Busy>
                                                    ) : sprint_checkout_configured ? (
                                                        'Checkout 90-day pass'
                                                    ) : (
                                                        'Not configured'
                                                    )}
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                }
                            />
                        ) : null}

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
                                <PlanRow
                                    key={plan.key}
                                    current={plan.is_current}
                                    icon={
                                        <IconTile icon={Crown} tone="violet" />
                                    }
                                    title={plan.headline}
                                    price={plan.price_line}
                                    summary={plan.summary}
                                    badges={
                                        <>
                                            {plan.badge ? (
                                                <StatusBadge tone="success">
                                                    {plan.badge}
                                                </StatusBadge>
                                            ) : null}
                                            {plan.is_current ? (
                                                <StatusBadge tone="info">
                                                    Current
                                                </StatusBadge>
                                            ) : null}
                                            {!plan.price_configured ? (
                                                <StatusBadge>
                                                    Portal or env
                                                </StatusBadge>
                                            ) : null}
                                        </>
                                    }
                                    action={
                                        showSwap ? (
                                            <Form
                                                action="/settings/billing/swap"
                                                method="post"
                                                options={visitOptions(
                                                    `swap:${plan.key}`,
                                                )}
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
                                                            className={
                                                                pillButton
                                                            }
                                                            disabled={actionDisabled(
                                                                `swap:${plan.key}`,
                                                                processing,
                                                            )}
                                                        >
                                                            {processing ? (
                                                                <Busy>
                                                                    Updating…
                                                                </Busy>
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
                                                options={visitOptions('portal')}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        className={pillButton}
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
                                                            className={
                                                                pillButton
                                                            }
                                                            disabled={actionDisabled(
                                                                `subscribe:${plan.key}`,
                                                                processing,
                                                            )}
                                                            variant={
                                                                plan.key ===
                                                                'pro_annual'
                                                                    ? 'outline'
                                                                    : 'default'
                                                            }
                                                        >
                                                            {processing ? (
                                                                <Busy>
                                                                    Redirecting
                                                                    to
                                                                    checkout...
                                                                </Busy>
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
                                                className={pillButton}
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
                                        )
                                    }
                                />
                            );
                        })}
                    </ListCard>
                </Section>
            ) : null}

            <Section title="One-time extras" icon={ShoppingBag}>
                <ListCard>
                    <ListRow
                        href={addons.url()}
                        icon={<IconTile icon={Package} tone="amber" />}
                        title="Add-ons"
                        description="Boost Pack and future one-time services, separate from access time."
                    />
                </ListCard>
            </Section>
        </>
    );
}

Billing.layout = {
    breadcrumbs: settingsBreadcrumbs('Billing', '/settings/billing'),
};
