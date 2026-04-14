'use client';

import { Head, Link, usePage } from '@inertiajs/react';
import { ChartLine, CheckCircle, Lightning, WarningCircle } from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { PaywallModal } from '@/components/paywall-modal';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import { ProseMarkdown } from '@/components/markdown/prose-markdown';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import type { FeatureQuotasProps, QuotaFeatureDetail } from '@/types/feature-quotas';
import { collegeLists, gapFix } from '@/routes';
import {
    generate as gapFixGenerate,
    whatIfExplain as gapFixWhatIfExplain,
} from '@/routes/gap-fix';

type CollegeSchoolRow = {
    id: string;
    school_name: string;
    tier: string;
    fit_score: number;
    median_sat: number | null;
    median_gpa: string | null;
    median_act: number | null;
    acceptance_rate: string | null;
};

type ReasonSeverity = 'critical' | 'moderate' | 'minor';

type RejectionReason = string | { text: string; severity?: string };

type AnalysisPayload = {
    rejection_risks?: Array<{
        college_list_school_id: string;
        reasons: RejectionReason[];
    }>;
    gaps?: Array<{
        id: string;
        severity: string;
        title: string;
        steps: string[];
    }>;
    disclaimer?: string | null;
};

type LatestAnalysis = {
    id: string;
    selected_school_ids: string[];
    payload: AnalysisPayload;
    is_partial: boolean;
    missing_school_ids: string[];
    created_at: string | null;
};

type PageProps = {
    college_schools: CollegeSchoolRow[];
    latest_analysis: LatestAnalysis | null;
    profile_baseline: {
        sat_score: number | null;
        gpa_normalized_4: number | null;
        test_optional_status: string | null;
    };
    isSubscribed?: boolean;
    feature_quotas?: FeatureQuotasProps | null;
};

const ANALYSIS_STAGES = [
    'Reading your profile',
    'Comparing to school medians',
    'Scoring gaps',
    'Building your roadmap',
] as const;

const WHATIF_KEY_PREFIX = 'admitme:gap-fix-whatif:';

function whatIfStorageKey(
    analysisId: string,
    schoolIds: string[],
    satD: number,
    gpaD: number,
): string {
    return `${WHATIF_KEY_PREFIX}${analysisId}:${[...schoolIds].sort().join(',')}:${satD}:${gpaD}`;
}

function clearWhatIfSessionStorage(): void {
    try {
        for (let i = sessionStorage.length - 1; i >= 0; i--) {
            const k = sessionStorage.key(i);
            if (k?.startsWith(WHATIF_KEY_PREFIX)) {
                sessionStorage.removeItem(k);
            }
        }
    } catch {
        /* ignore */
    }
}

function schoolNameById(schools: CollegeSchoolRow[], id: string): string {
    return schools.find((s) => s.id === id)?.school_name ?? 'School';
}

function schoolRowById(schools: CollegeSchoolRow[], id: string): CollegeSchoolRow | undefined {
    return schools.find((s) => s.id === id);
}

function formatSchoolNamesOxford(ids: string[], schools: CollegeSchoolRow[]): string {
    const names = ids.map((id) => schoolNameById(schools, id));
    if (names.length === 0) {
        return '';
    }
    if (names.length === 1) {
        return names[0];
    }
    if (names.length === 2) {
        return `${names[0]} and ${names[1]}`;
    }

    return `${names.slice(0, -1).join(', ')}, and ${names[names.length - 1]}`;
}

function normalizeRejectionReason(r: RejectionReason): { text: string; severity: ReasonSeverity } {
    if (typeof r === 'string') {
        return { text: r, severity: 'moderate' };
    }
    const sev = r.severity;
    const severity: ReasonSeverity =
        sev === 'critical' || sev === 'moderate' || sev === 'minor' ? sev : 'moderate';

    return { text: r.text, severity };
}

function reasonSeverityLabel(severity: ReasonSeverity): string {
    if (severity === 'critical') {
        return 'Critical';
    }
    if (severity === 'moderate') {
        return 'Moderate';
    }

    return 'Minor';
}

function severityOrder(s: string): number {
    if (s === 'critical') {
        return 0;
    }
    if (s === 'moderate') {
        return 1;
    }

    return 2;
}

export default function GapFixPage() {
    const {
        college_schools,
        latest_analysis: initialLatest,
        profile_baseline,
        isSubscribed = false,
        feature_quotas,
    } = usePage<PageProps>().props;

    const [paywallOpen, setPaywallOpen] = useState(false);
    const [quotaModalOpen, setQuotaModalOpen] = useState(false);
    const [quotaModalDetail, setQuotaModalDetail] = useState<QuotaFeatureDetail | null>(null);
    const hasPaidAccess = isSubscribed === true;

    const [selected, setSelected] = useState<Set<string>>(() => {
        if (initialLatest?.selected_school_ids?.length) {
            return new Set(initialLatest.selected_school_ids);
        }

        return new Set();
    });
    const [latest, setLatest] = useState<LatestAnalysis | null>(initialLatest);
    const [analyzing, setAnalyzing] = useState(false);
    const [stageIdx, setStageIdx] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [throttleMsg, setThrottleMsg] = useState<string | null>(null);
    const [incompleteGenerate, setIncompleteGenerate] = useState(false);
    const [satDelta, setSatDelta] = useState(0);
    const [gpaDelta, setGpaDelta] = useState(0);
    const [whatIfNarrative, setWhatIfNarrative] = useState<string | null>(null);
    const [whatIfBusy, setWhatIfBusy] = useState(false);

    useEffect(() => {
        if (!latest?.id) {
            return;
        }
        const key = whatIfStorageKey(latest.id, Array.from(selected), satDelta, gpaDelta);
        try {
            const stored = sessionStorage.getItem(key);
            if (stored) {
                setWhatIfNarrative(stored);
            } else {
                setWhatIfNarrative(null);
            }
        } catch {
            setWhatIfNarrative(null);
        }
    }, [latest?.id, selected, satDelta, gpaDelta]);

    useEffect(() => {
        if (!analyzing) {
            return;
        }
        const t = window.setInterval(() => {
            setStageIdx((i) => (i + 1) % ANALYSIS_STAGES.length);
        }, 3200);

        return () => window.clearInterval(t);
    }, [analyzing]);

    const selectedList = useMemo(
        () => college_schools.filter((s) => selected.has(s.id)),
        [college_schools, selected],
    );

    const hypoSat = useMemo(() => {
        if (profile_baseline.sat_score == null) {
            return null;
        }

        return Math.max(400, Math.min(1600, profile_baseline.sat_score + satDelta));
    }, [profile_baseline.sat_score, satDelta]);

    const hypoGpa = useMemo(() => {
        if (profile_baseline.gpa_normalized_4 == null) {
            return null;
        }

        return Math.max(0, Math.min(4, Math.round((profile_baseline.gpa_normalized_4 + gpaDelta) * 100) / 100));
    }, [gpaDelta, profile_baseline.gpa_normalized_4]);

    const gapQuota = feature_quotas?.features?.gap_analysis;
    const roadmapQuota = feature_quotas?.features?.fix_roadmap;
    const gapBlocked = gapQuota?.remaining === 0;
    const roadmapBlocked = roadmapQuota?.remaining === 0;

    const toggleSchool = useCallback((id: string) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }, []);

    const runAnalysis = useCallback(async () => {
        setError(null);
        setThrottleMsg(null);
        setIncompleteGenerate(false);
        if (selected.size === 0) {
            setError('Select at least one school from your list.');

            return;
        }

        setAnalyzing(true);
        setStageIdx(0);
        try {
            const res = await fetch(gapFixGenerate.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({
                    college_list_school_ids: Array.from(selected),
                }),
            });
            const raw = await res.text();
            let data: {
                analysis?: LatestAnalysis;
                message?: string;
                errors?: Record<string, string[]>;
                quota?: QuotaFeatureDetail;
            } = {};
            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* ignore */
            }

            if (res.status === 429) {
                setThrottleMsg(data.message ?? 'You have reached your gap analysis limit for your plan.');
                if (data.quota) {
                    setQuotaModalDetail(data.quota);
                    setQuotaModalOpen(true);
                }

                return;
            }

            if (res.status === 422) {
                const analysisErr = data.errors?.analysis?.[0];
                if (typeof analysisErr === 'string' && analysisErr !== '') {
                    setIncompleteGenerate(true);

                    return;
                }
                const first =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : undefined;
                setError(first ?? data.message ?? 'Could not complete analysis.');

                return;
            }

            if (!res.ok) {
                const first =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : undefined;
                setError(first ?? data.message ?? 'Could not complete analysis.');

                return;
            }

            if (data.analysis) {
                clearWhatIfSessionStorage();
                setWhatIfNarrative(null);
                setLatest(data.analysis);
            }
        } catch {
            setError('Network error — try again.');
        } finally {
            setAnalyzing(false);
        }
    }, [selected]);

    const explainWhatIf = useCallback(async () => {
        setWhatIfNarrative(null);
        setError(null);
        setThrottleMsg(null);
        if (selected.size === 0) {
            setError('Select schools to explain a what-if scenario.');

            return;
        }
        setWhatIfBusy(true);
        try {
            const res = await fetch(gapFixWhatIfExplain.url(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({
                    college_list_school_ids: Array.from(selected),
                    sat_delta: satDelta,
                    gpa_delta: gpaDelta,
                }),
            });
            const raw = await res.text();
            let data: {
                narrative?: string;
                message?: string;
                errors?: Record<string, string[]>;
                quota?: QuotaFeatureDetail;
            } = {};
            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* ignore */
            }
            if (res.status === 429) {
                setThrottleMsg(data.message ?? 'You have reached your fix roadmap limit for your plan.');
                if (data.quota) {
                    setQuotaModalDetail(data.quota);
                    setQuotaModalOpen(true);
                }

                return;
            }
            if (!res.ok) {
                const first =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : undefined;
                setError(first ?? data.message ?? 'Could not generate explanation.');

                return;
            }
            if (data.narrative) {
                setWhatIfNarrative(data.narrative);
                try {
                    if (latest?.id) {
                        sessionStorage.setItem(
                            whatIfStorageKey(latest.id, Array.from(selected), satDelta, gpaDelta),
                            data.narrative,
                        );
                    }
                } catch {
                    /* ignore */
                }
            }
        } catch {
            setError('Network error — try again.');
        } finally {
            setWhatIfBusy(false);
        }
    }, [gpaDelta, latest?.id, satDelta, selected]);

    const payload = latest?.payload;
    const gapsSorted = useMemo(() => {
        const g = payload?.gaps ?? [];

        return [...g].sort((a, b) => severityOrder(a.severity) - severityOrder(b.severity));
    }, [payload?.gaps]);

    const missingNames = useMemo(() => {
        if (!latest?.missing_school_ids?.length) {
            return [];
        }

        return latest.missing_school_ids.map((id) => schoolNameById(college_schools, id));
    }, [college_schools, latest?.missing_school_ids]);

    return (
        <>
            <Head title="Gap + Fix" />

            <div className={cn("page-shell relative", !hasPaidAccess && 'pointer-events-none select-none blur-sm')}>
                <header className="mb-4 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <ChartLine className="size-6 text-primary" aria-hidden />
                        <h1 className="text-xl font-semibold tracking-tight text-foreground">
                            Gap + Fix
                        </h1>
                    </div>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Rejection risks per school, a prioritized fix roadmap, and a what-if view
                        against your list — grounded in your saved profile and list data.
                    </p>
                </header>

                {college_schools.length === 0 ? (
                    <Card className="max-w-lg border-dashed">
                        <CardHeader>
                            <CardTitle>No schools on your list</CardTitle>
                            <CardDescription>
                                Add schools on your college lists page, then come back to run a gap analysis
                                against your profile.
                            </CardDescription>
                        </CardHeader>
                        <CardFooter>
                            <Button type="button" asChild>
                                <Link href={collegeLists.url()}>Go to college lists</Link>
                            </Button>
                        </CardFooter>
                    </Card>
                ) : (
                    <div className="space-y-6">
                        <section className="rounded-xl border border-border/60 bg-card/40 p-4">
                            <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                                Schools to analyze
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Choose one or more list schools (up to 12). We compare your profile to
                                each row&apos;s medians, tier, and fit score.
                            </p>
                            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {college_schools.map((s) => {
                                    const on = selected.has(s.id);
                                    return (
                                        <label
                                            key={s.id}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm transition-colors',
                                                on
                                                    ? 'border-primary/40 bg-primary/5'
                                                    : 'border-border/60 bg-background/80 hover:bg-muted/30',
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={on}
                                                onChange={() => toggleSchool(s.id)}
                                                className="mt-0.5"
                                            />
                                            <span>
                                                <span className="font-medium text-foreground">
                                                    {s.school_name}
                                                </span>
                                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                                    {s.tier} · fit {s.fit_score}
                                                    {s.median_sat != null ? ` · SAT ~${s.median_sat}` : ''}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            <div className="mt-4 flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    disabled={analyzing || selected.size === 0 || gapBlocked}
                                    onClick={() => void runAnalysis()}
                                    className="gap-1.5"
                                >
                                    <Lightning className="size-4" weight="bold" />
                                    {analyzing ? 'Analyzing…' : 'Run analysis'}
                                </Button>
                                <span
                                    className={cn(
                                        'text-xs',
                                        gapQuota?.warning ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground',
                                    )}
                                >
                                    {gapQuota && gapQuota.limit != null
                                        ? `${gapQuota.used} of ${gapQuota.limit} gap analyses used${feature_quotas?.window_days ? ` per ${feature_quotas.window_days} days` : ''}.`
                                        : 'Each run uses your current selection.'}
                                </span>
                            </div>
                            {error ? (
                                <p className="mt-2 flex items-center gap-1.5 text-sm text-destructive">
                                    <WarningCircle className="size-4 shrink-0" />
                                    {error}
                                </p>
                            ) : null}
                            {throttleMsg ? (
                                <p className="mt-2 flex items-center gap-1.5 text-sm text-amber-800 dark:text-amber-200">
                                    <WarningCircle className="size-4 shrink-0" />
                                    {throttleMsg}
                                </p>
                            ) : null}
                        </section>

                        {incompleteGenerate ? (
                            <Card className="border-destructive/30 bg-destructive/5" size="sm">
                                <CardHeader>
                                    <CardTitle className="text-base">Analysis did not finish</CardTitle>
                                    <CardDescription>
                                        Analysis failed — the AI returned an incomplete result. Please try
                                        again.
                                    </CardDescription>
                                </CardHeader>
                                <CardFooter className="border-t border-border/60 pt-4">
                                    <Button
                                        type="button"
                                        onClick={() => {
                                            setIncompleteGenerate(false);
                                            void runAnalysis();
                                        }}
                                    >
                                        Regenerate
                                    </Button>
                                </CardFooter>
                            </Card>
                        ) : null}

                        {analyzing ? (
                            <section
                                className="rounded-xl border border-primary/30 bg-primary/5 p-5 shadow-sm"
                                aria-live="polite"
                            >
                                <p className="text-xs font-semibold uppercase tracking-wider text-primary">
                                    Analysis in progress
                                </p>
                                <p className="mt-2 text-base font-medium text-foreground">
                                    Analyzing your profile against{' '}
                                    {formatSchoolNamesOxford(Array.from(selected), college_schools)}
                                    …
                                </p>
                                <ul className="mt-4 space-y-2 text-sm text-muted-foreground">
                                    {ANALYSIS_STAGES.map((line, i) => (
                                        <li
                                            key={line}
                                            className={cn(
                                                'flex items-start gap-2 rounded-md px-2 py-1.5 transition-colors',
                                                i === stageIdx &&
                                                    'bg-background/80 text-foreground ring-1 ring-primary/20',
                                            )}
                                        >
                                            <CheckCircle
                                                className={cn(
                                                    'mt-0.5 size-4 shrink-0',
                                                    i < stageIdx
                                                        ? 'text-primary/70'
                                                        : i === stageIdx
                                                          ? 'text-primary'
                                                          : 'text-muted-foreground/35',
                                                )}
                                                weight={i <= stageIdx ? 'fill' : 'regular'}
                                            />
                                            {line}
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-4 text-xs text-muted-foreground">
                                    This usually takes 10–15 seconds
                                </p>
                            </section>
                        ) : null}

                        {payload && (payload.rejection_risks?.length || gapsSorted.length) ? (
                            <Tabs defaultValue="risks" className="gap-3">
                                {latest?.is_partial ? (
                                    <div
                                        role="status"
                                        className="rounded-lg border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-950 dark:text-amber-50"
                                    >
                                        Partial results — analysis incomplete for:{' '}
                                        {(missingNames.length > 0
                                            ? missingNames.join(', ')
                                            : (latest.missing_school_ids ?? []).join(', ')) || 'some schools'}
                                        . Regenerate or select fewer schools.
                                    </div>
                                ) : null}

                                <TabsList className="h-auto w-full flex-wrap justify-start gap-1 bg-muted/50 p-1 sm:w-auto">
                                    <TabsTrigger value="risks" className="text-sm">
                                        Rejection Risks
                                    </TabsTrigger>
                                    <TabsTrigger value="roadmap" className="text-sm">
                                        Fix Roadmap
                                    </TabsTrigger>
                                    <TabsTrigger value="whatif" className="text-sm">
                                        What-If
                                    </TabsTrigger>
                                </TabsList>

                                <TabsContent value="risks" className="mt-0 outline-none">
                                    <div className="space-y-4">
                                        {payload.disclaimer ? (
                                            <p className="text-xs text-muted-foreground">{payload.disclaimer}</p>
                                        ) : null}
                                        {(payload.rejection_risks ?? []).map((row) => {
                                            const rowSchool = schoolRowById(
                                                college_schools,
                                                row.college_list_school_id,
                                            );
                                            const tier = rowSchool?.tier ?? '—';

                                            return (
                                                <Card key={row.college_list_school_id} size="sm">
                                                    <CardHeader className="pb-2">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <CardTitle className="text-base leading-snug">
                                                                {schoolNameById(
                                                                    college_schools,
                                                                    row.college_list_school_id,
                                                                )}
                                                            </CardTitle>
                                                            <Badge
                                                                variant="outline"
                                                                className={cn(
                                                                    'capitalize',
                                                                    tier === 'reach' &&
                                                                        'border-destructive/40 bg-destructive/10 text-destructive',
                                                                    tier === 'match' &&
                                                                        'border-amber-500/45 bg-amber-500/10 text-amber-950 dark:text-amber-100',
                                                                    tier === 'safety' &&
                                                                        'border-emerald-500/40 bg-emerald-500/10 text-emerald-950 dark:text-emerald-100',
                                                                )}
                                                            >
                                                                {tier}
                                                            </Badge>
                                                        </div>
                                                    </CardHeader>
                                                    <CardContent className="pt-0">
                                                        <ol className="list-decimal space-y-3 pl-5 marker:font-medium">
                                                            {row.reasons.map((raw, i) => {
                                                                const { text, severity } =
                                                                    normalizeRejectionReason(raw);

                                                                return (
                                                                    <li
                                                                        key={i}
                                                                        className="text-sm text-foreground"
                                                                    >
                                                                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                                                                            <span className="min-w-0 leading-relaxed">
                                                                                {text}
                                                                            </span>
                                                                            <span
                                                                                className={cn(
                                                                                    'shrink-0 rounded-md border px-2 py-0.5 text-xs font-semibold',
                                                                                    severity === 'critical' &&
                                                                                        'border-destructive/50 bg-destructive/15 text-destructive',
                                                                                    severity === 'moderate' &&
                                                                                        'border-amber-500/45 bg-amber-500/10 text-amber-950 dark:text-amber-100',
                                                                                    severity === 'minor' &&
                                                                                        'border-border/60 bg-muted/50 text-muted-foreground',
                                                                                )}
                                                                            >
                                                                                {reasonSeverityLabel(severity)}
                                                                            </span>
                                                                        </div>
                                                                    </li>
                                                                );
                                                            })}
                                                        </ol>
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
                                    </div>
                                </TabsContent>

                                <TabsContent value="roadmap" className="mt-0 outline-none">
                                    <div className="space-y-4">
                                        {gapsSorted.map((g) => (
                                            <Card key={g.id} size="sm">
                                                <CardHeader className="pb-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span
                                                            className={cn(
                                                                'rounded-md border px-2 py-0.5 text-xs font-semibold uppercase tracking-wide',
                                                                g.severity === 'critical' &&
                                                                    'border-destructive/40 bg-destructive/10 text-destructive',
                                                                g.severity === 'moderate' &&
                                                                    'border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-100',
                                                                g.severity === 'minor' &&
                                                                    'border-border/60 bg-muted/40 text-muted-foreground',
                                                            )}
                                                        >
                                                            {g.severity}
                                                        </span>
                                                        <CardTitle className="text-base leading-snug">
                                                            {g.title}
                                                        </CardTitle>
                                                    </div>
                                                </CardHeader>
                                                <CardContent className="pt-0">
                                                    <ol className="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                                                        {g.steps.map((step, i) => (
                                                            <li key={i}>{step}</li>
                                                        ))}
                                                    </ol>
                                                </CardContent>
                                            </Card>
                                        ))}
                                    </div>
                                </TabsContent>

                                <TabsContent value="whatif" className="mt-0 outline-none">
                                    <div className="space-y-5">
                                        <div className="space-y-4 rounded-xl border border-border/60 bg-card/40 p-4">
                                            <h3 className="text-sm font-semibold text-foreground">
                                                Hypothetical changes
                                            </h3>
                                            <div>
                                                <label className="text-xs font-medium text-muted-foreground">
                                                    SAT delta ({satDelta >= 0 ? '+' : ''}
                                                    {satDelta} pts)
                                                </label>
                                                <input
                                                    type="range"
                                                    min={-200}
                                                    max={200}
                                                    step={1}
                                                    value={satDelta}
                                                    onChange={(e) => setSatDelta(Number(e.target.value))}
                                                    className="mt-1 block w-full"
                                                />
                                            </div>
                                            <div>
                                                <label className="text-xs font-medium text-muted-foreground">
                                                    GPA (4.0 scale) delta ({gpaDelta >= 0 ? '+' : ''}
                                                    {gpaDelta.toFixed(2)})
                                                </label>
                                                <input
                                                    type="range"
                                                    min={-50}
                                                    max={50}
                                                    step={5}
                                                    value={Math.round(gpaDelta * 100)}
                                                    onChange={(e) =>
                                                        setGpaDelta(Number(e.target.value) / 100)
                                                    }
                                                    className="mt-1 block w-full"
                                                />
                                            </div>
                                            <dl className="grid gap-2 text-sm">
                                                <div className="flex justify-between gap-2">
                                                    <dt className="text-muted-foreground">Baseline SAT</dt>
                                                    <dd className="font-medium tabular-nums text-foreground">
                                                        {profile_baseline.sat_score ?? '—'}
                                                    </dd>
                                                </div>
                                                <div className="flex justify-between gap-2">
                                                    <dt className="text-muted-foreground">Hypothetical SAT</dt>
                                                    <dd className="font-medium tabular-nums text-foreground">
                                                        {hypoSat ?? '—'}
                                                    </dd>
                                                </div>
                                                <div className="flex justify-between gap-2">
                                                    <dt className="text-muted-foreground">Baseline GPA (4.0)</dt>
                                                    <dd className="font-medium tabular-nums text-foreground">
                                                        {profile_baseline.gpa_normalized_4 ?? '—'}
                                                    </dd>
                                                </div>
                                                <div className="flex justify-between gap-2">
                                                    <dt className="text-muted-foreground">Hypothetical GPA</dt>
                                                    <dd className="font-medium tabular-nums text-foreground">
                                                        {hypoGpa ?? '—'}
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>

                                        <div className="space-y-3">
                                            <h3 className="text-sm font-semibold text-foreground">
                                                Median comparison (selected schools)
                                            </h3>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                {Array.from(selected).map((id) => {
                                                    const s = schoolRowById(college_schools, id);
                                                    if (!s) {
                                                        return null;
                                                    }

                                                    let satLine =
                                                        'SAT: median data unavailable for this comparison.';
                                                    if (hypoSat != null && s.median_sat != null) {
                                                        satLine =
                                                            hypoSat >= s.median_sat
                                                                ? 'SAT: Now within SAT median range'
                                                                : 'SAT: Still below median';
                                                    } else if (hypoSat == null) {
                                                        satLine =
                                                            'SAT: add a baseline SAT in your profile to compare.';
                                                    }

                                                    let gpaLine =
                                                        'GPA: median data unavailable for this comparison.';
                                                    if (hypoGpa != null && s.median_gpa != null) {
                                                        const med = Number.parseFloat(s.median_gpa);
                                                        if (!Number.isNaN(med)) {
                                                            gpaLine =
                                                                hypoGpa >= med
                                                                    ? 'GPA: Now within GPA median range'
                                                                    : 'GPA: Still below median';
                                                        }
                                                    } else if (hypoGpa == null) {
                                                        gpaLine =
                                                            'GPA: add a baseline GPA in your profile to compare.';
                                                    }

                                                    return (
                                                        <Card key={s.id} size="sm">
                                                            <CardHeader className="pb-2">
                                                                <CardTitle className="text-sm font-semibold">
                                                                    {s.school_name}
                                                                </CardTitle>
                                                            </CardHeader>
                                                            <CardContent className="space-y-2 pt-0 text-sm text-muted-foreground">
                                                                <p>{satLine}</p>
                                                                <p>{gpaLine}</p>
                                                            </CardContent>
                                                        </Card>
                                                    );
                                                })}
                                            </div>
                                        </div>

                                        <div className="space-y-3">
                                            <Button
                                                type="button"
                                                variant="secondary"
                                                disabled={whatIfBusy || selected.size === 0 || roadmapBlocked}
                                                onClick={() => void explainWhatIf()}
                                            >
                                                {whatIfBusy ? 'Explaining…' : 'Explain impact'}
                                            </Button>
                                            {roadmapQuota && roadmapQuota.limit != null ? (
                                                <p
                                                    className={cn(
                                                        'text-xs',
                                                        roadmapQuota.warning
                                                            ? 'text-amber-700 dark:text-amber-300'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {roadmapQuota.used} of {roadmapQuota.limit} fix roadmap runs used
                                                    {feature_quotas?.window_days
                                                        ? ` per ${feature_quotas.window_days} days`
                                                        : ''}
                                                    .
                                                </p>
                                            ) : null}
                                            {whatIfNarrative ? (
                                                <div className="rounded-xl border border-border/60 bg-muted/10 p-4">
                                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                                        Impact narrative
                                                    </p>
                                                    <ProseMarkdown content={whatIfNarrative} />
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                </TabsContent>
                            </Tabs>
                        ) : (
                            !analyzing && (
                                <p className="text-sm text-muted-foreground">
                                    Run an analysis to see rejection risks, your roadmap, and the what-if
                                    tools. Previous runs stay on this page until you generate a new one.
                                </p>
                            )
                        )}
                    </div>
                )}
                
                {!hasPaidAccess ? (
                    <div className="absolute inset-0 flex items-center justify-center bg-background/55 p-4 supports-backdrop-filter:backdrop-blur-[2px]">
                        <Card className="pointer-events-auto z-40 w-full max-w-md border-border/80 shadow-lg">
                            <CardHeader>
                                <CardTitle>Gap fix is a Pro feature</CardTitle>
                                <CardDescription>
                                    Get your step-by-step rejection fix roadmap and what-if simulator
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

GapFixPage.layout = {
    breadcrumbs: [
        { title: 'Gap + Fix', href: gapFix.url() },
    ],
};
