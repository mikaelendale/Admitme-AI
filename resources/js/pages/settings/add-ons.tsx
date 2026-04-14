'use client';

import type { ReactNode } from 'react';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Lightning, RocketLaunch, Sparkle } from '@phosphor-icons/react';
import { Button } from '@/components/ui/button';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { checkout as billingCheckout, edit as billingEdit } from '@/routes/billing';

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

function Panel({
    title,
    children,
    className,
}: {
    title: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-lg border border-border/50 bg-card/30 p-3',
                className,
            )}
        >
            <h3 className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                {title}
            </h3>
            <div className="mt-2">{children}</div>
        </div>
    );
}

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

            <div className="space-y-4">
                <div className="space-y-1">
                    <h2 className="text-lg font-semibold tracking-tight text-foreground">
                        Add-ons
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        One-time Boost and future human services. Subscriptions
                        and passes live on{' '}
                        <Link
                            href={billingEdit()}
                            className="font-medium text-primary underline-offset-2 hover:underline"
                        >
                            Plan &amp; billing
                        </Link>
                        .
                    </p>
                </div>

                {checkout_success ? (
                    <p className="text-xs text-emerald-600 dark:text-emerald-400">
                        Checkout completed. If you were charged, you will receive
                        a Stripe receipt — fulfillment details follow by email
                        when enabled.
                    </p>
                ) : null}
                {checkout_cancelled ? (
                    <p className="text-xs text-muted-foreground">
                        Checkout cancelled — no charge.
                    </p>
                ) : null}

                <InputError message={errors.billing} className="text-xs" />

                <section className="overflow-hidden rounded-xl border border-border/60 bg-linear-to-b from-muted/15 to-transparent">
                    <div className="border-b border-border/50 px-3 py-2">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                            Revenue add-ons
                        </p>
                    </div>
                    <div className="grid grid-cols-1 divide-y divide-border/50 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                        <div className="flex flex-col gap-2 p-3 lg:col-span-2">
                            <div className="flex items-center gap-1.5 text-xs font-medium text-foreground">
                                <Lightning
                                    className="size-3.5 text-amber-500/90"
                                    aria-hidden
                                />
                                {boost.headline}
                            </div>
                            <p className="text-[11px] font-medium text-primary">
                                {boost.price_line}
                            </p>
                            {boost.summary ? (
                                <p className="text-[11px] leading-snug text-muted-foreground">
                                    {boost.summary}
                                </p>
                            ) : null}
                            {boost.bullets.length > 0 ? (
                                <ul className="list-inside list-disc space-y-0.5 text-[11px] text-foreground">
                                    {boost.bullets.map((b) => (
                                        <li key={b}>{b}</li>
                                    ))}
                                </ul>
                            ) : null}
                            <Form
                                action={billingCheckout.url()}
                                method="post"
                                options={{ preserveScroll: true }}
                                className="mt-1"
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
                                            size="sm"
                                            className="h-7 px-3 text-[11px]"
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
                        <div className="flex flex-col gap-2 p-3">
                            <div className="flex items-center gap-1.5 text-xs font-medium text-foreground">
                                <Sparkle
                                    className="size-3.5 text-muted-foreground"
                                    aria-hidden
                                />
                                Coming soon
                            </div>
                            <p className="text-[11px] leading-snug text-muted-foreground">
                                Human essay review sessions and counselor blocks
                                will appear here as separate SKUs.
                            </p>
                            <span className="mt-auto inline-flex w-fit rounded border border-border/60 px-2 py-0.5 text-[10px] text-muted-foreground">
                                Roadmap
                            </span>
                        </div>
                    </div>
                </section>

                <div className="grid gap-2 sm:grid-cols-2">
                    <Panel title="Pricing tiers">
                        <p className="text-[11px] leading-snug text-muted-foreground">
                            Pro monthly / annual and passes are on Plan &amp;
                            billing — this page is only for à la carte add-ons.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-2 h-7 border-border/70 text-[11px]"
                            asChild
                        >
                            <Link href={billingEdit()}>Open pricing</Link>
                        </Button>
                    </Panel>
                    <Panel title="Fulfillment">
                        <div className="flex items-start gap-2">
                            <RocketLaunch
                                className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <p className="text-[11px] leading-snug text-muted-foreground">
                                Boost does not change workspace access dates.
                                Check email for next steps after purchase.
                            </p>
                        </div>
                    </Panel>
                </div>
            </div>
        </>
    );
}
