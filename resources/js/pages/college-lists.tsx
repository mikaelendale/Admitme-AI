'use client';

import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarBlank,
    Coin,
    CurrencyDollar,
    LinkSimple,
    MapPin,
    Scales,
    Sparkle,
    SquaresFour,
    Table as TableIcon,
    Trash,
    WarningCircle,
} from '@phosphor-icons/react';
import { PaywallModal } from '@/components/paywall-modal';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { createLaravelEcho, type CouncilBroadcastPayload } from '@/lib/laravelEcho';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import {
    type CollegeRow,
    type FitTier,
    fitScoreStyles,
    tierLabel,
    tierStyles,
} from '@/lib/college-list-display';
import { cn } from '@/lib/utils';
import type { CouncilFeedLine } from '@/types/council';
import type { FeatureQuotasProps, QuotaFeatureDetail } from '@/types/feature-quotas';
import { collegeLists } from '@/routes';
import collegeListsRoutes from '@/routes/college-lists';

type CouncilRunHistoryRow = {
    id: string;
    status: string;
    summary_text: string | null;
    completed_at: string | null;
    school_count: number;
};

type PageProps = {
    colleges: CollegeRow[];
    councilRunId?: string | null;
    councilRunHistory?: CouncilRunHistoryRow[];
    college_list_council?: {
        enabled: boolean;
        reverb: {
            key: string;
            wsHost: string;
            wsPort: number;
            wssPort: number;
            scheme: string;
        };
    } | null;
    isSubscribed?: boolean;
    feature_quotas?: FeatureQuotasProps | null;
};

function CouncilRunStatusBanner({ line }: { line: string }) {
    return (
        <div className="mb-4 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2 text-sm text-foreground">
            <span className="font-semibold">Council run </span>
            <span className="text-muted-foreground">{line}</span>
        </div>
    );
}

function countByTier(rows: CollegeRow[]): Record<FitTier, number> {
    return rows.reduce(
        (acc, r) => {
            acc[r.tier] += 1;

            return acc;
        },
        { reach: 0, match: 0, safety: 0 },
    );
}

function listBalanceWarning(rows: CollegeRow[]): string | null {
    const c = countByTier(rows);
    const n = rows.length;
    if (n === 0) {
        return null;
    }
    if (c.safety === 0) {
        return 'No safeties on this list — add at least one realistic admit.';
    }
    if (c.reach > Math.ceil(n * 0.45)) {
        return 'Reach-heavy — add matches and safeties so the list is executable.';
    }
    if (c.match === 0) {
        return 'No matches — most students need a strong middle band.';
    }
    if (c.reach === 0) {
        return 'No reaches — optional: one stretch keeps ambition honest.';
    }

    return null;
}

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
            <h3 className="ui-eyebrow">{title}</h3>
            <div className="mt-2">{children}</div>
        </div>
    );
}

export default function CollegeLists() {
    const {
        colleges,
        councilRunId,
        councilRunHistory = [],
        college_list_council,
        isSubscribed = false,
        feature_quotas,
    } = usePage<PageProps>().props;
    const councilEnabled = college_list_council?.enabled === true;
    
    const [paywallOpen, setPaywallOpen] = useState(false);
    const [quotaModalOpen, setQuotaModalOpen] = useState(false);
    const [quotaModalDetail, setQuotaModalDetail] = useState<QuotaFeatureDetail | null>(null);
    const hasPaidAccess = isSubscribed === true;

    const councilQuota = feature_quotas?.features?.college_list;
    const councilBlocked = councilQuota?.remaining === 0;

    const [layout, setLayout] = useState<'table' | 'cards'>('table');
    const [mainTab, setMainTab] = useState<'list' | 'deadlines' | 'history'>('list');
    const [generatorPrompt, setGeneratorPrompt] = useState('');
    const [generateStatus, setGenerateStatus] = useState<
        'idle' | 'working' | 'done'
    >('idle');
    const [generateError, setGenerateError] = useState<string | null>(null);
    const [additionalContext, setAdditionalContext] = useState('');
    const [buildIntent, setBuildIntent] = useState<'generate' | 'refine'>(
        'refine',
    );
    const councilReloadRef = useRef(false);
    const echoRef = useRef<ReturnType<typeof createLaravelEcho>>(null);
    const [councilBannerLine, setCouncilBannerLine] = useState('Loading council status…');
    const [councilFeedLines, setCouncilFeedLines] = useState<CouncilFeedLine[]>([]);
    const [removingId, setRemovingId] = useState<string | null>(null);
    const [addSchoolOpen, setAddSchoolOpen] = useState(false);
    const [manualName, setManualName] = useState('');
    const [manualTier, setManualTier] = useState<'reach' | 'match' | 'safety'>('match');
    const [manualNotes, setManualNotes] = useState('');
    const [manualSaving, setManualSaving] = useState(false);

    useEffect(() => {
        councilReloadRef.current = false;
    }, [councilRunId]);

    useEffect(() => {
        if (typeof councilRunId !== 'string' || councilRunId.length === 0) {
            return;
        }
        let cancelled = false;
        let intervalId: number | null = null;

        async function tick(): Promise<void> {
            try {
                const res = await fetch(`/college-lists/council-runs/${councilRunId}`, {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!res.ok || cancelled) {
                    return;
                }
                const j = (await res.json()) as {
                    status?: string;
                    summary_text?: string | null;
                    error_message?: string | null;
                    events?: Array<{
                        id: string;
                        phase: string;
                        severity: string;
                        title: string;
                        body: string | null;
                    }>;
                };
                const msg =
                    j.status === 'completed' && j.summary_text
                        ? j.summary_text
                        : `Status: ${j.status ?? 'unknown'}`;
                setCouncilBannerLine(j.error_message ?? msg);

                if (Array.isArray(j.events) && j.events.length > 0) {
                    setCouncilFeedLines(
                        j.events.map((ev) => ({
                            id: ev.id,
                            type: 'poll',
                            title: ev.title,
                            body: ev.body ?? undefined,
                            severity: ev.severity,
                        })),
                    );
                }

                if (
                    (j.status === 'completed' || j.status === 'failed') &&
                    !councilReloadRef.current
                ) {
                    councilReloadRef.current = true;
                    if (intervalId !== null) {
                        window.clearInterval(intervalId);
                        intervalId = null;
                    }
                    router.visit(collegeLists.url(), {
                        preserveScroll: true,
                        only: ['colleges', 'councilRunId', 'councilRunHistory'],
                    });
                }
            } catch {
                /* ignore */
            }
        }

        void tick();
        intervalId = window.setInterval(() => void tick(), 4000);

        return () => {
            cancelled = true;
            if (intervalId !== null) {
                window.clearInterval(intervalId);
            }
        };
    }, [councilRunId]);

    useEffect(() => {
        if (typeof councilRunId !== 'string' || councilRunId.length === 0) {
            return;
        }
        if (!college_list_council?.enabled || !college_list_council.reverb?.key) {
            return;
        }
        const csrf = readCsrfTokenFromMeta();
        if (!csrf) {
            return;
        }
        if (!echoRef.current) {
            echoRef.current = createLaravelEcho(csrf, college_list_council.reverb);
        }
        const echo = echoRef.current;
        if (!echo) {
            return;
        }

        const channel = echo.private(`college-list-runs.${councilRunId}`);
        const onBroadcast = (e: CouncilBroadcastPayload): void => {
            const { type, data } = e;
            if (type === 'insight') {
                const title = String(data.title ?? '');
                if (!title) {
                    return;
                }
                setCouncilFeedLines((prev) => [
                    ...prev,
                    {
                        id: crypto.randomUUID(),
                        type: 'insight',
                        title,
                        body: data.body ? String(data.body) : undefined,
                        severity: typeof data.severity === 'string' ? data.severity : 'info',
                    },
                ]);
            }
            if (type === 'status' && typeof data.status === 'string') {
                setCouncilBannerLine(`Status: ${data.status}`);
            }
            if (type === 'completed' || type === 'failed') {
                if (!councilReloadRef.current) {
                    councilReloadRef.current = true;
                    router.visit(collegeLists.url(), {
                        preserveScroll: true,
                        only: ['colleges', 'councilRunId', 'councilRunHistory'],
                    });
                }
            }
        };
        channel.listen('.council.broadcast', onBroadcast);

        return () => {
            channel.stopListening('.council.broadcast');
        };
    }, [councilRunId, college_list_council]);

    const balanceWarning = useMemo(
        () => listBalanceWarning(colleges),
        [colleges],
    );
    const tierCounts = useMemo(() => countByTier(colleges), [colleges]);

    const deadlineTracker = useMemo(() => {
        return [...colleges]
            .sort((a, b) => a.deadlineSort - b.deadlineSort)
            .map((r) => ({
                id: r.id,
                name: r.name,
                deadline: r.deadline,
                deadlinesDisplay: r.deadlinesDisplay,
                tier: r.tier,
            }));
    }, [colleges]);

    const aggregateNetLens = useMemo(() => {
        const n = colleges.length;

        return `Across ${n} schools, net estimates span wide bands by need and residency — open a school for the estimator line.`;
    }, [colleges]);

    const hasSchools = colleges.length > 0;

    const runActive =
        typeof councilRunId === 'string' && councilRunId.length > 0;

    const removeSchool = async (id: string) => {
        if (runActive) {
            return;
        }
        setRemovingId(id);
        try {
            const res = await fetch(`/college-lists/${id}`, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
            });
            if (res.ok) {
                router.reload({ only: ['colleges', 'councilRunHistory'] });
            }
        } finally {
            setRemovingId(null);
        }
    };

    const saveManualSchool = async () => {
        const name = manualName.trim();
        if (name.length < 2) {
            return;
        }
        setManualSaving(true);
        try {
            const res = await fetch('/college-lists/schools/manual', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({
                    school_name: name,
                    tier: manualTier,
                    student_notes: manualNotes.trim() === '' ? null : manualNotes.trim(),
                }),
            });
            if (res.ok) {
                setAddSchoolOpen(false);
                setManualName('');
                setManualNotes('');
                setManualTier('match');
                router.reload({ only: ['colleges', 'councilRunHistory'] });
            }
        } finally {
            setManualSaving(false);
        }
    };

    const handleGenerate = async () => {
        const msg = generatorPrompt.trim();
        if (msg === '') {
            setGenerateError(
                'Add a short prompt: major, region, budget, and any constraints.',
            );

            return;
        }
        if (!councilEnabled) {
            setGenerateError(
                'The list builder is not available here. Use the dashboard mentor chat to generate a list.',
            );

            return;
        }

        setGenerateError(null);
        setGenerateStatus('working');

        const extra = additionalContext.trim();
        const intent = hasSchools ? buildIntent : 'generate';
        let fullMessage = msg;
        if (extra !== '') {
            fullMessage += `\n\n---\nExtra facts for this run (use for precision; may not be in saved profile):\n${extra}`;
        }

        try {
            const res = await fetch('/college-lists/council-runs', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({ message: fullMessage, intent }),
            });

            const raw = await res.text();
            let data: { message?: string; run_id?: string; quota?: QuotaFeatureDetail } = {};
            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* non-JSON body */
            }

            if (res.status === 409) {
                setGenerateError(
                    data.message ?? 'A list build is already in progress.',
                );
                setGenerateStatus('idle');

                return;
            }

            if (res.status === 429) {
                setGenerateError(
                    data.message ?? 'You have reached your college list generation limit for this period.',
                );
                if (data.quota) {
                    setQuotaModalDetail(data.quota);
                    setQuotaModalOpen(true);
                }
                setGenerateStatus('idle');

                return;
            }

            if (!res.ok) {
                setGenerateError(
                    typeof data.message === 'string'
                        ? data.message
                        : 'Could not start the list builder.',
                );
                setGenerateStatus('idle');

                return;
            }

            const runId = data.run_id;
            if (!runId) {
                setGenerateError('Invalid response from server.');
                setGenerateStatus('idle');

                return;
            }

            setGenerateStatus('done');
            router.visit(
                `${collegeLists.url()}?run=${encodeURIComponent(runId)}`,
                { preserveScroll: true },
            );
        } catch {
            setGenerateError('Network error — try again.');
            setGenerateStatus('idle');
        }
    };

    const generatorTextareaClass =
        'w-full resize-none rounded-md border border-border/70 bg-background px-3 py-2 text-sm text-foreground outline-none placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring';

    return (
        <>
            <Head title="College list builder" />

            <div className={cn("page-shell relative", !hasPaidAccess && 'pointer-events-none select-none blur-sm')}>
                <header className="mb-4 space-y-1">
                    <h1 className="page-title">College list builder</h1>
                    <p className="page-description">
                        Generate a starter list, track balance and deadlines,
                        then drill into fit, aid, and “why this school” per
                        campus.
                    </p>
                </header>

                {runActive ? (
                    <>
                        <CouncilRunStatusBanner line={councilBannerLine} />
                        {councilFeedLines.length > 0 ? (
                            <div className="mb-4 max-h-48 overflow-y-auto rounded-lg border border-border/60 bg-muted/20 p-3 text-xs">
                                <p className="mb-2 font-semibold text-foreground">Live council feed</p>
                                <ul className="space-y-2">
                                    {councilFeedLines.map((line) => (
                                        <li key={line.id} className="flex gap-2">
                                            <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                                            <div>
                                                <div className="font-medium text-foreground">{line.title}</div>
                                                {line.body ? (
                                                    <div className="text-muted-foreground">{line.body}</div>
                                                ) : null}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                    </>
                ) : null}

                {!hasSchools ? (
                    <section className="mb-8 rounded-2xl border border-border/60 bg-gradient-to-b from-primary/5 to-transparent px-4 py-8 sm:px-8">
                        <div className="mx-auto max-w-xl space-y-4 text-center">
                            <div className="inline-flex size-12 items-center justify-center rounded-full border border-primary/25 bg-primary/10">
                                <Sparkle
                                    className="size-6 text-primary"
                                    aria-hidden
                                />
                            </div>
                            <h2 className="text-lg font-semibold tracking-tight text-foreground">
                                List generator
                            </h2>
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                Describe your major, region, budget, and vibe.
                                We&apos;ll run the council and fill this page
                                with reach, match, and safety picks.
                            </p>
                            <textarea
                                value={generatorPrompt}
                                onChange={(e) =>
                                    setGeneratorPrompt(e.target.value)
                                }
                                placeholder="e.g. CS, West Coast, high aid need, test-optional friendly…"
                                rows={4}
                                className={cn(generatorTextareaClass, 'min-h-24')}
                            />
                            <div className="w-full space-y-1.5 text-left">
                                <label className="text-xs font-medium text-foreground">
                                    Facts for this run (recommended)
                                </label>
                                <p className="text-xs text-muted-foreground">
                                    Add anything not in your profile the AI should
                                    know: exact budget, test plan, citizenship,
                                    fee waivers, gap year, etc. The council may
                                    also ask follow-ups in the live feed.
                                </p>
                                <textarea
                                    value={additionalContext}
                                    onChange={(e) =>
                                        setAdditionalContext(e.target.value)
                                    }
                                    placeholder="e.g. EFC ~12k, need full aid at privates, applying REA to one school only…"
                                    rows={3}
                                    className={cn(
                                        generatorTextareaClass,
                                        'min-h-16',
                                    )}
                                />
                            </div>
                            {generateError ? (
                                <p className="text-left text-sm text-amber-800 dark:text-amber-200">
                                    {generateError}
                                </p>
                            ) : null}
                            {councilQuota && councilQuota.limit != null ? (
                                <p
                                    className={cn(
                                        'text-left text-xs',
                                        councilQuota.warning
                                            ? 'text-amber-700 dark:text-amber-300'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {councilQuota.used} of {councilQuota.limit} list generations used
                                    {feature_quotas?.window_days
                                        ? ` per ${feature_quotas.window_days} days`
                                        : ''}
                                    .
                                </p>
                            ) : null}
                            <Button
                                type="button"
                                className="h-10 w-full max-w-xs"
                                disabled={generateStatus === 'working' || councilBlocked}
                                onClick={() => void handleGenerate()}
                            >
                                {generateStatus === 'working'
                                    ? 'Starting council…'
                                    : 'Generate my list'}
                            </Button>
                            {!councilEnabled ? (
                                <p className="text-xs text-muted-foreground">
                                    Tip: enable the college list council or ask
                                    the dashboard mentor to start a build for
                                    you.
                                </p>
                            ) : null}
                        </div>
                    </section>
                ) : (
                    <>
                        <section className="mb-4 overflow-hidden rounded-xl border border-border/60 bg-muted/20">
                            <div className="border-b border-border/50 px-3 py-2">
                                <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                                    List intelligence
                                </p>
                            </div>
                            <div className="grid grid-cols-1 divide-y divide-border/50 md:grid-cols-2 md:divide-x md:divide-y-0">
                                <div className="flex flex-col gap-2 p-3">
                                    <div className="flex items-center gap-1.5 text-xs font-medium text-foreground">
                                        <Sparkle
                                            className="size-3.5 text-primary"
                                            aria-hidden
                                        />
                                        Regenerate or refine
                                    </div>
                                    <p className="text-sm leading-snug text-muted-foreground">
                                        Choose whether to keep and refine your
                                        current schools or replace the whole list.
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            size="xs"
                                            variant={
                                                buildIntent === 'refine'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="h-8 text-xs"
                                            onClick={() =>
                                                setBuildIntent('refine')
                                            }
                                        >
                                            Edit existing list
                                        </Button>
                                        <Button
                                            type="button"
                                            size="xs"
                                            variant={
                                                buildIntent === 'generate'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="h-8 text-xs"
                                            onClick={() =>
                                                setBuildIntent('generate')
                                            }
                                        >
                                            Replace with new list
                                        </Button>
                                    </div>
                                    <textarea
                                        value={generatorPrompt}
                                        onChange={(e) =>
                                            setGeneratorPrompt(e.target.value)
                                        }
                                        placeholder="e.g. Add more safeties in-state…"
                                        rows={2}
                                        className={cn(
                                            generatorTextareaClass,
                                            'min-h-0',
                                        )}
                                    />
                                    <label className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                        Extra facts (optional)
                                    </label>
                                    <textarea
                                        value={additionalContext}
                                        onChange={(e) =>
                                            setAdditionalContext(e.target.value)
                                        }
                                        placeholder="Budget, tests, deadlines constraints…"
                                        rows={2}
                                        className={cn(
                                            generatorTextareaClass,
                                            'min-h-0',
                                        )}
                                    />
                                    {generateError ? (
                                        <p className="text-xs text-amber-800 dark:text-amber-200">
                                            {generateError}
                                        </p>
                                    ) : null}
                                    <Button
                                        type="button"
                                        size="xs"
                                        className="h-8 w-fit px-2 text-xs"
                                        disabled={
                                            generateStatus === 'working' ||
                                            !councilEnabled ||
                                            councilBlocked
                                        }
                                        onClick={() => void handleGenerate()}
                                    >
                                        {generateStatus === 'working'
                                            ? 'Working…'
                                            : 'Run council'}
                                    </Button>
                                </div>
                                <div className="flex flex-col gap-2 p-3">
                                    <div className="flex items-center gap-1.5 text-xs font-medium text-foreground">
                                        <Coin
                                            className="size-3.5 text-muted-foreground"
                                            aria-hidden
                                        />
                                        Net cost lens
                                    </div>
                                    <p className="text-sm leading-snug text-muted-foreground">
                                        {aggregateNetLens}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <div
                            className={cn(
                                'mb-4 rounded-lg border px-3 py-3',
                                balanceWarning
                                    ? 'border-amber-500/35 bg-amber-500/10'
                                    : 'border-border/60 bg-muted/30',
                            )}
                        >
                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                <span className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                                    <Scales className="size-3.5" aria-hidden />
                                    List balancer
                                </span>
                                {balanceWarning ? (
                                    <span className="inline-flex items-center gap-1 rounded border border-amber-500/40 bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-900 dark:text-amber-100">
                                        <WarningCircle
                                            className="size-3 shrink-0"
                                            aria-hidden
                                        />
                                        Review suggested
                                    </span>
                                ) : null}
                            </div>
                            <div className="mb-2 flex flex-wrap gap-1.5">
                                {(
                                    [
                                        ['reach', tierCounts.reach],
                                        ['match', tierCounts.match],
                                        ['safety', tierCounts.safety],
                                    ] as const
                                ).map(([tier, count]) => (
                                    <span
                                        key={tier}
                                        className={cn(
                                            'rounded-md border px-2 py-0.5 text-xs font-medium capitalize',
                                            tierStyles(tier),
                                            'border-border/40',
                                        )}
                                    >
                                        {tierLabel(tier)} · {count}
                                    </span>
                                ))}
                            </div>
                            <p
                                className={cn(
                                    'text-sm leading-snug',
                                    balanceWarning
                                        ? 'text-amber-900 dark:text-amber-100'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {balanceWarning ??
                                    'Reach / match / safety mix looks workable for a first pass.'}
                            </p>
                        </div>
                    </>
                )}

                {hasSchools ? (
                    <Tabs
                        value={mainTab}
                        onValueChange={(v) =>
                            setMainTab(v as 'list' | 'deadlines' | 'history')
                        }
                        className="gap-4"
                    >
                        <TabsList className="h-9 w-full max-w-md bg-muted/50 p-1">
                            <TabsTrigger
                                value="list"
                                className="flex-1 text-xs"
                            >
                                List
                            </TabsTrigger>
                            <TabsTrigger
                                value="deadlines"
                                className="flex-1 text-xs"
                            >
                                Deadlines
                            </TabsTrigger>
                            <TabsTrigger
                                value="history"
                                className="flex-1 text-xs"
                            >
                                History
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="list" className="mt-0 outline-none">
                            <Tabs
                                value={layout}
                                onValueChange={(v) =>
                                    setLayout(v as 'table' | 'cards')
                                }
                                className="gap-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                                        List display
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {hasSchools && !runActive ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="xs"
                                                className="h-8 text-xs"
                                                onClick={() => setAddSchoolOpen(true)}
                                            >
                                                Add school
                                            </Button>
                                        ) : null}
                                    <TabsList className="h-8 gap-0.5 bg-muted/50 p-0.5">
                                        <TabsTrigger
                                            value="table"
                                            className="size-8 rounded-md p-0"
                                        >
                                            <TableIcon
                                                className="size-4"
                                                weight="bold"
                                                aria-label="Table"
                                            />
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="cards"
                                            className="size-8 rounded-md p-0"
                                        >
                                            <SquaresFour
                                                className="size-4"
                                                weight="bold"
                                                aria-label="Cards"
                                            />
                                        </TabsTrigger>
                                    </TabsList>
                                    </div>
                                </div>

                                <TabsContent
                                    value="table"
                                    className="mt-0 outline-none"
                                >
                        <div className="max-h-[min(70vh,720px)] overflow-auto rounded-lg border border-border/60 scrollbar-none">
                            <table className="w-full min-w-[720px] border-collapse text-left">
                                <thead>
                                    <tr>
                                        {[
                                            'School',
                                            'Fit',
                                            'Tier',
                                            'Apply',
                                            'Deadlines',
                                            'Aid (est.)',
                                            'Actions',
                                        ].map((h) => (
                                            <th
                                                key={h || 'a'}
                                                className="sticky top-0 z-10 border-b border-border/60 bg-muted/80 px-2 py-1.5 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground backdrop-blur-xs"
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {colleges.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="px-3 py-8 text-center text-xs text-muted-foreground"
                                            >
                                                No schools saved yet. Add schools
                                                from the dashboard chat or
                                                onboarding.
                                            </td>
                                        </tr>
                                    ) : (
                                    colleges.map((row, index) => (
                                        <tr
                                            key={row.id}
                                            className={cn(
                                                'border-b border-border/40 transition-colors hover:bg-muted/20',
                                                index % 2 === 1 &&
                                                    'bg-muted/10',
                                            )}
                                        >
                                            <td className="px-2 py-1.5 align-middle">
                                                <div className="text-xs font-medium leading-tight text-foreground">
                                                    {row.name}
                                                </div>
                                                <div className="mt-0.5 text-xs leading-tight text-muted-foreground">
                                                    {row.location}
                                                </div>
                                            </td>
                                            <td className="px-2 py-1.5 align-middle text-xs tabular-nums">
                                                <span className="font-semibold text-foreground">
                                                    {row.fitScore}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    /100
                                                </span>
                                            </td>
                                            <td className="px-2 py-1.5 align-middle">
                                                <span
                                                    className={cn(
                                                        'inline-block rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                        tierStyles(row.tier),
                                                        'border-border/40',
                                                    )}
                                                >
                                                    {tierLabel(row.tier)}
                                                </span>
                                            </td>
                                            <td className="max-w-28 px-2 py-1.5 align-middle text-xs">
                                                {row.applicationPortalUrl ? (
                                                    <a
                                                        href={
                                                            row.applicationPortalUrl
                                                        }
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-primary underline-offset-2 hover:underline"
                                                    >
                                                        Portal
                                                    </a>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="max-w-52 px-2 py-1.5 align-middle text-xs leading-snug text-foreground whitespace-pre-line">
                                                {row.deadlinesDisplay?.trim() ||
                                                    row.deadline ||
                                                    '—'}
                                            </td>
                                            <td className="max-w-56 px-2 py-1.5 align-middle text-sm leading-snug text-muted-foreground">
                                                {row.aidEstimate}
                                            </td>
                                            <td className="px-1 py-1 align-middle">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Link
                                                        href={collegeListsRoutes.school.url(
                                                            row.id,
                                                        )}
                                                        className="flex h-8 min-w-12 items-center justify-center rounded-md px-1.5 text-xs text-primary transition-colors hover:bg-muted/60"
                                                    >
                                                        Detail
                                                    </Link>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                                        disabled={runActive || removingId === row.id}
                                                        title="Remove from list"
                                                        onClick={() => void removeSchool(row.id)}
                                                    >
                                                        {removingId === row.id ? (
                                                            <span className="text-[10px]">…</span>
                                                        ) : (
                                                            <Trash className="size-4" aria-hidden />
                                                        )}
                                                    </Button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </TabsContent>

                    <TabsContent value="cards" className="mt-0 outline-none">
                        <div className="grid gap-2 xs:grid-cols-2 xl:grid-cols-3">
                            {colleges.length === 0 ? (
                                <p className="col-span-full py-8 text-center text-xs text-muted-foreground">
                                    No schools saved yet.
                                </p>
                            ) : (
                            colleges.map((row) => (
                                <article
                                    key={row.id}
                                    className="flex flex-col rounded-lg border border-border/60 bg-card/40 p-3"
                                >
                                    <div className="mb-2 flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <h3 className="text-xs font-semibold leading-snug text-foreground">
                                                {row.name}
                                            </h3>
                                            <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                                <MapPin className="size-3 shrink-0" />
                                                {row.location}
                                            </p>
                                        </div>
                                        <span
                                            className={cn(
                                                'shrink-0 rounded-md border border-border/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                tierStyles(row.tier),
                                            )}
                                        >
                                            {tierLabel(row.tier)}
                                        </span>
                                    </div>
                                    <div className="mb-2 flex items-baseline gap-1 border-y border-border/40 py-2">
                                        <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                            Fit
                                        </span>
                                        <span
                                            className={cn(
                                                'ml-auto text-xl font-semibold tabular-nums',
                                                fitScoreStyles(row.fitScore),
                                            )}
                                        >
                                            {row.fitScore}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            /100
                                        </span>
                                    </div>
                                    <div className="space-y-1.5 text-xs text-muted-foreground">
                                        <div className="flex gap-1.5">
                                            <CalendarBlank className="mt-0.5 size-3.5 shrink-0" />
                                            <span className="whitespace-pre-line">
                                                {row.deadlinesDisplay?.trim() ||
                                                    row.deadline ||
                                                    '—'}
                                            </span>
                                        </div>
                                        {row.applicationPortalUrl ? (
                                            <div className="flex gap-1.5">
                                                <LinkSimple className="mt-0.5 size-3.5 shrink-0" />
                                                <a
                                                    href={row.applicationPortalUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-primary underline-offset-2 hover:underline"
                                                >
                                                    Application portal
                                                </a>
                                            </div>
                                        ) : null}
                                        <div className="flex gap-1.5">
                                            <CurrencyDollar className="mt-0.5 size-3.5 shrink-0" />
                                            <span>{row.netCostEstimate}</span>
                                        </div>
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="xs"
                                            className="h-8 border-border/70 text-xs"
                                            asChild
                                        >
                                            <Link
                                                href={collegeListsRoutes.school.url(
                                                    row.id,
                                                )}
                                            >
                                                School detail
                                            </Link>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="xs"
                                            className="h-8 border-border/70 text-xs text-destructive hover:text-destructive"
                                            disabled={runActive || removingId === row.id}
                                            onClick={() => void removeSchool(row.id)}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </article>
                            ))
                            )}
                        </div>
                    </TabsContent>
                            </Tabs>
                        </TabsContent>

                        <TabsContent
                            value="deadlines"
                            className="mt-0 outline-none"
                        >
                            <Panel title="Deadline tracker">
                                <p className="mb-3 text-xs leading-relaxed text-muted-foreground">
                                    These dates are pulled automatically from each
                                    school on your list (EA/ED/RD or imported
                                    deadlines). You do not enter them manually on
                                    this page.
                                </p>
                                <ul className="divide-y divide-border/40">
                                    {deadlineTracker.map((d) => (
                                        <li
                                            key={d.id}
                                            className="flex flex-wrap items-baseline justify-between gap-2 py-2 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <Link
                                                    href={collegeListsRoutes.school.url(
                                                        d.id,
                                                    )}
                                                    className="text-xs font-medium text-foreground underline-offset-2 hover:underline"
                                                >
                                                    {d.name}
                                                </Link>
                                                <p className="whitespace-pre-line text-xs text-muted-foreground">
                                                    {d.deadlinesDisplay?.trim() ||
                                                        d.deadline ||
                                                        '—'}
                                                </p>
                                            </div>
                                            <span
                                                className={cn(
                                                    'shrink-0 rounded border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                    tierStyles(d.tier),
                                                    'border-border/40',
                                                )}
                                            >
                                                {tierLabel(d.tier)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </Panel>
                        </TabsContent>

                        <TabsContent value="history" className="mt-0 outline-none">
                            <Panel title="Recent council builds">
                                {councilRunHistory.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Completed list builds will appear here.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-border/40">
                                        {councilRunHistory.map((row) => (
                                            <li
                                                key={row.id}
                                                className="flex flex-wrap items-start justify-between gap-2 py-3 first:pt-0 last:pb-0"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-xs font-medium text-foreground">
                                                        {row.completed_at
                                                            ? new Date(row.completed_at).toLocaleString()
                                                            : '—'}
                                                        <span
                                                            className={cn(
                                                                'ml-2 rounded border px-1.5 py-0.5 text-[10px] uppercase',
                                                                row.status === 'completed'
                                                                    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-950 dark:text-emerald-100'
                                                                    : 'border-destructive/40 bg-destructive/10 text-destructive',
                                                            )}
                                                        >
                                                            {row.status}
                                                        </span>
                                                    </p>
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {row.summary_text ?? '—'}
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        Schools linked to this run: {row.school_count}
                                                    </p>
                                                </div>
                                                <Button type="button" variant="outline" size="xs" className="shrink-0" asChild>
                                                    <Link
                                                        href={`${collegeLists.url()}?run=${encodeURIComponent(row.id)}`}
                                                    >
                                                        View run
                                                    </Link>
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Panel>
                        </TabsContent>
                    </Tabs>
                ) : null}

                <Dialog open={addSchoolOpen} onOpenChange={setAddSchoolOpen}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Add school manually</DialogTitle>
                        </DialogHeader>
                        <div className="grid gap-3 py-2">
                            <div>
                                <Label htmlFor="manual-school-name">School name</Label>
                                <Input
                                    id="manual-school-name"
                                    value={manualName}
                                    onChange={(e) => setManualName(e.target.value)}
                                    placeholder="e.g. State University"
                                    maxLength={100}
                                />
                            </div>
                            <div>
                                <Label>Tier</Label>
                                <Select
                                    value={manualTier}
                                    onValueChange={(v) =>
                                        setManualTier(v as 'reach' | 'match' | 'safety')
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="reach">Reach</SelectItem>
                                        <SelectItem value="match">Match</SelectItem>
                                        <SelectItem value="safety">Safety</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="manual-notes">Notes (optional)</Label>
                                <textarea
                                    id="manual-notes"
                                    value={manualNotes}
                                    onChange={(e) => setManualNotes(e.target.value)}
                                    rows={2}
                                    className="flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    maxLength={1000}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAddSchoolOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                disabled={manualSaving || manualName.trim().length < 2}
                                onClick={() => void saveManualSchool()}
                            >
                                {manualSaving ? 'Saving…' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                
                {!hasPaidAccess ? (
                    <div className="absolute inset-0 z-30 flex items-center justify-center bg-black/55 p-4 supports-backdrop-filter:backdrop-blur-[2px]">
                        <Card className="pointer-events-auto w-full max-w-md border-border/80 shadow-lg">
                            <CardHeader>
                                <CardTitle>College list is a Pro feature</CardTitle>
                                <CardDescription>
                                    Get personalized reach/match/safety recommendations and deadline tracking
                                </CardDescription>
                            </CardHeader>
                            <CardFooter className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                                <Button
                                    type="button"
                                    className="w-full sm:w-auto"
                                    onClick={() => setPaywallOpen(true)}
                                >
                                    Unlock now
                                </Button>
                            </CardFooter>
                        </Card>
                    </div>
                ) : null}
                
                <PaywallModal open={paywallOpen} onOpenChange={setPaywallOpen} />
                <QuotaExhaustedModal
                    open={quotaModalOpen}
                    onOpenChange={setQuotaModalOpen}
                    quota={quotaModalDetail}
                    plan={feature_quotas?.plan}
                />
            </div>
        </>
    );
}

CollegeLists.layout = {
    breadcrumbs: [{ title: 'College lists', href: collegeLists.url() }],
};
