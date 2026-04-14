'use client';

import { Form, Head, router, useForm, usePage, usePoll } from '@inertiajs/react';
import {
    CaretLeft,
    CaretRight,
    CircleNotch,
    Upload,
    Star,
    Lightning,
    ListChecksIcon,
    RocketLaunch,
    Lock,
    ListBullets,
    Wrench,
    PencilLine,
} from '@phosphor-icons/react';
import { motion, AnimatePresence } from 'framer-motion';
import { useEffect, useMemo, useState } from 'react';
import { PolarAngleAxis, PolarGrid, Radar, RadarChart } from 'recharts';
import OnboardingController from '@/actions/App/Http/Controllers/OnboardingController';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectValue,
    SelectTrigger,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

const STEPS = [
    {
        id: 1,
        title: 'Let’s see where your application actually stands.',
        description:
            'AdmitMe is your AI admissions mentor—guiding you from profile to acceptance. It shows what’s helping your application, what’s holding it back, and exactly what to do next.',
        icon: RocketLaunch,
    },
    {
        id: 2,
        title: 'Show me your application.',
        description:
            'Paste everything you’ve done so far—grades, activities, awards, goals. Messy is fine. I’ll structure it.',
        icon: Upload,
    },
    {
        id: 3,
        title: 'Admissions read in progress...',
        description:
            'We’re reviewing your profile the way an admissions officer would—flagging strengths, risks, and what could get you rejected.',
        icon: Star,
    },
    {
        id: 4,
        title: 'Choose your AdmitMe plan',
        description:
            'Billing is optional during onboarding. Pick a plan now, or skip and finish setup first.',
        icon: Lightning,
    },
    {
        id: 5,
        title: 'Your profile baseline is ready.',
        description:
            'This is where your journey begins. Track improvements, fix gaps, and build an application that stands out.',
        icon: ListChecksIcon,
    },
];

function countWords(text: string): number {
    const t = text.trim();

    if (!t) {
        return 0;
    }

    return t.split(/\s+/).filter(Boolean).length;
}

type NoCapDimension = {
    key: string;
    label: string;
    score: number;
    verdict: string;
};

type NoCapInsightToken = {
    text: string;
    tone: 'positive' | 'caution' | 'neutral';
};

export type NoCapReport = {
    headline: string;
    dimensions: NoCapDimension[];
    insight_tokens: NoCapInsightToken[];
    takeaways: string[];
};

const NO_CAP_DIMENSION_ORDER = [
    'academic_signal',
    'activities_impact',
    'positioning_spike',
    'essay_readiness',
    'narrative_coherence',
] as const;

function parseNoCapReport(answerJson: unknown): NoCapReport | null {
    if (!answerJson || typeof answerJson !== 'object') {
        return null;
    }

    const wrap = answerJson as Record<string, unknown>;
    const raw = wrap.structured;

    if (!raw || typeof raw !== 'object') {
        return null;
    }

    const o = raw as Record<string, unknown>;

    if (typeof o.headline !== 'string') {
        return null;
    }

    if (
        !Array.isArray(o.dimensions) ||
        !Array.isArray(o.insight_tokens) ||
        !Array.isArray(o.takeaways)
    ) {
        return null;
    }

    const dimensions: NoCapDimension[] = [];

    for (const item of o.dimensions) {
        if (!item || typeof item !== 'object') {
            return null;
        }

        const d = item as Record<string, unknown>;

        if (
            typeof d.key !== 'string' ||
            typeof d.label !== 'string' ||
            typeof d.score !== 'number' ||
            typeof d.verdict !== 'string'
        ) {
            return null;
        }

        dimensions.push({
            key: d.key,
            label: d.label,
            score: d.score,
            verdict: d.verdict,
        });
    }

    const insight_tokens: NoCapInsightToken[] = [];

    for (const item of o.insight_tokens) {
        if (!item || typeof item !== 'object') {
            return null;
        }

        const t = item as Record<string, unknown>;

        if (typeof t.text !== 'string' || !isInsightTone(t.tone)) {
            return null;
        }

        insight_tokens.push({ text: t.text, tone: t.tone });
    }

    const takeaways: string[] = [];

    for (const line of o.takeaways) {
        if (typeof line !== 'string') {
            return null;
        }

        takeaways.push(line);
    }

    return {
        headline: o.headline,
        dimensions,
        insight_tokens,
        takeaways,
    };
}

function isInsightTone(v: unknown): v is NoCapInsightToken['tone'] {
    return v === 'positive' || v === 'caution' || v === 'neutral';
}

function orderNoCapDimensions(dimensions: NoCapDimension[]): NoCapDimension[] {
    const order = new Map<string, number>(
        NO_CAP_DIMENSION_ORDER.map((k, i) => [k, i]),
    );

    return [...dimensions].sort(
        (a, b) => (order.get(a.key) ?? 99) - (order.get(b.key) ?? 99),
    );
}

const noCapRadarConfig = {
    score: {
        label: 'Signal',
        color: 'hsl(var(--chart-1))',
    },
} satisfies ChartConfig;

function verdictChipClass(verdict: string): string {
    switch (verdict) {
        case 'strong':
            return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200';
        case 'mixed':
            return 'border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-100';
        case 'weak':
            return 'border-rose-500/40 bg-rose-500/10 text-rose-900 dark:text-rose-100';
        default:
            return 'border-muted-foreground/25 bg-muted/40 text-muted-foreground';
    }
}

function insightToneClass(tone: NoCapInsightToken['tone']): string {
    switch (tone) {
        case 'positive':
            return 'border-emerald-500/30 bg-emerald-500/5 text-emerald-900 dark:text-emerald-100';
        case 'caution':
            return 'border-amber-500/35 bg-amber-500/5 text-amber-950 dark:text-amber-100';
        default:
            return 'border-border bg-muted/30 text-foreground/80';
    }
}

type AgeRangeValue =
    | '13_14'
    | '15_16'
    | '17_18'
    | '19plus'
    | 'under_13_blocked'
    | '';

type OnboardingResponseRow = {
    answer_text: string | null;
    answer_json: unknown;
};

export type OnboardingPageProps = {
    sessionUuid: string;
    sessionId: number;
    initialStep: number;
    needsAgeGate: boolean;
    checkoutVerified: boolean;
    billingSkipped: boolean;
    checkoutCancelled: boolean;
    selectedBilling: {
        plan: string | null;
        billing_cycle: string;
        label: string;
    };
    profile: {
        age_range: string | null;
        is_age_13_or_older_confirmed: boolean;
    };
    responses: Record<string, OnboardingResponseRow>;
    hasActiveSubscription: boolean;
    maxProfileWords: number;
    trial_only_mode?: boolean;
};

function isValidPersistedAgeRange(
    value: string,
): value is Exclude<AgeRangeValue, '' | 'under_13_blocked'> {
    return ['13_14', '15_16', '17_18', '19plus'].includes(value);
}

export default function OnboardingPage({
    initialStep,
    needsAgeGate,
    checkoutVerified,
    billingSkipped,
    checkoutCancelled,
    selectedBilling,
    profile,
    responses,
    hasActiveSubscription,
    maxProfileWords,
    trial_only_mode: trialOnlyMode = false,
}: OnboardingPageProps) {
    const { errors, csrf_token: csrfToken } = usePage<{
        errors: Record<string, string | undefined>;
        csrf_token?: string;
    }>().props;

    const [step, setStep] = useState(initialStep);
    const [profileText, setProfileText] = useState(
        () => responses['profile.freeform_text']?.answer_text ?? '',
    );
    const [noCapReport, setNoCapReport] = useState<NoCapReport | null>(null);
    const [noCapLoading, setNoCapLoading] = useState(false);
    const [noCapError, setNoCapError] = useState<string | null>(null);
    const [noCapFinished, setNoCapFinished] = useState(false);
    const [noCapStreamKey, setNoCapStreamKey] = useState(0);
    const [noCapLoadingMessage, setNoCapLoadingMessage] = useState<string>(
        'Generating your read… results appear here as soon as they are ready.',
    );
    const [recurringPlanCycle, setRecurringPlanCycle] = useState<
        'monthly' | 'annual'
    >('monthly');
    const [ageRange, setAgeRange] = useState<AgeRangeValue>(() => {
        const ar = profile.age_range;

        return ar && isValidPersistedAgeRange(ar) ? ar : ('' as AgeRangeValue);
    });
    const [legalAccepted, setLegalAccepted] = useState(false);
    const [profileClientError, setProfileClientError] = useState<string | null>(
        null,
    );
    const [stepProcessing, setStepProcessing] = useState(false);

    const checkoutForm = useForm({
        plan: 'trial' as 'trial' | 'sprint' | 'pro',
        billing_cycle: 'monthly' as 'monthly' | 'annual',
    });
    const [checkoutPlanBusy, setCheckoutPlanBusy] = useState<
        'trial' | 'sprint' | 'pro' | null
    >(null);
    const checkoutBusy = checkoutPlanBusy !== null;

    function submitCheckoutPlan(plan: 'trial' | 'sprint' | 'pro'): void {
        checkoutForm.clearErrors();
        checkoutForm.setData({
            plan,
            billing_cycle: plan === 'pro' ? recurringPlanCycle : 'monthly',
        });
        checkoutForm.post(OnboardingController.checkout.url(), {
            preserveScroll: true,
            onStart: () => setCheckoutPlanBusy(plan),
            onFinish: () => setCheckoutPlanBusy(null),
        });
    }

    useEffect(() => {
        setStep(initialStep);
    }, [initialStep]);

    const currentStep = STEPS[step - 1];

    const billingComplete = checkoutVerified || hasActiveSubscription || billingSkipped;

    const hasServerPlanSelection = Boolean(selectedBilling.plan);
    const serverBillingCycle = selectedBilling.billing_cycle;
    const trialSelected = selectedBilling.plan === 'trial';
    const sprintSelected = selectedBilling.plan === 'sprint';
    const proSelected =
        selectedBilling.plan === 'pro' &&
        recurringPlanCycle === serverBillingCycle;
    const anyPlanCardMatches =
        trialSelected || sprintSelected || proSelected;
    const dimUnselectedPlans =
        (hasServerPlanSelection || billingComplete) && anyPlanCardMatches;

    useEffect(() => {
        if (
            selectedBilling.plan === 'pro' &&
            (selectedBilling.billing_cycle === 'monthly' ||
                selectedBilling.billing_cycle === 'annual')
        ) {
            setRecurringPlanCycle(selectedBilling.billing_cycle);
        }
    }, [selectedBilling.plan, selectedBilling.billing_cycle]);

    useEffect(() => {
        if (step !== 4 || billingComplete) {
            return undefined;
        }

        const id = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            router.reload({
                only: [
                    'checkoutVerified',
                    'hasActiveSubscription',
                    'responses',
                    'selectedBilling',
                    'initialStep',
                ],
            });
        }, 3000);

        return () => window.clearInterval(id);
    }, [step, billingComplete]);

    const reportRow = responses['report.no_cap_markdown'];
    const reportHydrateKey = JSON.stringify([
        reportRow?.answer_text ?? '',
        reportRow?.answer_json ?? null,
    ]);
    const serverNoCapParsed = useMemo(
        () => parseNoCapReport(reportRow?.answer_json),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- keyed by serialized row
        [reportHydrateKey],
    );

    const noCapLegacyOnly =
        Boolean(reportRow?.answer_text?.trim()) && !serverNoCapParsed;

    const { start: startStep3ResponsesPoll, stop: stopStep3ResponsesPoll } =
        usePoll(
            1000,
            { only: ['responses'] },
            { autoStart: false },
        );

    useEffect(() => {
        const shouldPollStep3Responses =
            step === 3 &&
            !serverNoCapParsed &&
            !noCapReport &&
            !noCapLegacyOnly &&
            noCapError === null;

        if (!shouldPollStep3Responses) {
            stopStep3ResponsesPoll();

            return undefined;
        }

        startStep3ResponsesPoll();

        return () => stopStep3ResponsesPoll();
    }, [
        step,
        serverNoCapParsed,
        noCapReport,
        noCapLegacyOnly,
        noCapError,
        startStep3ResponsesPoll,
        stopStep3ResponsesPoll,
    ]);

    const profileWordCount = countWords(profileText);
    const profileOverWordLimit = profileWordCount > maxProfileWords;

    useEffect(() => {
        if (step !== 3) {
            return undefined;
        }

        if (serverNoCapParsed) {
            setNoCapReport(serverNoCapParsed);
            setNoCapFinished(true);
            setNoCapLoading(false);
            setNoCapError(null);

            return undefined;
        }

        if (noCapLegacyOnly) {
            setNoCapReport(null);
            setNoCapFinished(true);
            setNoCapLoading(false);
            setNoCapError(null);

            return undefined;
        }

        let cancelled = false;
        let timeoutId: number | undefined;
        let message15sId: number | undefined;
        let message30sId: number | undefined;

        setNoCapReport(null);
        setNoCapError(null);
        setNoCapFinished(false);
        setNoCapLoading(true);
        setNoCapLoadingMessage(
            'Generating your read… results appear here as soon as they are ready.',
        );

        message15sId = window.setTimeout(() => {
            if (!cancelled) {
                setNoCapLoadingMessage(
                    'Hang tight — analyzing your profile...',
                );
            }
        }, 15000);

        message30sId = window.setTimeout(() => {
            if (!cancelled) {
                setNoCapLoadingMessage(
                    'Almost there — this usually takes under a minute...',
                );
            }
        }, 30000);

        timeoutId = window.setTimeout(() => {
            if (!cancelled) {
                setNoCapError(
                    'This is taking longer than usual. Your analysis is still running — refresh in a moment or retry now.',
                );
                setNoCapFinished(true);
                setNoCapLoading(false);
            }
        }, 60000);

        void (async () => {
            try {
                if (!csrfToken) {
                    throw new Error('Missing session token. Refresh the page.');
                }

                const res = await fetch(
                    OnboardingController.admissionsRead.url(),
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: '{}',
                    },
                );

                const data = (await res.json()) as {
                    message?: string;
                    report?: unknown;
                };

                if (!res.ok) {
                    throw new Error('SERVER_ERROR');
                }

                const fromApi = parseNoCapReport({ structured: data.report });

                if (!fromApi) {
                    throw new Error('SERVER_ERROR');
                }

                if (!cancelled) {
                    if (timeoutId !== undefined) {
                        window.clearTimeout(timeoutId);
                    }
                    if (message15sId !== undefined) {
                        window.clearTimeout(message15sId);
                    }
                    if (message30sId !== undefined) {
                        window.clearTimeout(message30sId);
                    }
                    setNoCapReport(fromApi);
                    setNoCapFinished(true);
                    setNoCapLoading(false);
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            router.reload({
                                only: ['responses'],
                            });
                        });
                    });
                }
            } catch {
                if (!cancelled) {
                    if (timeoutId !== undefined) {
                        window.clearTimeout(timeoutId);
                    }
                    if (message15sId !== undefined) {
                        window.clearTimeout(message15sId);
                    }
                    if (message30sId !== undefined) {
                        window.clearTimeout(message30sId);
                    }
                    setNoCapError('Server error. Please try again.');
                    setNoCapFinished(true);
                    setNoCapLoading(false);
                }
            }
        })();

        return () => {
            cancelled = true;
            if (timeoutId !== undefined) {
                window.clearTimeout(timeoutId);
            }
            if (message15sId !== undefined) {
                window.clearTimeout(message15sId);
            }
            if (message30sId !== undefined) {
                window.clearTimeout(message30sId);
            }
        };
    }, [
        step,
        reportHydrateKey,
        noCapStreamKey,
        csrfToken,
        serverNoCapParsed,
        noCapLegacyOnly,
    ]);

    const postSaveStep = (data: Record<string, string | number | boolean>) => {
        setStepProcessing(true);
        router.post(OnboardingController.saveStep.url(), data, {
            preserveScroll: true,
            onFinish: () => setStepProcessing(false),
        });
    };

    const handleNext = () => {
        if (step === 1) {
            postSaveStep({ step: 1 });

            return;
        }

        if (step === 2) {
            const trimmed = profileText.trim();

            if (!trimmed) {
                setProfileClientError(
                    'Please paste or type your profile first.',
                );

                return;
            }

            if (profileOverWordLimit) {
                setProfileClientError(
                    `Please stay within ${maxProfileWords.toLocaleString()} words.`,
                );

                return;
            }

            setProfileClientError(null);
            postSaveStep({ step: 2, profile_text: trimmed });

            return;
        }

        if (step === 3) {
            postSaveStep({ step: 3, report_acknowledged: true });

            return;
        }

        if (step === 4) {
            postSaveStep({ step: 4 });
        }
    };

    const handleBack = () => {
        if (step > 1) {
            const targetStep = step - 1;
            setStep(targetStep);
            
            const stepKeyMap: Record<number, number> = {
                1: 1,
                2: 2,
                3: 3,
                4: 4,
                5: 5,
            };
            const serverStepKey = stepKeyMap[targetStep];
            if (serverStepKey !== undefined) {
                router.post(
                    OnboardingController.saveStep.url(),
                    { step: serverStepKey - 1 },
                    {
                        preserveScroll: true,
                        only: [],
                    },
                );
            }
        }
    };

    const ageSubmitReady = isValidPersistedAgeRange(ageRange) && legalAccepted;

    return (
        <>
            <Head title="Onboarding" />
            {checkoutCancelled ? (
                <p
                    className="bg-destructive/10 px-4 py-2 text-center text-sm text-destructive"
                    role="status"
                >
                    Checkout was cancelled. Choose a plan when you are ready.
                </p>
            ) : null}
            <Dialog open={needsAgeGate} onOpenChange={() => {}}>
                <DialogContent
                    showCloseButton={false}
                    onEscapeKeyDown={(event) => event.preventDefault()}
                    onPointerDownOutside={(event) => event.preventDefault()}
                    className="sm:max-w-lg"
                >
                    <DialogHeader>
                        <DialogTitle>Age confirmation required</DialogTitle>
                        <DialogDescription>
                            AdmitMe is not available for users under 13. Please
                            confirm your age before continuing onboarding. (Date
                            of birth for strict verification will be added
                            later.)
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        action={OnboardingController.saveStep.url()}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="contents"
                    >
                        {({ processing }) => (
                            <>
                                <input type="hidden" name="step" value="0" />
                                <input
                                    type="hidden"
                                    name="age_range"
                                    value={
                                        isValidPersistedAgeRange(ageRange)
                                            ? ageRange
                                            : ''
                                    }
                                />
                                {legalAccepted ? (
                                    <input
                                        type="hidden"
                                        name="legal_accepted"
                                        value="1"
                                    />
                                ) : null}

                                <div className="space-y-4">
                                    <div className="grid gap-2">
                                        <label className="block text-sm font-medium text-foreground">
                                            Select your age range
                                        </label>
                                        <Select
                                            value={
                                                ageRange === ''
                                                    ? undefined
                                                    : ageRange
                                            }
                                            onValueChange={(value) =>
                                                setAgeRange(
                                                    value as AgeRangeValue,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Choose age range" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="13_14">
                                                    13–14
                                                </SelectItem>
                                                <SelectItem value="15_16">
                                                    15–16
                                                </SelectItem>
                                                <SelectItem value="17_18">
                                                    17–18
                                                </SelectItem>
                                                <SelectItem value="19plus">
                                                    19 or older
                                                </SelectItem>
                                                <SelectItem value="under_13_blocked">
                                                    Under 13
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            className="text-xs"
                                            message={errors.age_range}
                                        />
                                    </div>

                                    {ageRange === 'under_13_blocked' && (
                                        <p className="text-xs text-destructive">
                                            You must be at least 13 years old to
                                            use AdmitMe.
                                        </p>
                                    )}

                                    <div className="grid gap-2">
                                        <label className="flex items-start gap-2 text-sm text-muted-foreground">
                                            <input
                                                type="checkbox"
                                                className="mt-0.5 h-4 w-4 rounded border-input"
                                                checked={legalAccepted}
                                                onChange={(event) =>
                                                    setLegalAccepted(
                                                        event.target.checked,
                                                    )
                                                }
                                            />
                                            <span>
                                                I confirm I meet the age
                                                requirement above and agree to
                                                the{' '}
                                                <a
                                                    href="/privacy-policy"
                                                    className="underline underline-offset-4 hover:text-foreground"
                                                >
                                                    Privacy Policy
                                                </a>{' '}
                                                and{' '}
                                                <a
                                                    href="/terms"
                                                    className="underline underline-offset-4 hover:text-foreground"
                                                >
                                                    Terms of Service
                                                </a>
                                                .
                                            </span>
                                        </label>
                                        <InputError
                                            className="text-xs"
                                            message={errors.legal_accepted}
                                        />
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={!ageSubmitReady || processing}
                                    >
                                        Confirm and continue
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

            <div className="flex min-h-svh w-full flex-col items-center justify-start px-4 py-4 sm:justify-center sm:px-6 sm:py-8 lg:px-8">
                {/* Content Container — flex-1 + mt-auto nav = bottom bar only when content is short (mobile) */}
                <div className="flex w-full max-w-2xl flex-1 flex-col sm:flex-none">
                    {/* Header with Logo */}
                    <motion.div
                        className="mb-6 sm:mb-10 md:mb-10"
                        key={step}
                        initial={{ opacity: 0, y: -10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.4, ease: 'easeOut' }}
                    >
                        <div className="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-gradient-to-br from-foreground/10 to-foreground/5 sm:mb-6">
                            <AppLogoIcon className="h-8 w-8 object-contain" />
                        </div>
                        <div>
                            <h1 className="font-serif text-xl leading-tight font-medium tracking-normal text-foreground italic sm:text-2xl sm:tracking-wider">
                                {currentStep.title}
                            </h1>
                            <p className="mt-3 max-w-lg text-base text-muted-foreground sm:text-base">
                                {currentStep.description}
                            </p>
                        </div>
                    </motion.div>

                    {/* Step Content */}
                    <Card className="mb-8 rounded-xl border-none bg-transparent shadow-none ring-3 ring-primary-foreground/30 md:p-2">
                        <AnimatePresence mode="wait">
                            <motion.div
                                className="space-y-6"
                                key={step}
                                initial={{ opacity: 0, x: 20 }}
                                animate={{ opacity: 1, x: 0 }}
                                exit={{ opacity: 0, x: -20 }}
                                transition={{
                                    duration: 0.35,
                                    ease: 'easeInOut',
                                }}
                            >
                                {/* Icon Display */}

                                {/* Step-specific Content */}
                                {step === 1 && (
                                    <div className="space-y-4 text-center">
                                        <p className="text-muted-foreground">
                                            Get a fast admissions read before
                                            you invest time in the wrong
                                            priorities.
                                        </p>
                                        <div className="grid grid-cols-1 gap-4 pt-2 sm:grid-cols-3">
                                            {[
                                                'Analyzes academics, activities, and positioning',
                                                'Benchmarks you against admitted students',
                                                'Builds your step-by-step application plan',
                                            ].map((item) => (
                                                <div
                                                    key={item}
                                                    className="rounded-lg border border-muted bg-muted/50 p-3 text-left text-sm font-medium"
                                                >
                                                    {item}
                                                </div>
                                            ))}
                                        </div>

                                        <div className="rounded-lg border border-muted bg-muted/30 p-4 text-left">
                                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Application Snapshot
                                            </p>
                                            <p className="mt-2 text-sm font-medium text-foreground/80 blur-[5px] select-none">
                                                Admissions Readiness: 62/100
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground select-none">
                                                Committee risk: profile impact
                                                is not yet differentiated for
                                                target schools. Mentor
                                                recommendation: tighten
                                                narrative, quantify impact, and
                                                improve school-fit signal.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {step === 2 && (
                                    <div className="space-y-5">
                                        <div>
                                            <label className="mb-3 block text-sm font-medium text-foreground">
                                                Paste what you have
                                            </label>
                                            <textarea
                                                value={profileText}
                                                onChange={(e) => {
                                                    setProfileText(
                                                        e.target.value,
                                                    );
                                                    setProfileClientError(null);
                                                }}
                                                maxLength={80000}
                                                placeholder="GPA, classes, activities, leadership, awards, goals... just dump everything here."
                                                className="min-h-32 w-full resize-none rounded-lg border border-input bg-background p-4 text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-accent focus:ring-offset-2 focus:ring-offset-background focus:outline-none"
                                            />
                                            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    Word limit{' '}
                                                    <span className="font-medium text-foreground">
                                                        {maxProfileWords.toLocaleString()}
                                                    </span>{' '}
                                                    words
                                                </span>
                                                <span
                                                    className={
                                                        profileOverWordLimit
                                                            ? 'font-medium text-destructive'
                                                            : ''
                                                    }
                                                >
                                                    {profileWordCount.toLocaleString()}{' '}
                                                    /{' '}
                                                    {maxProfileWords.toLocaleString()}
                                                </span>
                                            </div>
                                            <InputError
                                                message={
                                                    errors.profile_text ??
                                                    profileClientError ??
                                                    undefined
                                                }
                                            />
                                        </div>
                                        <p className="text-center text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            or
                                        </p>
                                        <div className="relative rounded-lg border-2 border-dashed border-muted bg-muted/20 p-6 text-center">
                                            <span className="mb-2 inline-flex rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                                Coming soon
                                            </span>
                                            <Upload className="mx-auto mb-2 h-5 w-5 text-muted-foreground/50" />
                                            <p className="text-sm font-medium text-muted-foreground">
                                                Upload your profile (resume,
                                                doc, or notes)
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                File upload is not available
                                                yet. Paste your content above
                                                for now.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {step === 3 && (
                                    <div className="space-y-3">
                                        <div className="rounded-lg border border-border/40 bg-muted/20 px-3 py-3 sm:px-4 sm:py-4">
                                            {noCapLoading && !noCapReport ? (
                                                <div className="space-y-3">
                                                    <p className="text-center text-xs text-muted-foreground sm:text-sm">
                                                        {noCapLoadingMessage}
                                                    </p>
                                                    <div className="flex items-start gap-3 sm:gap-4">
                                                        <Skeleton className="size-[7.5rem] shrink-0 rounded-full sm:size-36" />
                                                        <div className="min-w-0 flex-1 space-y-2 pt-1">
                                                            <Skeleton className="h-3 max-w-xs w-[82%]" />
                                                            <Skeleton className="h-3 max-w-[10rem] w-[58%]" />
                                                            <div className="flex flex-wrap gap-1.5 pt-1">
                                                                <Skeleton className="h-5 w-16 rounded-full" />
                                                                <Skeleton className="h-5 w-20 rounded-full" />
                                                                <Skeleton className="h-5 w-14 rounded-full" />
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            ) : null}

                                            {noCapError ? (
                                                <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
                                                    <p className="font-medium">
                                                        Could not generate read
                                                    </p>
                                                    <p className="mt-1 text-xs">
                                                        {noCapError}
                                                    </p>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="mt-3"
                                                        onClick={() => {
                                                            setNoCapError(null);
                                                            setNoCapReport(
                                                                null,
                                                            );
                                                            setNoCapLoadingMessage(
                                                                'Generating your read… results appear here as soon as they are ready.',
                                                            );
                                                            setNoCapStreamKey(
                                                                (k) => k + 1,
                                                            );
                                                        }}
                                                    >
                                                        Retry
                                                    </Button>
                                                </div>
                                            ) : null}

                                            {!noCapError &&
                                                (noCapReport ||
                                                    noCapLegacyOnly) && (
                                                    <div>
                                                        {noCapReport ? (
                                                            <Tabs
                                                                defaultValue="overview"
                                                                className="w-full"
                                                            >
                                                                <TabsList
                                                                    variant="line"
                                                                    className="mb-3 grid h-9 w-full min-w-0 grid-cols-3 px-0 sm:mb-4"
                                                                >
                                                                    <TabsTrigger
                                                                        value="overview"
                                                                        className="text-xs sm:text-sm"
                                                                    >
                                                                        Overview
                                                                    </TabsTrigger>
                                                                    <TabsTrigger
                                                                        value="details"
                                                                        className="text-xs sm:text-sm"
                                                                    >
                                                                        Signals
                                                                    </TabsTrigger>
                                                                    <TabsTrigger
                                                                        value="takeaways"
                                                                        className="text-xs sm:text-sm"
                                                                    >
                                                                        Next
                                                                        steps
                                                                        <span className="ml-0.5 tabular-nums text-muted-foreground">
                                                                            (
                                                                            {
                                                                                noCapReport
                                                                                    .takeaways
                                                                                    .length
                                                                            }
                                                                            )
                                                                        </span>
                                                                    </TabsTrigger>
                                                                </TabsList>
                                                                <TabsContent
                                                                    value="overview"
                                                                    className="mt-0 space-y-3"
                                                                >
                                                                    <p className="line-clamp-3 text-sm leading-snug font-semibold text-foreground sm:line-clamp-4 sm:text-[0.95rem]">
                                                                        {
                                                                            noCapReport.headline
                                                                        }
                                                                    </p>
                                                                    <ChartContainer
                                                                        config={
                                                                            noCapRadarConfig
                                                                        }
                                                                        className="mx-auto aspect-square w-full max-w-[min(220px,70vw)] max-h-[220px] sm:max-h-[240px]"
                                                                    >
                                                                        <RadarChart
                                                                            data={orderNoCapDimensions(
                                                                                noCapReport.dimensions,
                                                                            ).map(
                                                                                (
                                                                                    d,
                                                                                ) => ({
                                                                                    label: d.label,
                                                                                    score: d.score,
                                                                                }),
                                                                            )}
                                                                        >
                                                                            <ChartTooltip
                                                                                cursor={
                                                                                    false
                                                                                }
                                                                                content={
                                                                                    <ChartTooltipContent />
                                                                                }
                                                                            />
                                                                            <PolarGrid className="stroke-foreground" />
                                                                            <PolarAngleAxis
                                                                                dataKey="label"
                                                                                tick={{
                                                                                    fontSize: 9,
                                                                                    fill: 'hsl(var(--foreground))',
                                                                                }}
                                                                            />
                                                                            <Radar
                                                                                name="Signal"
                                                                                dataKey="score"
                                                                                fill="var(--chart-1)"
                                                                                fillOpacity={
                                                                                    0.45
                                                                                }
                                                                                stroke="var(--chart-1)"
                                                                                strokeWidth={
                                                                                    1.5
                                                                                }
                                                                            />
                                                                        </RadarChart>
                                                                    </ChartContainer>
                                                                    <ul className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                                                                        {orderNoCapDimensions(
                                                                            noCapReport.dimensions,
                                                                        ).map(
                                                                            (
                                                                                d,
                                                                            ) => (
                                                                                <li
                                                                                    key={
                                                                                        d.key
                                                                                    }
                                                                                    className="flex min-w-0 flex-col gap-0.5 rounded-md border border-border/50 bg-background/50 px-2 py-1.5"
                                                                                >
                                                                                    <span className="truncate text-[10px] font-medium text-foreground sm:text-xs">
                                                                                        {
                                                                                            d.label
                                                                                        }
                                                                                    </span>
                                                                                    <span
                                                                                        className={`w-fit rounded-full border px-1.5 py-px text-[9px] font-semibold tracking-wide uppercase sm:text-[10px] ${verdictChipClass(d.verdict)}`}
                                                                                    >
                                                                                        {
                                                                                            d.verdict
                                                                                        }
                                                                                    </span>
                                                                                </li>
                                                                            ),
                                                                        )}
                                                                    </ul>
                                                                </TabsContent>
                                                                <TabsContent
                                                                    value="details"
                                                                    className="mt-0 space-y-3"
                                                                >
                                                                    <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                                                        Insight
                                                                        chips
                                                                    </p>
                                                                    <div className="flex flex-wrap gap-1">
                                                                        {noCapReport.insight_tokens.map(
                                                                            (
                                                                                t,
                                                                                i,
                                                                            ) => (
                                                                                <span
                                                                                    key={`${t.text}-${i}`}
                                                                                    className={`inline-flex max-w-full rounded-full border px-2 py-0.5 text-[10px] leading-tight font-medium sm:text-[11px] ${insightToneClass(t.tone)}`}
                                                                                >
                                                                                    {
                                                                                        t.text
                                                                                    }
                                                                                </span>
                                                                            ),
                                                                        )}
                                                                    </div>
                                                                    <p className="pt-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                                                        By area
                                                                        (full)
                                                                    </p>
                                                                    <ul className="max-h-[min(40vh,16rem)] space-y-1 overflow-y-auto pr-1">
                                                                        {orderNoCapDimensions(
                                                                            noCapReport.dimensions,
                                                                        ).map(
                                                                            (
                                                                                d,
                                                                            ) => (
                                                                                <li
                                                                                    key={
                                                                                        d.key
                                                                                    }
                                                                                    className="flex items-start justify-between gap-2 rounded-md border border-border/60 bg-background/60 px-2 py-1.5 text-xs"
                                                                                >
                                                                                    <span className="min-w-0 font-medium text-foreground">
                                                                                        {
                                                                                            d.label
                                                                                        }
                                                                                    </span>
                                                                                    <span
                                                                                        className={`shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase ${verdictChipClass(d.verdict)}`}
                                                                                    >
                                                                                        {
                                                                                            d.verdict
                                                                                        }
                                                                                    </span>
                                                                                </li>
                                                                            ),
                                                                        )}
                                                                    </ul>
                                                                </TabsContent>
                                                                <TabsContent
                                                                    value="takeaways"
                                                                    className="mt-0"
                                                                >
                                                                    <ul className="max-h-[min(50vh,20rem)] list-inside list-disc space-y-1.5 overflow-y-auto pr-1 text-xs leading-relaxed text-foreground/90 sm:text-sm">
                                                                        {noCapReport.takeaways.map(
                                                                            (
                                                                                line,
                                                                                i,
                                                                            ) => (
                                                                                <li
                                                                                    key={`${line}-${i}`}
                                                                                    className="marker:text-muted-foreground"
                                                                                >
                                                                                    {
                                                                                        line
                                                                                    }
                                                                                </li>
                                                                            ),
                                                                        )}
                                                                    </ul>
                                                                </TabsContent>
                                                            </Tabs>
                                                        ) : (
                                                            <p className="text-xs text-muted-foreground">
                                                                This saved read
                                                                predates the
                                                                profile map.
                                                                Retry to
                                                                regenerate with
                                                                scores and
                                                                chips.
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
                                        </div>
                                    </div>
                                )}

                                {step === 4 && (
                                    <>
                                        <InputError
                                            className="text-center text-sm"
                                            message={errors.billing}
                                        />
                                        <div className="space-y-3">
                                            {trialOnlyMode ? (
                                                <p className="text-center text-xs text-muted-foreground">
                                                    Sprint and Pro are paused
                                                    while we keep costs down —
                                                    the $1 trial includes full
                                                    access.
                                                </p>
                                            ) : null}

                                            {!trialOnlyMode ? (
                                                <div className="flex justify-start">
                                                    <div
                                                        className={cn(
                                                            'inline-flex gap-2 rounded-lg border border-muted bg-muted/30 p-1',
                                                            billingComplete &&
                                                                'pointer-events-none opacity-50',
                                                        )}
                                                    >
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                billingComplete ||
                                                                checkoutBusy
                                                            }
                                                            onClick={() =>
                                                                setRecurringPlanCycle(
                                                                    'monthly',
                                                                )
                                                            }
                                                            className={`rounded-md px-2 py-1 text-sm font-medium transition-all disabled:cursor-not-allowed ${
                                                                recurringPlanCycle ===
                                                                'monthly'
                                                                    ? 'bg-foreground text-background shadow-md'
                                                                    : 'text-muted-foreground hover:text-foreground'
                                                            }`}
                                                        >
                                                            Monthly
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                billingComplete ||
                                                                checkoutBusy
                                                            }
                                                            onClick={() =>
                                                                setRecurringPlanCycle(
                                                                    'annual',
                                                                )
                                                            }
                                                            className={`rounded-md px-2 py-1 text-sm font-medium transition-all disabled:cursor-not-allowed ${
                                                                recurringPlanCycle ===
                                                                'annual'
                                                                    ? 'bg-foreground text-background shadow-md'
                                                                    : 'text-muted-foreground hover:text-foreground'
                                                            }`}
                                                        >
                                                            Annual
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : null}

                                            <div
                                                className={cn(
                                                    'grid gap-2',
                                                    !trialOnlyMode &&
                                                        'md:grid-cols-2',
                                                )}
                                            >
                                                {/* Trial Plan - Now Featured */}
                                                <div
                                                    className={cn(
                                                        'relative flex flex-col rounded-xl border-2 border-emerald-500/30 bg-gradient-to-br from-emerald-600 to-emerald-700 p-2.5 text-white shadow-lg transition-all hover:shadow-xl',
                                                        trialSelected &&
                                                            'ring-2 ring-emerald-400 ring-offset-2 ring-offset-background',
                                                        dimUnselectedPlans &&
                                                            !trialSelected &&
                                                            'pointer-events-none opacity-45 grayscale select-none',
                                                    )}
                                                >
                                                    <div className="absolute -top-2 right-4 rounded-full bg-emerald-400 px-2 py-0.5 text-[10px] font-bold text-emerald-950 uppercase">
                                                        Best
                                                    </div>
                                                    <div className="mb-2 flex-1">
                                                        <p className="text-sm font-semibold">
                                                            $1 Trial • 3 days full access
                                                        </p>
                                                        <p className="mt-1 text-lg font-bold">
                                                            $1
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        className="w-full bg-emerald-400 font-semibold text-emerald-950 transition-colors hover:bg-emerald-300 disabled:opacity-60"
                                                        disabled={
                                                            billingComplete ||
                                                            checkoutBusy ||
                                                            (dimUnselectedPlans &&
                                                                !trialSelected)
                                                        }
                                                        onClick={() =>
                                                            submitCheckoutPlan(
                                                                'trial',
                                                            )
                                                        }
                                                    >
                                                        {checkoutPlanBusy ===
                                                        'trial' ? (
                                                            <>
                                                                <CircleNotch
                                                                    className="mr-2 inline size-4 animate-spin"
                                                                    aria-hidden
                                                                />
                                                                Redirecting to
                                                                checkout...
                                                            </>
                                                        ) : billingComplete &&
                                                          trialSelected ? (
                                                            'Selected plan'
                                                        ) : (
                                                            'Start $1 Trial'
                                                        )}
                                                    </Button>
                                                </div>

                                                {!trialOnlyMode ? (
                                                    <>
                                                        {/* Sprint Plan - Now Secondary */}
                                                        <div
                                                    className={cn(
                                                        'relative flex flex-col rounded-xl border border-muted bg-muted/30 p-2.5 transition-all hover:border-muted-foreground/30 hover:bg-muted/50',
                                                        sprintSelected &&
                                                            'ring-2 ring-primary/50 ring-offset-2 ring-offset-background',
                                                        dimUnselectedPlans &&
                                                            !sprintSelected &&
                                                            'pointer-events-none opacity-45 grayscale select-none',
                                                    )}
                                                >
                                                    <div className="absolute -top-2 right-4 rounded-full bg-muted px-2 py-0.5 text-[9px] font-medium text-muted-foreground uppercase">
                                                        Popular
                                                    </div>
                                                    <div className="mb-2 flex-1">
                                                        <p className="text-sm font-semibold text-foreground">
                                                            Sprint • 90 days
                                                        </p>
                                                        <p className="mt-1 text-lg font-bold text-foreground">
                                                            $29
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        className="mt-6 w-full"
                                                        variant="outline"
                                                        disabled={
                                                            billingComplete ||
                                                            checkoutBusy ||
                                                            (dimUnselectedPlans &&
                                                                !sprintSelected)
                                                        }
                                                        onClick={() =>
                                                            submitCheckoutPlan(
                                                                'sprint',
                                                            )
                                                        }
                                                    >
                                                        {checkoutPlanBusy ===
                                                        'sprint' ? (
                                                            <>
                                                                <CircleNotch
                                                                    className="mr-2 inline size-4 animate-spin"
                                                                    aria-hidden
                                                                />
                                                                Redirecting to
                                                                checkout...
                                                            </>
                                                        ) : billingComplete &&
                                                          sprintSelected ? (
                                                            'Selected'
                                                        ) : (
                                                            'Select Sprint'
                                                        )}
                                                    </Button>
                                                </div>

                                                {/* Pro Plan */}
                                                <div
                                                    className={cn(
                                                        'flex flex-col rounded-xl border border-muted bg-muted/30 p-2.5 transition-all hover:border-muted-foreground/30 hover:bg-muted/50',
                                                        proSelected &&
                                                            'ring-2 ring-primary/50 ring-offset-2 ring-offset-background',
                                                        dimUnselectedPlans &&
                                                            !proSelected &&
                                                            'pointer-events-none opacity-45 grayscale select-none',
                                                    )}
                                                >
                                                    <div className="mb-2 flex-1">
                                                        <p className="text-sm font-semibold text-foreground">
                                                            Pro • Unlimited
                                                        </p>
                                                        <p className="mt-1 text-lg font-bold text-foreground">
                                                            $
                                                            {recurringPlanCycle ===
                                                            'monthly'
                                                                ? '15'
                                                                : '150'}
                                                            <span className="text-xs font-normal text-muted-foreground">
                                                                {recurringPlanCycle ===
                                                                'monthly'
                                                                    ? '/mo'
                                                                    : '/yr'}
                                                            </span>
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        className="w-full"
                                                        variant="outline"
                                                        disabled={
                                                            billingComplete ||
                                                            checkoutBusy ||
                                                            (dimUnselectedPlans &&
                                                                !proSelected)
                                                        }
                                                        onClick={() =>
                                                            submitCheckoutPlan(
                                                                'pro',
                                                            )
                                                        }
                                                    >
                                                        {checkoutPlanBusy ===
                                                        'pro' ? (
                                                            <>
                                                                <CircleNotch
                                                                    className="mr-2 inline size-4 animate-spin"
                                                                    aria-hidden
                                                                />
                                                                Redirecting to
                                                                checkout...
                                                            </>
                                                        ) : billingComplete &&
                                                          proSelected ? (
                                                            'Selected'
                                                        ) : (
                                                            'Select Pro'
                                                        )}
                                                    </Button>
                                                        </div>
                                                    </>
                                                ) : null}
                                            </div>
                                            <InputError
                                                className="text-center text-sm"
                                                message={
                                                    errors.plan ??
                                                    checkoutForm.errors.plan
                                                }
                                            />
                                            {!billingComplete ? (
                                                <Form
                                                    action="/onboarding/skip-billing"
                                                    method="post"
                                                    options={{ preserveScroll: true }}
                                                    className="text-center"
                                                >
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-xs text-muted-foreground"
                                                    >
                                                        Skip for now
                                                    </Button>
                                                </Form>
                                            ) : null}
                                        </div>
                                    </>
                                )}

                                {step === 5 && (
                                    <div className="space-y-4 text-center">
                                        <div className="space-y-4 rounded-lg border border-muted bg-muted/30 p-6">
                                            <div className="inline-flex h-12 w-12 items-center justify-center rounded-full border border-foreground/20 bg-foreground/10">
                                                <ListChecksIcon className="h-6 w-6 text-foreground" />
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium text-foreground">
                                                    Your profile baseline is
                                                    ready.
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    This is where your journey
                                                    begins. Track improvements,
                                                    fix gaps, and build an
                                                    application that stands out.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </motion.div>
                        </AnimatePresence>
                    </Card>

                    {/* Navigation — not fixed: sticks to viewport bottom when content is short; follows content when long (scroll) */}
                    <div className="mt-auto px-0 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:pt-0 sm:pb-0">
                                        {step === 4 ? (
                                            <p className="mb-2 text-center text-sm text-muted-foreground sm:hidden">
                                                {billingComplete
                                                    ? 'Tap Continue for the next step.'
                                                    : 'Pick a plan, skip for now, or complete checkout in Stripe.'}
                                            </p>
                                        ) : null}
                        <div className="flex items-center justify-between gap-2 sm:gap-4">
                            <Button
                                variant="outline"
                                size="lg"
                                onClick={handleBack}
                                disabled={step === 1 || needsAgeGate}
                                className="w-1/2 sm:w-auto"
                            >
                                <CaretLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>

                            {step === 4 ? (
                                <p className="hidden flex-1 text-center text-sm text-muted-foreground sm:block sm:text-right">
                                    {billingComplete
                                        ? 'Continue when you are ready.'
                                        : 'Use a plan card above, or skip billing for now.'}
                                </p>
                            ) : null}
                            {step < STEPS.length ? (
                                <Button
                                    onClick={handleNext}
                                    size="lg"
                                    disabled={
                                        (step === 3 &&
                                            (noCapLoading || !noCapFinished)) ||
                                        stepProcessing ||
                                        needsAgeGate
                                    }
                                    className="w-1/2 sm:w-auto"
                                >
                                    {step === 1
                                        ? 'Analyze My Application'
                                        : 'Continue'}
                                    <CaretRight className="ml-2 h-4 w-4" />
                                </Button>
                            ) : (
                                <Form
                                    action={OnboardingController.complete.url()}
                                    method="post"
                                    options={{ preserveScroll: true }}
                                    className="w-1/2 sm:w-auto"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="lg"
                                            className="w-full sm:w-auto"
                                            disabled={processing || needsAgeGate}
                                        >
                                            Start Your Journey
                                            <RocketLaunch className="ml-2 h-4 w-4" />
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </div>
                    </div>
                    <InputError
                        className="mt-2 text-center"
                        message={errors.onboarding}
                    />

                    {step === 1 && (
                        <p className="mt-4 text-center text-xs text-muted-foreground">
                            Most students overestimate their chances. This shows
                            you the truth.
                        </p>
                    )}

                </div>
            </div>
        </>
    );
}
