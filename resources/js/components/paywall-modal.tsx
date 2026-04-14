'use client';

import { router } from '@inertiajs/react';
import { Check, CircleNotch } from '@phosphor-icons/react';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { edit as billingEdit } from '@/routes/billing';

const PLANS = [
    {
        title: '3-day trial',
        price: '$1',
        detail: 'Full access to try everything',
    },
    {
        title: 'Pro monthly',
        price: '$15/mo',
        detail: 'Flexible subscription, cancel anytime',
    },
    {
        title: '90-day pass',
        price: '$29 one-time',
        detail: 'One payment, full access for the season',
    },
] as const;

export function PaywallModal({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [unlockBusy, setUnlockBusy] = useState(false);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                overlayClassName="fixed inset-0 isolate z-50 bg-black/55 duration-100 supports-backdrop-filter:backdrop-blur-sm data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0"
            >
                <DialogHeader>
                    <DialogTitle className="text-lg">Unlock AdmitMe</DialogTitle>
                    <DialogDescription>
                        Start a $1 trial or pick a plan — all three unlock the full workspace.
                    </DialogDescription>
                </DialogHeader>

                <ul className="space-y-3">
                    {PLANS.map((plan) => (
                        <li
                            key={plan.title}
                            className="flex gap-3 rounded-lg border border-border/60 bg-muted/30 px-3 py-2.5"
                        >
                            <span className="mt-0.5 text-primary">
                                <Check className="size-4" weight="bold" aria-hidden />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="font-medium text-foreground">
                                    <span className="text-primary">{plan.price}</span>
                                    <span className="text-muted-foreground"> — </span>
                                    {plan.title}
                                </p>
                                <p className="text-sm text-muted-foreground">{plan.detail}</p>
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="flex flex-col gap-2 pt-1">
                    <Button
                        type="button"
                        className="w-full"
                        disabled={unlockBusy}
                        onClick={() => {
                            setUnlockBusy(true);
                            router.visit(billingEdit.url(), {
                                onFinish: () => setUnlockBusy(false),
                            });
                        }}
                    >
                        {unlockBusy ? (
                            <>
                                <CircleNotch
                                    className="mr-2 inline size-4 animate-spin"
                                    aria-hidden
                                />
                                Redirecting to checkout...
                            </>
                        ) : (
                            'Unlock now'
                        )}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        className="w-full text-muted-foreground"
                        disabled={unlockBusy}
                        onClick={() => onOpenChange(false)}
                    >
                        Maybe later
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
