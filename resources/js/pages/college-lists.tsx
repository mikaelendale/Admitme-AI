'use client';

import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowSquareOut,
    CalendarBlank,
    CalendarDots,
    CaretRight,
    ClockCounterClockwise,
    Columns,
    DotsThree,
    ListChecks,
    LockSimple,
    Plus,
    Scales,
    Sparkle,
    Trash,
    UsersThree,
    WarningCircle,
} from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { AffordabilityBadge } from '@/components/admitme/college-assessment';
import {
    ErrorPill,
    HintPill,
    OptionCards,
} from '@/components/admitme/profile-fields';
import { SuggestionReview } from '@/components/admitme/suggestion-review';
import {
    CHOICE_TONE,
    deadlineOf,
    Disclosure,
    joinParts,
    placeOf,
    TierIcon,
    TIERS,
} from '@/components/college/college-ui';
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
import { PaywallModal } from '@/components/paywall-modal';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useHydrated } from '@/hooks/use-hydrated';
import { AFFORDABILITY_LABELS } from '@/lib/college-assessment';
import type { AffordabilityStatus } from '@/lib/college-assessment';
import { choiceStatusLabel } from '@/lib/college-list-display';
import type { CollegeRow, FitTier } from '@/lib/college-list-display';
import { createLaravelEcho } from '@/lib/laravelEcho';
import type { CouncilBroadcastPayload } from '@/lib/laravelEcho';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import { collegeLists } from '@/routes';
import collegeListsRoutes from '@/routes/college-lists';
import type { CouncilFeedLine } from '@/types/council';
import type {
    FeatureQuotasProps,
    QuotaFeatureDetail,
} from '@/types/feature-quotas';

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

const textareaClass =
    'w-full resize-none rounded-xl border border-input bg-card px-3 py-2.5 text-[14px] leading-relaxed text-foreground shadow-card outline-none transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25';

function severityDot(severity?: string): string {
    if (
        severity === 'error' ||
        severity === 'critical' ||
        severity === 'danger'
    ) {
        return 'bg-red-500';
    }

    if (severity === 'warning') {
        return 'bg-amber-500';
    }

    return 'bg-foreground/30';
}

/** The council run this page is following: its status line and, while it works, a live feed. */
function CouncilRunCard({
    line,
    feed,
}: {
    line: string;
    feed: CouncilFeedLine[];
}) {
    return (
        <div className="mb-9 flex items-start gap-3.5 rounded-2xl border border-border bg-card p-4 sm:p-5">
            <IconTile icon={UsersThree} tone="green" variant="gradient" />
            <div className="min-w-0 flex-1">
                <p className="text-[15px] font-medium text-foreground">
                    List council
                </p>
                <p
                    className="mt-0.5 text-[13.5px] leading-snug text-muted-foreground"
                    aria-live="polite"
                >
                    {line}
                </p>
                {feed.length > 0 ? (
                    <Disclosure
                        className="mt-3"
                        summary={`Live feed · ${feed.length}`}
                        defaultOpen
                    >
                        <ul className="max-h-56 scrollbar-thin space-y-2.5 overflow-y-auto pr-1">
                            {feed.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex gap-2.5 text-[13px] leading-snug"
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'mt-[7px] size-1.5 shrink-0 rounded-full',
                                            severityDot(item.severity),
                                        )}
                                    />
                                    <div className="min-w-0">
                                        <p className="text-foreground">
                                            {item.title}
                                        </p>
                                        {item.body ? (
                                            <p className="mt-0.5 text-muted-foreground">
                                                {item.body}
                                            </p>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Disclosure>
                ) : null}
            </div>
        </div>
    );
}

/** A list row that opens in place (deadlines by date, research history). */
function ExpandRow({
    icon,
    title,
    description,
    children,
}: {
    icon: ReactNode;
    title: ReactNode;
    description: ReactNode;
    children: ReactNode;
}) {
    return (
        <details className="group/row">
            <summary className="flex cursor-pointer list-none items-center gap-4 px-5 py-4 transition-colors select-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-inset [&::-webkit-details-marker]:hidden">
                <span className="flex shrink-0 items-center">{icon}</span>
                <span className="min-w-0 flex-1">
                    <span className="block text-[15px] font-medium text-foreground">
                        {title}
                    </span>
                    <span className="mt-0.5 block text-[13.5px] leading-snug text-muted-foreground">
                        {description}
                    </span>
                </span>
                <CaretRight
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground/60 transition-transform duration-150 group-open/row:rotate-90"
                />
            </summary>
            <div className="border-t border-border px-5">{children}</div>
        </details>
    );
}

function StatusCell({ row }: { row: CollegeRow }) {
    const badge = (
        <StatusBadge tone={CHOICE_TONE[row.choiceStatus]}>
            {choiceStatusLabel(row.choiceStatus)}
        </StatusBadge>
    );

    return row.applicationId ? (
        <Link
            href={`/applications/${row.applicationId}`}
            title="Open application"
            className="inline-flex rounded-full transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            {badge}
        </Link>
    ) : (
        badge
    );
}

function RowMenu({
    row,
    removeDisabled,
    onRemove,
}: {
    row: CollegeRow;
    removeDisabled: boolean;
    onRemove: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    className="text-muted-foreground"
                    aria-label={`Actions for ${row.name}`}
                >
                    <DotsThree className="size-5" weight="bold" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem asChild>
                    <Link href={collegeListsRoutes.school.url(row.id)}>
                        View details
                    </Link>
                </DropdownMenuItem>
                {row.applicationId ? (
                    <DropdownMenuItem asChild>
                        <Link href={`/applications/${row.applicationId}`}>
                            Open application
                        </Link>
                    </DropdownMenuItem>
                ) : null}
                {row.applicationPortalUrl ? (
                    <DropdownMenuItem asChild>
                        <a
                            href={row.applicationPortalUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Application portal
                            <ArrowSquareOut
                                className="ml-auto size-3.5"
                                aria-hidden
                            />
                        </a>
                    </DropdownMenuItem>
                ) : null}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    disabled={removeDisabled}
                    onSelect={onRemove}
                >
                    <Trash aria-hidden />
                    Remove from list
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Saved colleges. A real table with a header row when there is room; below ~670px of content width each row
 * stacks into a list row (name, then status, affordability and deadline underneath).
 */
function CollegeTable({
    rows,
    compareIds,
    onToggleCompare,
    runActive,
    removingId,
    onRemove,
    emptyMessage,
}: {
    rows: CollegeRow[];
    compareIds: string[];
    onToggleCompare: (id: string, on: boolean) => void;
    runActive: boolean;
    removingId: string | null;
    onRemove: (id: string) => void;
    emptyMessage: string;
}) {
    return (
        <div className="@container">
            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                <table className="block w-full border-collapse text-left @2xl:table">
                    <thead className="hidden @2xl:table-header-group">
                        <tr className="border-b border-border bg-muted/40 text-[12.5px] text-muted-foreground">
                            <th
                                scope="col"
                                className="w-12 py-2.5 pl-5 font-normal"
                            >
                                <span className="sr-only">Compare</span>
                            </th>
                            <th
                                scope="col"
                                className="py-2.5 pr-4 pl-1 font-normal"
                            >
                                College
                            </th>
                            <th scope="col" className="py-2.5 pr-4 font-normal">
                                Deadline
                            </th>
                            <th scope="col" className="py-2.5 pr-4 font-normal">
                                Affordability
                            </th>
                            <th scope="col" className="py-2.5 pr-4 font-normal">
                                Status
                            </th>
                            <th
                                scope="col"
                                className="w-12 py-2.5 pr-3 font-normal"
                            >
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="block divide-y divide-border @2xl:table-row-group">
                        {rows.length === 0 ? (
                            <tr className="block @2xl:table-row">
                                <td
                                    colSpan={6}
                                    className="block px-5 py-10 text-center text-[13.5px] text-muted-foreground @2xl:table-cell"
                                >
                                    {emptyMessage}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => {
                                const selected = compareIds.includes(row.id);
                                const deadline = deadlineOf(row);
                                const estimates = joinParts([
                                    row.netCostEstimate !== '—'
                                        ? row.netCostEstimate
                                        : null,
                                    row.aidEstimate !== '—'
                                        ? row.aidEstimate
                                        : null,
                                ]);
                                const aid =
                                    estimates !== ''
                                        ? `${estimates} (AI est.)`
                                        : null;

                                return (
                                    <tr
                                        key={row.id}
                                        className={cn(
                                            'relative flex items-start gap-3 px-4 py-4 transition-[background-color,opacity] @2xl:table-row @2xl:p-0',
                                            selected
                                                ? 'bg-muted/50'
                                                : 'hover:bg-muted/30',
                                            removingId === row.id &&
                                                'opacity-50',
                                        )}
                                    >
                                        <td className="block pt-[3px] @2xl:table-cell @2xl:w-12 @2xl:py-4 @2xl:pr-0 @2xl:pl-5 @2xl:align-middle">
                                            <Checkbox
                                                checked={selected}
                                                disabled={
                                                    !selected &&
                                                    compareIds.length >= 4
                                                }
                                                onCheckedChange={(checked) =>
                                                    onToggleCompare(
                                                        row.id,
                                                        checked === true,
                                                    )
                                                }
                                                aria-label={`Compare ${row.name}`}
                                            />
                                        </td>
                                        <td className="block min-w-0 flex-1 @2xl:table-cell @2xl:py-4 @2xl:pr-4 @2xl:pl-1 @2xl:align-middle">
                                            <div className="flex items-start gap-3 @2xl:items-center">
                                                <TierIcon tier={row.tier} />
                                                <div className="min-w-0 flex-1">
                                                    <Link
                                                        href={collegeListsRoutes.school.url(
                                                            row.id,
                                                        )}
                                                        className="block pr-8 text-[14.5px] leading-snug font-medium text-foreground underline-offset-2 hover:underline @2xl:pr-0"
                                                    >
                                                        {row.name}
                                                    </Link>
                                                    <p className="nums mt-0.5 pr-8 text-[13px] leading-snug text-muted-foreground @2xl:pr-0">
                                                        {joinParts([
                                                            TIERS[row.tier]
                                                                .label,
                                                            placeOf(row),
                                                            `Fit ${row.fitScore}`,
                                                        ])}
                                                        <span className="@2xl:hidden">
                                                            {' · '}
                                                            <CalendarBlank
                                                                aria-hidden
                                                                className="mb-px inline size-3.5"
                                                            />{' '}
                                                            <span
                                                                className={
                                                                    deadline.none
                                                                        ? undefined
                                                                        : 'text-foreground/80'
                                                                }
                                                            >
                                                                {deadline.text.replace(
                                                                    /\s*\n\s*/g,
                                                                    ' · ',
                                                                )}
                                                            </span>
                                                        </span>
                                                    </p>
                                                    <div className="mt-2 flex flex-wrap items-center gap-1.5 @2xl:hidden">
                                                        <StatusCell row={row} />
                                                        {row.assessment ? (
                                                            <AffordabilityBadge
                                                                affordability={
                                                                    row
                                                                        .assessment
                                                                        .affordability
                                                                }
                                                            />
                                                        ) : null}
                                                        {aid ? (
                                                            <span className="text-[12.5px] text-muted-foreground">
                                                                {aid}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="hidden @2xl:table-cell @2xl:py-4 @2xl:pr-4 @2xl:align-middle">
                                            <span
                                                className={cn(
                                                    'text-[13.5px] leading-snug whitespace-pre-line',
                                                    deadline.none
                                                        ? 'text-muted-foreground'
                                                        : 'text-foreground',
                                                )}
                                            >
                                                {deadline.text}
                                            </span>
                                        </td>
                                        <td className="hidden @2xl:table-cell @2xl:py-4 @2xl:pr-4 @2xl:align-middle">
                                            {row.assessment ? (
                                                <AffordabilityBadge
                                                    affordability={
                                                        row.assessment
                                                            .affordability
                                                    }
                                                    short
                                                />
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                            {aid ? (
                                                <p className="mt-1 text-[12px] leading-snug text-muted-foreground">
                                                    {aid}
                                                </p>
                                            ) : null}
                                        </td>
                                        <td className="hidden @2xl:table-cell @2xl:py-4 @2xl:pr-4 @2xl:align-middle">
                                            <StatusCell row={row} />
                                        </td>
                                        <td className="absolute top-3 right-2 @2xl:static @2xl:table-cell @2xl:w-12 @2xl:py-4 @2xl:pr-3 @2xl:align-middle">
                                            <RowMenu
                                                row={row}
                                                removeDisabled={
                                                    runActive ||
                                                    removingId === row.id
                                                }
                                                onRemove={() =>
                                                    onRemove(row.id)
                                                }
                                            />
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function CollegeLists() {
    const {
        colleges: allColleges,
        councilRunId,
        councilRunHistory = [],
        college_list_council,
        isSubscribed = false,
        feature_quotas,
    } = usePage<PageProps>().props;
    const councilEnabled = college_list_council?.enabled === true;
    const hydrated = useHydrated();

    const [paywallOpen, setPaywallOpen] = useState(false);
    const [quotaModalOpen, setQuotaModalOpen] = useState(false);
    const [quotaModalDetail, setQuotaModalDetail] =
        useState<QuotaFeatureDetail | null>(null);
    const hasPaidAccess = isSubscribed === true;

    const councilQuota = feature_quotas?.features?.college_list;
    const councilBlocked = councilQuota?.remaining === 0;

    const [researchOpen, setResearchOpen] = useState(false);
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
    const [councilBannerLine, setCouncilBannerLine] = useState(
        'Loading council status…',
    );
    const [councilFeedLines, setCouncilFeedLines] = useState<CouncilFeedLine[]>(
        [],
    );
    const [removingId, setRemovingId] = useState<string | null>(null);
    const [addSchoolOpen, setAddSchoolOpen] = useState(false);
    const [manualName, setManualName] = useState('');
    const [manualTier, setManualTier] = useState<'reach' | 'match' | 'safety'>(
        'match',
    );
    const [manualNotes, setManualNotes] = useState('');
    const [manualSaving, setManualSaving] = useState(false);

    // Close the research dialog once a run has started (the page then follows the run).
    const [seenGenerateStatus, setSeenGenerateStatus] =
        useState(generateStatus);

    if (seenGenerateStatus !== generateStatus) {
        setSeenGenerateStatus(generateStatus);

        if (generateStatus === 'done') {
            setResearchOpen(false);
        }
    }

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

        if (
            !college_list_council?.enabled ||
            !college_list_council.reverb?.key
        ) {
            return;
        }

        const csrf = readCsrfTokenFromMeta();

        if (!csrf) {
            return;
        }

        if (!echoRef.current) {
            echoRef.current = createLaravelEcho(
                csrf,
                college_list_council.reverb,
            );
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
                        severity:
                            typeof data.severity === 'string'
                                ? data.severity
                                : 'info',
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

    // Suggestions are reviewed separately; the list, balance and deadlines describe saved colleges only.
    const suggestions = useMemo(
        () => allColleges.filter((c) => c.choiceStatus === 'suggested'),
        [allColleges],
    );
    const colleges = useMemo(
        () => allColleges.filter((c) => c.choiceStatus !== 'suggested'),
        [allColleges],
    );
    const [statusFilter, setStatusFilter] = useState<
        'all' | 'shortlisted' | 'applying'
    >('all');
    const [affordabilityFilter, setAffordabilityFilter] = useState<
        'any' | AffordabilityStatus
    >('any');
    const [tierFilter, setTierFilter] = useState<'all' | FitTier>('all');
    const [compareIds, setCompareIds] = useState<string[]>([]);
    const visible = useMemo(
        () =>
            colleges.filter(
                (c) =>
                    (statusFilter === 'all' ||
                        c.choiceStatus === statusFilter) &&
                    (affordabilityFilter === 'any' ||
                        c.assessment?.affordability.status ===
                            affordabilityFilter) &&
                    (tierFilter === 'all' || c.tier === tierFilter),
            ),
        [colleges, statusFilter, affordabilityFilter, tierFilter],
    );
    const filtersActive =
        statusFilter !== 'all' ||
        affordabilityFilter !== 'any' ||
        tierFilter !== 'all';
    const toggleCompare = (id: string, on: boolean) =>
        setCompareIds((current) =>
            on
                ? [...new Set([...current, id])].slice(0, 4)
                : current.filter((value) => value !== id),
        );

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

    const hasSchools = allColleges.length > 0;

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
                    student_notes:
                        manualNotes.trim() === '' ? null : manualNotes.trim(),
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
            let data: {
                message?: string;
                run_id?: string;
                quota?: QuotaFeatureDetail;
            } = {};

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
                    data.message ??
                        'You have reached your college list generation limit for this period.',
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

    const generateDisabled = hasSchools
        ? generateStatus === 'working' || !councilEnabled || councilBlocked
        : generateStatus === 'working' || councilBlocked;
    const emptyMessage = filtersActive
        ? 'No saved colleges match these filters.'
        : suggestions.length > 0
          ? 'No saved colleges yet — review the suggestions above.'
          : 'No schools saved yet. Add schools from the dashboard chat or onboarding.';

    const researchButton = (
        <Button
            type="button"
            className="h-9 gap-2 rounded-full px-4"
            onClick={() => setResearchOpen(true)}
        >
            <Sparkle className="size-4" weight="fill" />
            Research colleges
        </Button>
    );

    return (
        <>
            <Head title="College lists" />

            <div className="relative">
                <div
                    className={cn(
                        !hasPaidAccess &&
                            'pointer-events-none blur-sm select-none',
                    )}
                >
                    <Page width="default">
                        <PageHeader
                            title="College lists"
                            description="Your saved colleges with fit, deadlines and cost."
                            actions={
                                hasSchools ? (
                                    <>
                                        {!runActive ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="h-9 gap-2 rounded-full px-4"
                                                onClick={() =>
                                                    setAddSchoolOpen(true)
                                                }
                                            >
                                                <Plus className="size-4" />
                                                Add college
                                            </Button>
                                        ) : null}
                                        {researchButton}
                                    </>
                                ) : undefined
                            }
                        />

                        {runActive ? (
                            <CouncilRunCard
                                line={councilBannerLine}
                                feed={councilFeedLines}
                            />
                        ) : null}

                        {!hasSchools ? (
                            <EmptyState
                                icon={
                                    <IconTile
                                        icon={ListChecks}
                                        tone="green"
                                        variant="gradient"
                                        size="lg"
                                    />
                                }
                                title="No colleges yet"
                                description="Describe your major, region, budget and vibe. The list council suggests reach, match and safety picks — you choose which ones join your list."
                                action={researchButton}
                            />
                        ) : null}

                        <SuggestionReview
                            suggestions={suggestions}
                            runs={councilRunHistory}
                        />

                        {hasSchools ? (
                            <>
                                <Section
                                    title="Your colleges"
                                    icon={ListChecks}
                                    description={
                                        colleges.length ===
                                        0 ? undefined : balanceWarning ? (
                                            <span className="inline-flex items-start gap-1.5 text-amber-700 dark:text-amber-300">
                                                <WarningCircle
                                                    aria-hidden
                                                    className="mt-px size-4 shrink-0"
                                                    weight="fill"
                                                />
                                                {balanceWarning}
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-start gap-1.5">
                                                <Scales
                                                    aria-hidden
                                                    className="mt-px size-4 shrink-0"
                                                />
                                                Reach / match / safety mix looks
                                                workable for a first pass.
                                            </span>
                                        )
                                    }
                                    actions={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="h-8 gap-1.5 rounded-full px-3 text-[13px]"
                                            disabled={compareIds.length < 2}
                                            title={
                                                compareIds.length < 2
                                                    ? 'Tick two to four colleges to compare'
                                                    : undefined
                                            }
                                            onClick={() =>
                                                router.visit(
                                                    `/college-lists/compare?ids=${compareIds.join(',')}`,
                                                )
                                            }
                                        >
                                            <Columns className="size-4" />
                                            Compare
                                            {compareIds.length > 0
                                                ? ` (${compareIds.length})`
                                                : ''}
                                        </Button>
                                    }
                                >
                                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                        <SegmentedTabs
                                            label="Filter by tier"
                                            value={tierFilter}
                                            onChange={setTierFilter}
                                            items={[
                                                {
                                                    value: 'all',
                                                    label: 'All',
                                                    count: colleges.length,
                                                },
                                                {
                                                    value: 'reach',
                                                    label: 'Reach',
                                                    count: tierCounts.reach,
                                                },
                                                {
                                                    value: 'match',
                                                    label: 'Match',
                                                    count: tierCounts.match,
                                                },
                                                {
                                                    value: 'safety',
                                                    label: 'Safety',
                                                    count: tierCounts.safety,
                                                },
                                            ]}
                                        />
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Select
                                                value={statusFilter}
                                                onValueChange={(v) =>
                                                    setStatusFilter(
                                                        v as typeof statusFilter,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    size="sm"
                                                    className="h-8 rounded-lg text-[13px]"
                                                    aria-label="Filter by list status"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="all">
                                                        All saved
                                                    </SelectItem>
                                                    <SelectItem value="shortlisted">
                                                        Shortlisted
                                                    </SelectItem>
                                                    <SelectItem value="applying">
                                                        Applying
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <Select
                                                value={affordabilityFilter}
                                                onValueChange={(v) =>
                                                    setAffordabilityFilter(
                                                        v as typeof affordabilityFilter,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    size="sm"
                                                    className="h-8 rounded-lg text-[13px]"
                                                    aria-label="Filter by affordability"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="any">
                                                        Any affordability
                                                    </SelectItem>
                                                    {(
                                                        Object.keys(
                                                            AFFORDABILITY_LABELS,
                                                        ) as AffordabilityStatus[]
                                                    ).map((status) => (
                                                        <SelectItem
                                                            key={status}
                                                            value={status}
                                                        >
                                                            {
                                                                AFFORDABILITY_LABELS[
                                                                    status
                                                                ]
                                                            }
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>

                                    <CollegeTable
                                        rows={visible}
                                        compareIds={compareIds}
                                        onToggleCompare={toggleCompare}
                                        runActive={runActive}
                                        removingId={removingId}
                                        onRemove={(id) => void removeSchool(id)}
                                        emptyMessage={emptyMessage}
                                    />
                                    {colleges.length > 0 ? (
                                        <p className="mt-3 px-1 text-[12.5px] leading-relaxed text-muted-foreground">
                                            {aggregateNetLens}
                                        </p>
                                    ) : null}
                                </Section>

                                <Section>
                                    <div className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                        <ExpandRow
                                            icon={
                                                <IconTile
                                                    icon={CalendarDots}
                                                    tone="blue"
                                                    variant="glyph"
                                                />
                                            }
                                            title="Deadlines by date"
                                            description="Pulled automatically from each college on your list — you don't enter them here."
                                        >
                                            {deadlineTracker.length === 0 ? (
                                                <p className="py-4 text-[13.5px] text-muted-foreground">
                                                    No saved colleges yet.
                                                </p>
                                            ) : (
                                                <KeyValueList>
                                                    {deadlineTracker.map(
                                                        (d) => {
                                                            const deadline =
                                                                deadlineOf(d);

                                                            return (
                                                                <KeyValueRow
                                                                    key={d.id}
                                                                    label={
                                                                        <Link
                                                                            href={collegeListsRoutes.school.url(
                                                                                d.id,
                                                                            )}
                                                                            className="underline-offset-2 hover:underline"
                                                                        >
                                                                            {
                                                                                d.name
                                                                            }
                                                                        </Link>
                                                                    }
                                                                    hint={
                                                                        TIERS[
                                                                            d
                                                                                .tier
                                                                        ].label
                                                                    }
                                                                    value={
                                                                        <span
                                                                            className={cn(
                                                                                'font-normal whitespace-pre-line',
                                                                                deadline.none &&
                                                                                    'text-muted-foreground',
                                                                            )}
                                                                        >
                                                                            {
                                                                                deadline.text
                                                                            }
                                                                        </span>
                                                                    }
                                                                />
                                                            );
                                                        },
                                                    )}
                                                </KeyValueList>
                                            )}
                                        </ExpandRow>
                                        <ExpandRow
                                            icon={
                                                <IconTile
                                                    icon={ClockCounterClockwise}
                                                    tone="slate"
                                                    variant="glyph"
                                                />
                                            }
                                            title="Research history"
                                            description={
                                                councilRunHistory.length === 0
                                                    ? 'Completed list builds will appear here.'
                                                    : `${councilRunHistory.length} recent ${councilRunHistory.length === 1 ? 'build' : 'builds'} by the list council`
                                            }
                                        >
                                            {councilRunHistory.length === 0 ? (
                                                <p className="py-4 text-[13.5px] text-muted-foreground">
                                                    Completed list builds will
                                                    appear here.
                                                </p>
                                            ) : (
                                                <ul className="divide-y divide-border">
                                                    {councilRunHistory.map(
                                                        (run) => (
                                                            <li
                                                                key={run.id}
                                                                className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 py-3.5"
                                                            >
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="text-[14px] leading-snug text-foreground">
                                                                        {run.summary_text ??
                                                                            '—'}
                                                                    </p>
                                                                    <p className="nums mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12.5px] text-muted-foreground">
                                                                        {run.status !==
                                                                        'completed' ? (
                                                                            <StatusBadge tone="danger">
                                                                                {
                                                                                    run.status
                                                                                }
                                                                            </StatusBadge>
                                                                        ) : null}
                                                                        <span>
                                                                            {joinParts(
                                                                                [
                                                                                    run.completed_at
                                                                                        ? hydrated
                                                                                            ? new Date(
                                                                                                  run.completed_at,
                                                                                              ).toLocaleString(
                                                                                                  undefined,
                                                                                                  {
                                                                                                      dateStyle:
                                                                                                          'medium',
                                                                                                      timeStyle:
                                                                                                          'short',
                                                                                                  },
                                                                                              )
                                                                                            : null
                                                                                        : '—',
                                                                                    `Schools linked to this run: ${run.school_count}`,
                                                                                ],
                                                                            )}
                                                                        </span>
                                                                    </p>
                                                                </div>
                                                                <Link
                                                                    href={`${collegeLists.url()}?run=${encodeURIComponent(run.id)}`}
                                                                    className="shrink-0 text-[13px] font-medium text-foreground underline-offset-2 hover:underline"
                                                                >
                                                                    View run
                                                                </Link>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </ExpandRow>
                                    </div>
                                </Section>
                            </>
                        ) : null}
                    </Page>
                </div>

                {!hasPaidAccess ? (
                    <div className="absolute inset-0 z-30 flex items-start justify-center bg-background/40 px-4 pt-24">
                        <div className="w-full max-w-sm rounded-2xl border border-border bg-card p-6 text-center shadow-raised">
                            <IconTile
                                icon={LockSimple}
                                tone="orange"
                                variant="gradient"
                                size="lg"
                                className="mx-auto"
                            />
                            <h2 className="mt-4 text-[17px] font-semibold text-foreground">
                                College list is a Pro feature
                            </h2>
                            <p className="mt-1.5 text-[13.5px] leading-relaxed text-muted-foreground">
                                Get personalized reach/match/safety
                                recommendations and deadline tracking
                            </p>
                            <Button
                                type="button"
                                className="mt-5 h-9 rounded-full px-5"
                                onClick={() => setPaywallOpen(true)}
                            >
                                Unlock now
                            </Button>
                        </div>
                    </div>
                ) : null}
            </div>

            <Dialog open={researchOpen} onOpenChange={setResearchOpen}>
                <DialogContent className="gap-5 sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="text-[17px] font-semibold">
                            Research colleges
                        </DialogTitle>
                        <DialogDescription className="text-[13.5px] leading-relaxed">
                            The list council looks for reach, match and safety
                            picks using your profile and what you write here.
                            Results arrive as suggestions — nothing joins your
                            list until you add it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4">
                        {hasSchools ? (
                            <div className="grid gap-2">
                                <OptionCards
                                    name="What to do with your list"
                                    options={{
                                        refine: 'Refine my list',
                                        generate: 'Start fresh',
                                    }}
                                    value={buildIntent}
                                    onChange={(value) => {
                                        if (
                                            value === 'refine' ||
                                            value === 'generate'
                                        ) {
                                            setBuildIntent(value);
                                        }
                                    }}
                                />
                                <p className="text-[12.5px] text-muted-foreground">
                                    {buildIntent === 'refine'
                                        ? 'Keeps your current list and suggests changes to it.'
                                        : 'Replaces earlier suggestions. Colleges you saved stay on your list.'}
                                </p>
                            </div>
                        ) : null}
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="research-prompt"
                                className="text-[13.5px]"
                            >
                                What should it look for?
                            </Label>
                            <textarea
                                id="research-prompt"
                                value={generatorPrompt}
                                onChange={(e) =>
                                    setGeneratorPrompt(e.target.value)
                                }
                                placeholder={
                                    hasSchools
                                        ? 'e.g. Add more safeties in-state…'
                                        : 'e.g. CS, West Coast, high aid need, test-optional friendly…'
                                }
                                rows={3}
                                className={textareaClass}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="research-facts"
                                className="text-[13.5px]"
                            >
                                Facts for this run{' '}
                                <span className="font-normal text-muted-foreground">
                                    ({hasSchools ? 'optional' : 'recommended'})
                                </span>
                            </Label>
                            <textarea
                                id="research-facts"
                                value={additionalContext}
                                onChange={(e) =>
                                    setAdditionalContext(e.target.value)
                                }
                                placeholder={
                                    hasSchools
                                        ? 'Budget, tests, deadlines constraints…'
                                        : 'e.g. EFC ~12k, need full aid at privates, applying REA to one school only…'
                                }
                                rows={2}
                                className={textareaClass}
                            />
                            <p className="text-[12.5px] leading-snug text-muted-foreground">
                                Anything not in your profile: exact budget, test
                                plan, citizenship, fee waivers, gap year. The
                                council may ask follow-ups in the live feed.
                            </p>
                        </div>
                        {generateError ? (
                            <ErrorPill message={generateError} />
                        ) : null}
                        {!councilEnabled ? (
                            <HintPill>
                                The list builder isn&apos;t available here — ask
                                your mentor to start a build for you.
                            </HintPill>
                        ) : null}
                    </div>
                    <DialogFooter className="gap-3 sm:items-center sm:justify-between">
                        {councilQuota && councilQuota.limit != null ? (
                            <p
                                className={cn(
                                    'nums text-[12.5px]',
                                    councilQuota.warning
                                        ? 'text-amber-700 dark:text-amber-300'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {councilQuota.used} of {councilQuota.limit} list
                                generations used
                                {feature_quotas?.window_days
                                    ? ` per ${feature_quotas.window_days} days`
                                    : ''}
                                .
                            </p>
                        ) : (
                            <span />
                        )}
                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-full px-4"
                                onClick={() => setResearchOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                className="rounded-full px-4"
                                disabled={generateDisabled}
                                onClick={() => void handleGenerate()}
                            >
                                {generateStatus === 'working'
                                    ? 'Starting…'
                                    : 'Start research'}
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={addSchoolOpen} onOpenChange={setAddSchoolOpen}>
                <DialogContent className="gap-5 sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="text-[17px] font-semibold">
                            Add a college
                        </DialogTitle>
                        <DialogDescription className="text-[13.5px]">
                            It goes straight onto your shortlist with the tier
                            you choose.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="manual-school-name"
                                className="text-[13.5px]"
                            >
                                College name
                            </Label>
                            <Input
                                id="manual-school-name"
                                value={manualName}
                                onChange={(e) => setManualName(e.target.value)}
                                placeholder="e.g. State University"
                                maxLength={100}
                                className="h-10 rounded-xl"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <span className="text-[13.5px] font-medium text-foreground">
                                Tier
                            </span>
                            <OptionCards
                                name="Tier"
                                columns={3}
                                options={{
                                    reach: 'Reach',
                                    match: 'Match',
                                    safety: 'Safety',
                                }}
                                value={manualTier}
                                onChange={(value) => {
                                    if (
                                        value === 'reach' ||
                                        value === 'match' ||
                                        value === 'safety'
                                    ) {
                                        setManualTier(value);
                                    }
                                }}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="manual-notes"
                                className="text-[13.5px]"
                            >
                                Notes{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <textarea
                                id="manual-notes"
                                value={manualNotes}
                                onChange={(e) => setManualNotes(e.target.value)}
                                rows={2}
                                className={textareaClass}
                                maxLength={1000}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-full px-4"
                            onClick={() => setAddSchoolOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="rounded-full px-4"
                            disabled={
                                manualSaving || manualName.trim().length < 2
                            }
                            onClick={() => void saveManualSchool()}
                        >
                            {manualSaving ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <PaywallModal open={paywallOpen} onOpenChange={setPaywallOpen} />
            <QuotaExhaustedModal
                open={quotaModalOpen}
                onOpenChange={setQuotaModalOpen}
                quota={quotaModalDetail}
                plan={feature_quotas?.plan}
            />
        </>
    );
}

CollegeLists.layout = {
    breadcrumbs: [{ title: 'College lists', href: collegeLists.url() }],
};
