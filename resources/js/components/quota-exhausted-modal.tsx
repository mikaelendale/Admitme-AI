'use client';

import { router } from '@inertiajs/react';
import { WarningCircle } from '@phosphor-icons/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { edit as billingEdit } from '@/routes/billing';
import type { QuotaFeatureDetail } from '@/types/feature-quotas';

function formatReset(iso: string | null | undefined): string | null {
    if (!iso) {
        return null;
    }

    try {
        const d = new Date(iso);

        return d.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    } catch {
        return null;
    }
}

export function QuotaExhaustedModal({
    open,
    onOpenChange,
    quota,
    plan,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quota: QuotaFeatureDetail | null;
    /** When `trial`, copy mentions upgrading; paid plans mention monthly reset. */
    plan?: 'trial' | 'sprint' | 'pro';
}) {
    const [busy, setBusy] = useState(false);
    const limit = quota?.limit ?? null;
    const used = quota?.used ?? 0;
    const label = quota?.label ?? 'this action';
    const reset = formatReset(quota?.resets_at ?? null);
    const isTrial = plan === 'trial';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                overlayClassName="fixed inset-0 isolate z-50 bg-black/55 duration-100 supports-backdrop-filter:backdrop-blur-sm data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0"
            >
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-lg">
                        <WarningCircle
                            className="size-5 text-amber-600"
                            weight="bold"
                            aria-hidden
                        />
                        You&apos;ve reached your limit
                    </DialogTitle>
                    <DialogDescription asChild>
                        <div className="space-y-3 pt-1 text-sm text-muted-foreground">
                            {limit != null ? (
                                <p>
                                    You&apos;ve used{' '}
                                    <span className="font-medium text-foreground">
                                        {used} of {limit}
                                    </span>{' '}
                                    {label}
                                    {reset ? (
                                        <>
                                            {' '}
                                            — next slot frees around{' '}
                                            <span className="text-foreground">
                                                {reset}
                                            </span>
                                            .
                                        </>
                                    ) : null}
                                </p>
                            ) : (
                                <p>
                                    You&apos;ve reached the limit for {label}.
                                </p>
                            )}
                            {isTrial ? (
                                <p>
                                    Your trial includes tight caps so everyone
                                    gets a fair try. Upgrade to Sprint or Pro
                                    for higher monthly limits.
                                </p>
                            ) : (
                                <p>
                                    You can wait for your usage window to roll
                                    forward or upgrade if you need more
                                    capacity.
                                </p>
                            )}
                        </div>
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-2 pt-1">
                    <Button
                        type="button"
                        className="w-full"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.visit(billingEdit.url(), {
                                onFinish: () => setBusy(false),
                            });
                        }}
                    >
                        {busy ? 'Opening billing…' : 'View plans & billing'}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        className="w-full"
                        onClick={() => onOpenChange(false)}
                    >
                        Got it
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
