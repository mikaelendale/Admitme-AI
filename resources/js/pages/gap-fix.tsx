'use client';

import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Buildings,
    ChartLine,
    Check,
    CheckCircle,
    Circle,
    CircleNotch,
    Lightning,
    MinusCircle,
    Scales,
    Sparkle,
    WarningCircle,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    Notice,
    PlanOverlay,
    demoteHeadings,
    proseDocClass,
    sentenceCase,
    toolCardClass,
} from '@/components/essay-suite/ui';
import { ProseMarkdown } from '@/components/markdown/prose-markdown';
import {
    EmptyState,
    IconTile,
    KeyValueList,
    KeyValueRow,
    Page,
    PageHeader,
    Section,
    SegmentedTabs,
    StatusBadge,
} from '@/components/page/kit';
import type { BadgeTone } from '@/components/page/kit';
import { PaywallModal } from '@/components/paywall-modal';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import { collegeLists, gapFix } from '@/routes';
import {
    generate as gapFixGenerate,
    whatIfExplain as gapFixWhatIfExplain,
} from '@/routes/gap-fix';
import type {
    FeatureQuotasProps,
    QuotaFeatureDetail,
} from '@/types/feature-quotas';

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

type ResultTab = 'risks' | 'roadmap' | 'whatif';

type Comparison = { state: 'within' | 'below' | 'unknown'; text: string };

const ANALYSIS_STAGES = [
    'Reading your profile',
    'Comparing to school medians',
    'Scoring gaps',
    'Building your roadmap',
] as const;

const WHATIF_KEY_PREFIX = 'admitme:gap-fix-whatif:';

const SEVERITY_STYLE: Record<string, { dot: string; text: string }> = {
    critical: { dot: 'bg-destructive', text: 'text-destructive' },
    moderate: {
        dot: 'bg-amber-500',
        text: 'text-amber-700 dark:text-amber-300',
    },
    minor: { dot: 'bg-muted-foreground/50', text: 'text-muted-foreground' },
};

/** Severity as a small coloured dot and word (quieter than a pill on every row). */
function SeverityLabel({
    severity,
    label,
    className,
}: {
    severity: string;
    label?: string;
    className?: string;
}) {
    const style = SEVERITY_STYLE[severity] ?? SEVERITY_STYLE.minor;

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1.5 text-[12.5px] font-medium',
                style.text,
                className,
            )}
        >
            <span
                aria-hidden
                className={cn('size-1.5 rounded-full', style.dot)}
            />
            {label ?? sentenceCase(severity)}
        </span>
    );
}

const TIER_TONE: Record<string, BadgeTone> = {
    reach: 'danger',
    match: 'warning',
    safety: 'success',
};

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

function schoolRowById(
    schools: CollegeSchoolRow[],
    id: string,
): CollegeSchoolRow | undefined {
    return schools.find((s) => s.id === id);
}

function formatSchoolNamesOxford(
    ids: string[],
    schools: CollegeSchoolRow[],
): string {
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

function normalizeRejectionReason(r: RejectionReason): {
    text: string;
    severity: ReasonSeverity;
} {
    if (typeof r === 'string') {
        return { text: r, severity: 'moderate' };
    }

    const sev = r.severity;
    const severity: ReasonSeverity =
        sev === 'critical' || sev === 'moderate' || sev === 'minor'
            ? sev
            : 'moderate';

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

function compareSat(
    hypoSat: number | null,
    school: CollegeSchoolRow,
): Comparison {
    if (hypoSat != null && school.median_sat != null) {
        return hypoSat >= school.median_sat
            ? { state: 'within', text: 'SAT: Now within SAT median range' }
            : { state: 'below', text: 'SAT: Still below median' };
    }

    if (hypoSat == null) {
        return {
            state: 'unknown',
            text: 'SAT: add a baseline SAT in your profile to compare.',
        };
    }

    return {
        state: 'unknown',
        text: 'SAT: median data unavailable for this comparison.',
    };
}

function compareGpa(
    hypoGpa: number | null,
    school: CollegeSchoolRow,
): Comparison {
    if (hypoGpa != null && school.median_gpa != null) {
        const med = Number.parseFloat(school.median_gpa);

        if (!Number.isNaN(med)) {
            return hypoGpa >= med
                ? { state: 'within', text: 'GPA: Now within GPA median range' }
                : { state: 'below', text: 'GPA: Still below median' };
        }
    } else if (hypoGpa == null) {
        return {
            state: 'unknown',
            text: 'GPA: add a baseline GPA in your profile to compare.',
        };
    }

    return {
        state: 'unknown',
        text: 'GPA: median data unavailable for this comparison.',
    };
}

function ComparisonLine({ comparison }: { comparison: Comparison }) {
    const Icon =
        comparison.state === 'within'
            ? CheckCircle
            : comparison.state === 'below'
              ? WarningCircle
              : MinusCircle;

    return (
        <p className="flex items-start gap-2 text-[13.5px] leading-snug text-muted-foreground">
            <Icon
                weight="fill"
                aria-hidden
                className={cn(
                    'mt-px size-4 shrink-0',
                    comparison.state === 'within'
                        ? 'text-emerald-500'
                        : comparison.state === 'below'
                          ? 'text-amber-500'
                          : 'text-muted-foreground/50',
                )}
            />
            <span className="min-w-0">{comparison.text}</span>
        </p>
    );
}

export default function GapFixPage() {
    const {
        college_schools,
        latest_analysis: initialLatest,
        profile_baseline,
        isSubscribed = false,
        feature_quotas,
    } = usePage<PageProps>().props;

    const hydrated = useHydrated();
    const [paywallOpen, setPaywallOpen] = useState(false);
    const [quotaModalOpen, setQuotaModalOpen] = useState(false);
    const [quotaModalDetail, setQuotaModalDetail] =
        useState<QuotaFeatureDetail | null>(null);
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
    const [whatIfBusy, setWhatIfBusy] = useState(false);
    const [resultTab, setResultTab] = useState<ResultTab>('risks');

    // The what-if explanation shown is the one fetched for this exact scenario, else one cached earlier this
    // session (sessionStorage is read only after hydration).
    const latestId = latest?.id ?? null;
    const whatIfKey = latestId
        ? whatIfStorageKey(latestId, Array.from(selected), satDelta, gpaDelta)
        : null;
    const [fetchedWhatIf, setFetchedWhatIf] = useState<{
        key: string | null;
        text: string | null;
    } | null>(null);
    const cachedNarrative = useMemo(() => {
        if (!hydrated || whatIfKey === null) {
            return null;
        }

        try {
            return sessionStorage.getItem(whatIfKey);
        } catch {
            return null;
        }
    }, [hydrated, whatIfKey]);
    const whatIfNarrative =
        fetchedWhatIf && fetchedWhatIf.key === whatIfKey
            ? fetchedWhatIf.text
            : cachedNarrative;

    useEffect(() => {
        if (!analyzing) {
            return;
        }

        const t = window.setInterval(() => {
            setStageIdx((i) => (i + 1) % ANALYSIS_STAGES.length);
        }, 3200);

        return () => window.clearInterval(t);
    }, [analyzing]);

    const hypoSat = useMemo(() => {
        if (profile_baseline.sat_score == null) {
            return null;
        }

        return Math.max(
            400,
            Math.min(1600, profile_baseline.sat_score + satDelta),
        );
    }, [profile_baseline.sat_score, satDelta]);

    const hypoGpa = useMemo(() => {
        if (profile_baseline.gpa_normalized_4 == null) {
            return null;
        }

        return Math.max(
            0,
            Math.min(
                4,
                Math.round(
                    (profile_baseline.gpa_normalized_4 + gpaDelta) * 100,
                ) / 100,
            ),
        );
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
                setThrottleMsg(
                    data.message ??
                        'You have reached your gap analysis limit for your plan.',
                );

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
                setError(
                    first ?? data.message ?? 'Could not complete analysis.',
                );

                return;
            }

            if (!res.ok) {
                const first =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : undefined;
                setError(
                    first ?? data.message ?? 'Could not complete analysis.',
                );

                return;
            }

            if (data.analysis) {
                clearWhatIfSessionStorage();
                setFetchedWhatIf(null);
                setLatest(data.analysis);
            }
        } catch {
            setError('Network error — try again.');
        } finally {
            setAnalyzing(false);
        }
    }, [selected]);

    const explainWhatIf = useCallback(async () => {
        const scenarioKey = whatIfKey;
        setFetchedWhatIf({ key: scenarioKey, text: null });
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
                setThrottleMsg(
                    data.message ??
                        'You have reached your fix roadmap limit for your plan.',
                );

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
                setError(
                    first ?? data.message ?? 'Could not generate explanation.',
                );

                return;
            }

            if (data.narrative) {
                setFetchedWhatIf({ key: scenarioKey, text: data.narrative });

                try {
                    if (latestId) {
                        sessionStorage.setItem(
                            whatIfStorageKey(
                                latestId,
                                Array.from(selected),
                                satDelta,
                                gpaDelta,
                            ),
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
    }, [gpaDelta, latestId, satDelta, selected, whatIfKey]);

    const payload = latest?.payload;
    const gaps = payload?.gaps;
    const gapsSorted = useMemo(() => {
        const g = gaps ?? [];

        return [...g].sort(
            (a, b) => severityOrder(a.severity) - severityOrder(b.severity),
        );
    }, [gaps]);

    const missingIds = latest?.missing_school_ids;
    const missingNames = useMemo(() => {
        if (!missingIds?.length) {
            return [];
        }

        return missingIds.map((id) => schoolNameById(college_schools, id));
    }, [college_schools, missingIds]);

    const analyzedAt =
        hydrated && latest?.created_at
            ? new Date(latest.created_at).toLocaleString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
              })
            : null;
    const selectedSchools = Array.from(selected)
        .map((id) => schoolRowById(college_schools, id))
        .filter((s): s is CollegeSchoolRow => s !== undefined);

    return (
        <>
            <Head title="Gap + Fix" />

            <div className="relative">
                <div
                    className={cn(
                        !hasPaidAccess &&
                            'pointer-events-none blur-sm select-none',
                    )}
                    inert={!hasPaidAccess}
                >
                    <Page width="default">
                        <PageHeader
                            title="Gap + Fix"
                            description="Rejection risks for each school, a prioritized fix roadmap, and a what-if view against your list — grounded in your saved profile and list data."
                        />

                        {college_schools.length === 0 ? (
                            <EmptyState
                                icon={
                                    <IconTile
                                        icon={ChartLine}
                                        tone="orange"
                                        variant="glyph"
                                        size="lg"
                                    />
                                }
                                title="No schools on your list"
                                description="Add schools on your college lists page, then come back to run a gap analysis against your profile."
                                action={
                                    <Button
                                        asChild
                                        className="rounded-full px-4"
                                    >
                                        <Link href={collegeLists.url()}>
                                            Go to college lists
                                        </Link>
                                    </Button>
                                }
                            />
                        ) : (
                            <>
                                <Section
                                    title="Schools to analyze"
                                    icon={Buildings}
                                    description="Choose one or more (up to 12). We compare your profile with each school’s medians, tier and fit score."
                                >
                                    <div className={toolCardClass}>
                                        <div className="@container">
                                            <div className="grid gap-2 @lg:grid-cols-2 @3xl:grid-cols-3">
                                                {college_schools.map((s) => {
                                                    const on = selected.has(
                                                        s.id,
                                                    );

                                                    return (
                                                        <label
                                                            key={s.id}
                                                            className={cn(
                                                                'flex cursor-pointer items-start gap-3 rounded-xl px-3.5 py-3 transition-[background-color,box-shadow] duration-150 has-[input:focus-visible]:ring-2 has-[input:focus-visible]:ring-ring/50',
                                                                on
                                                                    ? 'bg-card shadow-raised ring-1 ring-foreground/15'
                                                                    : 'bg-muted hover:bg-foreground/[0.07]',
                                                            )}
                                                        >
                                                            <span className="relative mt-[3px] flex size-4 shrink-0 items-center justify-center">
                                                                <input
                                                                    type="checkbox"
                                                                    className="peer sr-only"
                                                                    checked={on}
                                                                    onChange={() =>
                                                                        toggleSchool(
                                                                            s.id,
                                                                        )
                                                                    }
                                                                />
                                                                <span
                                                                    aria-hidden
                                                                    className="absolute inset-0 rounded-[4px] border border-foreground/25 bg-card transition-colors peer-checked:border-foreground peer-checked:bg-foreground"
                                                                />
                                                                <Check
                                                                    aria-hidden
                                                                    weight="bold"
                                                                    className={cn(
                                                                        'relative size-3 text-background transition-opacity',
                                                                        on
                                                                            ? 'opacity-100'
                                                                            : 'opacity-0',
                                                                    )}
                                                                />
                                                            </span>
                                                            <span className="min-w-0">
                                                                <span className="block text-[13.5px] leading-snug font-medium text-foreground">
                                                                    {
                                                                        s.school_name
                                                                    }
                                                                </span>
                                                                <span className="nums mt-0.5 block text-[12.5px] text-muted-foreground">
                                                                    {sentenceCase(
                                                                        s.tier,
                                                                    )}{' '}
                                                                    · fit{' '}
                                                                    {
                                                                        s.fit_score
                                                                    }
                                                                    {s.median_sat !=
                                                                    null
                                                                        ? ` · SAT ~${s.median_sat}`
                                                                        : ''}
                                                                </span>
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        </div>

                                        <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border pt-5">
                                            <Button
                                                type="button"
                                                disabled={
                                                    analyzing ||
                                                    selected.size === 0 ||
                                                    gapBlocked
                                                }
                                                onClick={() =>
                                                    void runAnalysis()
                                                }
                                                className="h-10 gap-2 rounded-xl px-4"
                                            >
                                                <Lightning
                                                    className="size-4"
                                                    weight="fill"
                                                    aria-hidden
                                                />
                                                {analyzing
                                                    ? 'Analyzing…'
                                                    : 'Run analysis'}
                                            </Button>
                                            <span
                                                className={cn(
                                                    'nums text-[12.5px] leading-snug',
                                                    gapQuota?.warning
                                                        ? 'text-amber-700 dark:text-amber-300'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {gapQuota &&
                                                gapQuota.limit != null
                                                    ? `${gapQuota.used} of ${gapQuota.limit} gap analyses used${feature_quotas?.window_days ? ` per ${feature_quotas.window_days} days` : ''}.`
                                                    : 'Each run uses your current selection.'}
                                            </span>
                                        </div>

                                        {error ? (
                                            <Notice
                                                tone="danger"
                                                role="alert"
                                                className="mt-4"
                                            >
                                                {error}
                                            </Notice>
                                        ) : null}
                                        {throttleMsg ? (
                                            <Notice
                                                tone="warning"
                                                role="status"
                                                className="mt-4"
                                            >
                                                {throttleMsg}
                                            </Notice>
                                        ) : null}
                                    </div>
                                </Section>

                                {incompleteGenerate ? (
                                    <Notice
                                        tone="danger"
                                        role="alert"
                                        className="mb-9"
                                    >
                                        <p className="font-medium">
                                            Analysis did not finish
                                        </p>
                                        <p>
                                            Analysis failed — the AI returned an
                                            incomplete result. Please try again.
                                        </p>
                                        <Button
                                            type="button"
                                            size="sm"
                                            className="mt-3 rounded-lg"
                                            onClick={() => {
                                                setIncompleteGenerate(false);
                                                void runAnalysis();
                                            }}
                                        >
                                            Regenerate
                                        </Button>
                                    </Notice>
                                ) : null}

                                {analyzing ? (
                                    <section
                                        aria-live="polite"
                                        className="mb-9 rounded-2xl bg-muted/70 px-5 py-5"
                                    >
                                        <p className="text-[15px] leading-snug font-medium text-foreground">
                                            Analyzing your profile against{' '}
                                            {formatSchoolNamesOxford(
                                                Array.from(selected),
                                                college_schools,
                                            )}
                                            …
                                        </p>
                                        <ol className="mt-4 space-y-2.5">
                                            {ANALYSIS_STAGES.map((line, i) => (
                                                <li
                                                    key={line}
                                                    className={cn(
                                                        'flex items-center gap-2.5 text-[13.5px]',
                                                        i === stageIdx
                                                            ? 'font-medium text-foreground'
                                                            : i < stageIdx
                                                              ? 'text-muted-foreground'
                                                              : 'text-muted-foreground/60',
                                                    )}
                                                >
                                                    {i < stageIdx ? (
                                                        <CheckCircle
                                                            className="size-4 shrink-0 text-foreground/45"
                                                            weight="fill"
                                                            aria-hidden
                                                        />
                                                    ) : i === stageIdx ? (
                                                        <CircleNotch
                                                            className="size-4 shrink-0 animate-spin text-foreground"
                                                            aria-hidden
                                                        />
                                                    ) : (
                                                        <Circle
                                                            className="size-4 shrink-0 text-muted-foreground/40"
                                                            aria-hidden
                                                        />
                                                    )}
                                                    {line}
                                                </li>
                                            ))}
                                        </ol>
                                        <p className="mt-4 text-[12.5px] text-muted-foreground">
                                            This usually takes 10–15 seconds
                                        </p>
                                    </section>
                                ) : null}

                                {payload &&
                                (payload.rejection_risks?.length ||
                                    gapsSorted.length) ? (
                                    <Section
                                        title="Your results"
                                        icon={ChartLine}
                                        actions={
                                            analyzedAt ? (
                                                <span className="nums text-[12.5px] text-muted-foreground">
                                                    {analyzedAt}
                                                </span>
                                            ) : undefined
                                        }
                                    >
                                        {latest?.is_partial ? (
                                            <Notice
                                                tone="warning"
                                                role="status"
                                                className="mb-5"
                                            >
                                                Partial results — analysis
                                                incomplete for:{' '}
                                                {(missingNames.length > 0
                                                    ? missingNames.join(', ')
                                                    : (
                                                          latest.missing_school_ids ??
                                                          []
                                                      ).join(', ')) ||
                                                    'some schools'}
                                                . Regenerate or select fewer
                                                schools.
                                            </Notice>
                                        ) : null}

                                        <div className="-mx-5 scrollbar-none overflow-x-auto px-5 sm:mx-0 sm:px-0">
                                            <SegmentedTabs
                                                label="Results"
                                                items={[
                                                    {
                                                        value: 'risks',
                                                        label: 'Rejection risks',
                                                    },
                                                    {
                                                        value: 'roadmap',
                                                        label: 'Fix roadmap',
                                                    },
                                                    {
                                                        value: 'whatif',
                                                        label: 'What-if',
                                                    },
                                                ]}
                                                value={resultTab}
                                                onChange={setResultTab}
                                            />
                                        </div>

                                        <div
                                            role="tabpanel"
                                            aria-label="Results"
                                            className="mt-6"
                                        >
                                            {resultTab === 'risks' ? (
                                                <div className="space-y-7">
                                                    {payload.disclaimer ? (
                                                        <p className="text-[12.5px] leading-relaxed text-muted-foreground">
                                                            {payload.disclaimer}
                                                        </p>
                                                    ) : null}
                                                    {(
                                                        payload.rejection_risks ??
                                                        []
                                                    ).map((row) => {
                                                        const rowSchool =
                                                            schoolRowById(
                                                                college_schools,
                                                                row.college_list_school_id,
                                                            );
                                                        const tier =
                                                            rowSchool?.tier ??
                                                            '—';

                                                        return (
                                                            <div
                                                                key={
                                                                    row.college_list_school_id
                                                                }
                                                            >
                                                                <div className="mb-2.5 flex flex-wrap items-center gap-2">
                                                                    <h3 className="text-[15px] leading-snug font-semibold text-foreground">
                                                                        {schoolNameById(
                                                                            college_schools,
                                                                            row.college_list_school_id,
                                                                        )}
                                                                    </h3>
                                                                    <StatusBadge
                                                                        tone={
                                                                            TIER_TONE[
                                                                                tier
                                                                            ] ??
                                                                            'neutral'
                                                                        }
                                                                    >
                                                                        {sentenceCase(
                                                                            tier,
                                                                        )}
                                                                    </StatusBadge>
                                                                </div>
                                                                <ol className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                                                    {row.reasons.map(
                                                                        (
                                                                            raw,
                                                                            i,
                                                                        ) => {
                                                                            const {
                                                                                text,
                                                                                severity,
                                                                            } =
                                                                                normalizeRejectionReason(
                                                                                    raw,
                                                                                );

                                                                            return (
                                                                                <li
                                                                                    key={
                                                                                        i
                                                                                    }
                                                                                    className="flex flex-col gap-1 px-4 py-3.5 sm:flex-row sm:items-start sm:gap-4 sm:px-5"
                                                                                >
                                                                                    <span className="flex min-w-0 flex-1 gap-3">
                                                                                        <span className="nums w-4 shrink-0 text-[13px] leading-relaxed font-medium text-muted-foreground">
                                                                                            {i +
                                                                                                1}
                                                                                        </span>
                                                                                        <span className="min-w-0 text-[14px] leading-relaxed text-foreground">
                                                                                            {
                                                                                                text
                                                                                            }
                                                                                        </span>
                                                                                    </span>
                                                                                    <SeverityLabel
                                                                                        severity={
                                                                                            severity
                                                                                        }
                                                                                        label={reasonSeverityLabel(
                                                                                            severity,
                                                                                        )}
                                                                                        className="ml-7 sm:mt-[3px] sm:ml-0"
                                                                                    />
                                                                                </li>
                                                                            );
                                                                        },
                                                                    )}
                                                                </ol>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            ) : null}

                                            {resultTab === 'roadmap' ? (
                                                <ol className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                                    {gapsSorted.map((g) => (
                                                        <li
                                                            key={g.id}
                                                            className="px-5 py-4"
                                                        >
                                                            <SeverityLabel
                                                                severity={
                                                                    g.severity
                                                                }
                                                            />
                                                            <h3 className="mt-1 text-[15px] leading-snug font-semibold text-foreground">
                                                                {g.title}
                                                            </h3>
                                                            <ol className="mt-3 space-y-2.5">
                                                                {g.steps.map(
                                                                    (
                                                                        step,
                                                                        i,
                                                                    ) => (
                                                                        <li
                                                                            key={
                                                                                i
                                                                            }
                                                                            className="flex gap-3 text-[14px] leading-relaxed text-foreground/85"
                                                                        >
                                                                            <span className="nums mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[11.5px] font-semibold text-foreground">
                                                                                {i +
                                                                                    1}
                                                                            </span>
                                                                            <span className="min-w-0">
                                                                                {
                                                                                    step
                                                                                }
                                                                            </span>
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ol>
                                                        </li>
                                                    ))}
                                                </ol>
                                            ) : null}

                                            {resultTab === 'whatif' ? (
                                                <div className="space-y-9">
                                                    <div
                                                        className={
                                                            toolCardClass
                                                        }
                                                    >
                                                        <div className="@container">
                                                            <div className="grid gap-6 @xl:grid-cols-2">
                                                                <div>
                                                                    <div className="flex items-baseline justify-between gap-3">
                                                                        <label
                                                                            htmlFor="whatif-sat"
                                                                            className="text-[13.5px] font-medium text-foreground"
                                                                        >
                                                                            SAT
                                                                            change
                                                                        </label>
                                                                        <span className="nums text-[13.5px] font-semibold text-foreground">
                                                                            {satDelta >=
                                                                            0
                                                                                ? '+'
                                                                                : ''}
                                                                            {
                                                                                satDelta
                                                                            }{' '}
                                                                            pts
                                                                        </span>
                                                                    </div>
                                                                    <input
                                                                        id="whatif-sat"
                                                                        type="range"
                                                                        min={
                                                                            -200
                                                                        }
                                                                        max={
                                                                            200
                                                                        }
                                                                        step={1}
                                                                        value={
                                                                            satDelta
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setSatDelta(
                                                                                Number(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                ),
                                                                            )
                                                                        }
                                                                        className="mt-3 block w-full cursor-pointer accent-foreground"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <div className="flex items-baseline justify-between gap-3">
                                                                        <label
                                                                            htmlFor="whatif-gpa"
                                                                            className="text-[13.5px] font-medium text-foreground"
                                                                        >
                                                                            GPA
                                                                            change
                                                                            (4.0
                                                                            scale)
                                                                        </label>
                                                                        <span className="nums text-[13.5px] font-semibold text-foreground">
                                                                            {gpaDelta >=
                                                                            0
                                                                                ? '+'
                                                                                : ''}
                                                                            {gpaDelta.toFixed(
                                                                                2,
                                                                            )}
                                                                        </span>
                                                                    </div>
                                                                    <input
                                                                        id="whatif-gpa"
                                                                        type="range"
                                                                        min={
                                                                            -50
                                                                        }
                                                                        max={50}
                                                                        step={5}
                                                                        value={Math.round(
                                                                            gpaDelta *
                                                                                100,
                                                                        )}
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setGpaDelta(
                                                                                Number(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                ) /
                                                                                    100,
                                                                            )
                                                                        }
                                                                        className="mt-3 block w-full cursor-pointer accent-foreground"
                                                                    />
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <p className="mt-5 border-t border-border pt-4 text-[12.5px] text-muted-foreground">
                                                            Your baseline → with
                                                            these changes
                                                        </p>
                                                        <KeyValueList>
                                                            <KeyValueRow
                                                                label="SAT"
                                                                value={
                                                                    <span className="nums inline-flex items-center gap-1.5">
                                                                        <span className="font-normal text-muted-foreground">
                                                                            {profile_baseline.sat_score ??
                                                                                '—'}
                                                                        </span>
                                                                        <ArrowRight
                                                                            className="size-3.5 text-muted-foreground"
                                                                            aria-label="to"
                                                                        />
                                                                        {hypoSat ??
                                                                            '—'}
                                                                    </span>
                                                                }
                                                            />
                                                            <KeyValueRow
                                                                label="GPA (4.0 scale)"
                                                                value={
                                                                    <span className="nums inline-flex items-center gap-1.5">
                                                                        <span className="font-normal text-muted-foreground">
                                                                            {profile_baseline.gpa_normalized_4 ??
                                                                                '—'}
                                                                        </span>
                                                                        <ArrowRight
                                                                            className="size-3.5 text-muted-foreground"
                                                                            aria-label="to"
                                                                        />
                                                                        {hypoGpa ??
                                                                            '—'}
                                                                    </span>
                                                                }
                                                            />
                                                        </KeyValueList>
                                                    </div>

                                                    <Section
                                                        title="Median comparison"
                                                        icon={Scales}
                                                        description="For the schools you selected."
                                                    >
                                                        {selectedSchools.length ===
                                                        0 ? (
                                                            <p className="text-[13.5px] text-muted-foreground">
                                                                Select schools
                                                                above to
                                                                compare.
                                                            </p>
                                                        ) : (
                                                            <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                                                {selectedSchools.map(
                                                                    (s) => (
                                                                        <li
                                                                            key={
                                                                                s.id
                                                                            }
                                                                            className="px-5 py-3.5"
                                                                        >
                                                                            <p className="text-[14.5px] font-medium text-foreground">
                                                                                {
                                                                                    s.school_name
                                                                                }
                                                                            </p>
                                                                            <div className="mt-1.5 space-y-1">
                                                                                <ComparisonLine
                                                                                    comparison={compareSat(
                                                                                        hypoSat,
                                                                                        s,
                                                                                    )}
                                                                                />
                                                                                <ComparisonLine
                                                                                    comparison={compareGpa(
                                                                                        hypoGpa,
                                                                                        s,
                                                                                    )}
                                                                                />
                                                                            </div>
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        )}
                                                    </Section>

                                                    <div className="space-y-5">
                                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                                            <Button
                                                                type="button"
                                                                className="h-10 gap-2 rounded-xl px-4"
                                                                disabled={
                                                                    whatIfBusy ||
                                                                    selected.size ===
                                                                        0 ||
                                                                    roadmapBlocked
                                                                }
                                                                onClick={() =>
                                                                    void explainWhatIf()
                                                                }
                                                            >
                                                                <Sparkle
                                                                    className="size-4"
                                                                    weight="fill"
                                                                    aria-hidden
                                                                />
                                                                {whatIfBusy
                                                                    ? 'Explaining…'
                                                                    : 'Explain impact'}
                                                            </Button>
                                                            {roadmapQuota &&
                                                            roadmapQuota.limit !=
                                                                null ? (
                                                                <span
                                                                    className={cn(
                                                                        'nums text-[12.5px] leading-snug',
                                                                        roadmapQuota.warning
                                                                            ? 'text-amber-700 dark:text-amber-300'
                                                                            : 'text-muted-foreground',
                                                                    )}
                                                                >
                                                                    {
                                                                        roadmapQuota.used
                                                                    }{' '}
                                                                    of{' '}
                                                                    {
                                                                        roadmapQuota.limit
                                                                    }{' '}
                                                                    fix roadmap
                                                                    runs used
                                                                    {feature_quotas?.window_days
                                                                        ? ` per ${feature_quotas.window_days} days`
                                                                        : ''}
                                                                    .
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        {whatIfNarrative ? (
                                                            <div className="rounded-2xl border border-border bg-card px-5 py-5 sm:px-6">
                                                                <p className="mb-3 text-[13px] font-medium text-muted-foreground">
                                                                    Impact
                                                                </p>
                                                                <ProseMarkdown
                                                                    content={demoteHeadings(
                                                                        whatIfNarrative,
                                                                    )}
                                                                    className={
                                                                        proseDocClass
                                                                    }
                                                                />
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            ) : null}
                                        </div>
                                    </Section>
                                ) : !analyzing ? (
                                    <EmptyState
                                        title="No analysis yet"
                                        description="Run an analysis to see rejection risks, your roadmap, and the what-if tools. Previous runs stay on this page until you generate a new one."
                                    />
                                ) : null}
                            </>
                        )}
                    </Page>
                </div>

                {!hasPaidAccess ? (
                    <PlanOverlay
                        title="Gap fix is a Pro feature"
                        description="Get your step-by-step rejection fix roadmap and what-if simulator."
                        onUnlock={() => setPaywallOpen(true)}
                    />
                ) : null}

                <PaywallModal
                    open={paywallOpen}
                    onOpenChange={setPaywallOpen}
                />
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
    breadcrumbs: [{ title: 'Gap + Fix', href: gapFix.url() }],
};
