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
    WarningCircle,
} from '@phosphor-icons/react';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
import { collegeLists } from '@/routes';
import collegeListsRoutes from '@/routes/college-lists';

type PageProps = {
    colleges: CollegeRow[];
    councilRunId?: string | null;
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
};

function CouncilRunStatusBanner({ runId }: { runId: string }) {
    const [line, setLine] = useState<string>('Loading council status…');

    useEffect(() => {
        let cancelled = false;

        async function go(): Promise<void> {
            const res = await fetch(`/college-lists/council-runs/${runId}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok || cancelled) {
                return;
            }
            const j = (await res.json()) as {
                status?: string;
                summary_text?: string | null;
                error_message?: string | null;
            };
            const msg =
                j.status === 'completed' && j.summary_text
                    ? j.summary_text
                    : `Status: ${j.status ?? 'unknown'}`;
            if (!cancelled) {
                setLine(j.error_message ?? msg);
            }
        }

        void go();
        const id = window.setInterval(() => void go(), 5000);

        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, [runId]);

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
    const { colleges, councilRunId, college_list_council } =
        usePage<PageProps>().props;
    const councilEnabled = college_list_council?.enabled === true;

    const [layout, setLayout] = useState<'table' | 'cards'>('table');
    const [mainTab, setMainTab] = useState<'list' | 'deadlines'>('list');
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

    useEffect(() => {
        councilReloadRef.current = false;
    }, [councilRunId]);

    useEffect(() => {
        if (
            typeof councilRunId !== 'string' ||
            councilRunId.length === 0
        ) {
            return;
        }
        let cancelled = false;

        async function poll(): Promise<void> {
            try {
                const res = await fetch(
                    `/college-lists/council-runs/${councilRunId}`,
                    {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );
                if (!res.ok || cancelled) {
                    return;
                }
                const j = (await res.json()) as { status?: string };
                if (
                    (j.status === 'completed' || j.status === 'failed') &&
                    !councilReloadRef.current
                ) {
                    councilReloadRef.current = true;
                    router.reload({ only: ['colleges'] });
                }
            } catch {
                /* ignore */
            }
        }

        void poll();
        const id = window.setInterval(() => void poll(), 4000);

        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, [councilRunId]);

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
            let data: { message?: string; run_id?: string } = {};
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

            <div className="page-shell">
                <header className="mb-4 space-y-1">
                    <h1 className="page-title">College list builder</h1>
                    <p className="page-description">
                        Generate a starter list, track balance and deadlines,
                        then drill into fit, aid, and “why this school” per
                        campus.
                    </p>
                </header>

                {typeof councilRunId === 'string' && councilRunId.length > 0 ? (
                    <CouncilRunStatusBanner runId={councilRunId} />
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
                            <Button
                                type="button"
                                className="h-10 w-full max-w-xs"
                                disabled={generateStatus === 'working'}
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
                                            !councilEnabled
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
                            setMainTab(v as 'list' | 'deadlines')
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
                                            '',
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
                                                <Link
                                                    href={collegeListsRoutes.school.url(
                                                        row.id,
                                                    )}
                                                    className="flex h-8 w-full min-w-12 items-center justify-center rounded-md px-1.5 text-xs text-primary transition-colors hover:bg-muted/60"
                                                >
                                                    Detail
                                                </Link>
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
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="xs"
                                        className="mt-2 h-8 border-border/70 text-xs"
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
                    </Tabs>
                ) : null}
            </div>
        </>
    );
}

CollegeLists.layout = {
    breadcrumbs: [{ title: 'College lists', href: collegeLists.url() }],
};
