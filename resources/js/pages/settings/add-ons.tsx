'use client';

import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Check, Lightning, Sparkle } from '@phosphor-icons/react';
import InputError from '@/components/input-error';
import {
    IconTile,
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
import {
    checkout as billingCheckout,
    edit as billingEdit,
} from '@/routes/billing';

type BoostPayload = {
    checkout_configured: boolean;
    headline: string;
    price_line: string;
    summary: string;
    bullets: string[];
};

type Props = {
    boost: BoostPayload;
    trial_only_mode?: boolean;
    checkout_success: boolean;
    checkout_cancelled: boolean;
};

export default function AddOns({
    boost,
    trial_only_mode: trialOnlyMode = false,
    checkout_success,
    checkout_cancelled,
}: Props) {
    const { errors } = usePage<{ errors: { billing?: string } }>().props;

    return (
        <>
            <Head title="Add-ons" />

            <p className="-mt-3 mb-8 text-[14px] leading-relaxed text-muted-foreground">
                One-time extras, paid once. Subscriptions and passes are on{' '}
                <Link
                    href={billingEdit()}
                    className="font-medium text-foreground underline decoration-foreground/25 underline-offset-4 hover:decoration-foreground"
                >
                    Billing
                </Link>
                .
            </p>

            {checkout_success || checkout_cancelled || errors.billing ? (
                <div className="mb-8 space-y-2">
                    {checkout_success ? (
                        <Notice tone="success">
                            Checkout completed. If you were charged, you will
                            receive a Stripe receipt — fulfillment details
                            follow by email when enabled.
                        </Notice>
                    ) : null}
                    {checkout_cancelled ? (
                        <Notice>Checkout cancelled — no charge.</Notice>
                    ) : null}
                    <InputError message={errors.billing} />
                </div>
            ) : null}

            <Section title="Available" icon={Lightning}>
                <div className="rounded-2xl border border-border bg-card p-5">
                    <div className="flex items-start gap-4">
                        <IconTile
                            icon={Lightning}
                            tone="amber"
                            variant="gradient"
                            size="lg"
                        />
                        <div className="min-w-0">
                            <p className="text-[15.5px] font-semibold text-foreground">
                                {boost.headline}
                            </p>
                            <p className="mt-0.5 text-[13.5px] text-muted-foreground">
                                {boost.price_line}
                            </p>
                        </div>
                    </div>

                    {boost.summary ? (
                        <p className="mt-4 text-[14px] leading-relaxed text-muted-foreground">
                            {boost.summary}
                        </p>
                    ) : null}

                    {boost.bullets.length > 0 ? (
                        <ul className="mt-4 space-y-2">
                            {boost.bullets.map((b) => (
                                <li
                                    key={b}
                                    className="flex items-start gap-2.5 text-[14px] text-foreground"
                                >
                                    <Check
                                        className="mt-0.5 size-4 shrink-0 text-success"
                                        weight="bold"
                                        aria-hidden
                                    />
                                    {b}
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    <div className="mt-5 flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-[13px] leading-snug text-muted-foreground">
                            Doesn't change your access dates. Check your email
                            for next steps after purchase.
                        </p>
                        <Form
                            action={billingCheckout.url()}
                            method="post"
                            options={{ preserveScroll: true }}
                            className="shrink-0"
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="plan"
                                        value="boost"
                                    />
                                    <Button
                                        type="submit"
                                        className={pillButton}
                                        disabled={
                                            processing ||
                                            !boost.checkout_configured ||
                                            trialOnlyMode
                                        }
                                    >
                                        {processing
                                            ? 'Redirecting…'
                                            : trialOnlyMode
                                              ? 'Unavailable (trial-only mode)'
                                              : boost.checkout_configured
                                                ? 'Buy Boost Pack'
                                                : 'Stripe price not set'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                </div>
            </Section>

            <Section title="Coming soon" icon={Sparkle}>
                <ListCard>
                    <ListRow
                        icon={<IconTile icon={Sparkle} tone="slate" />}
                        title="Human essay review and counselor sessions"
                        description="These will appear here as separate add-ons."
                        trailing={<StatusBadge>Roadmap</StatusBadge>}
                    />
                </ListCard>
            </Section>
        </>
    );
}

AddOns.layout = {
    breadcrumbs: settingsBreadcrumbs('Add-ons', '/settings/add-ons'),
};
