import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Columns, ListMagnifyingGlass } from '@phosphor-icons/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    AffordabilityBadge,
    CollegeAssessmentView,
    ReasonCounts,
} from '@/components/admitme/college-assessment';
import {
    CHOICE_TONE,
    deadlineOf,
    formatDateOnly,
    joinParts,
    placeOf,
    TierIcon,
    TIERS,
} from '@/components/college/college-ui';
import {
    EmptyState,
    IconTile,
    Page,
    PageHeader,
    StatusBadge,
} from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { choiceStatusLabel } from '@/lib/college-list-display';
import type { CollegeRow } from '@/lib/college-list-display';
import { cn } from '@/lib/utils';
import { collegeLists } from '@/routes';

type ApplicationSummary = {
    id: string;
    status: string;
    deadline: {
        label: string;
        date: string;
        fromSource: boolean;
        basis: string;
    } | null;
    progress: {
        done: number;
        total: number;
        othersReceived: number;
        othersTotal: number;
    };
    nextAction: string | null;
    blockers: number;
};

type CompareRow = CollegeRow & { application: ApplicationSummary | null };

const REQUIREMENT_LABELS: Record<string, string> = {
    deadline: 'deadline',
    fee: 'application fee',
    test_policy: 'testing policy',
};

const muted = 'text-muted-foreground';

/** Side by side when there is room for every college; otherwise one attribute at a time, colleges stacked. */
const TABLE_AT: Record<number, { table: string; stacked: string }> = {
    2: { table: 'hidden @lg:block', stacked: '@lg:hidden' },
    3: { table: 'hidden @2xl:block', stacked: '@2xl:hidden' },
    4: { table: 'hidden @4xl:block', stacked: '@4xl:hidden' },
};

/**
 * Two to four saved colleges side by side, using the same fit check, sources and application state as
 * everywhere else — nothing here is computed differently.
 */
export default function CollegeCompare() {
    const { colleges } = usePage<{ colleges: CompareRow[] }>().props;
    const [showDetails, setShowDetails] = useState(false);

    const rows: { label: string; render: (c: CompareRow) => ReactNode }[] = [
        {
            label: 'List status',
            render: (c) => (
                <StatusBadge tone={CHOICE_TONE[c.choiceStatus]}>
                    {choiceStatusLabel(c.choiceStatus)}
                </StatusBadge>
            ),
        },
        {
            label: 'Location',
            render: (c) => {
                const place = joinParts([placeOf(c), c.assessment?.country]);

                return place !== '' ? (
                    place
                ) : (
                    <span className={muted}>Not on file</span>
                );
            },
        },
        {
            label: 'Next deadline',
            render: (c) => {
                if (c.application?.deadline) {
                    return (
                        <span>
                            {c.application.deadline.label} —{' '}
                            {formatDateOnly(c.application.deadline.date)}
                            <span
                                className={cn(
                                    'mt-0.5 block text-[12px] leading-snug',
                                    muted,
                                )}
                            >
                                {c.application.deadline.basis}
                            </span>
                        </span>
                    );
                }

                const deadline = deadlineOf(c);

                return deadline.none ? (
                    <span className={muted}>{deadline.text}</span>
                ) : (
                    <span className="whitespace-pre-line">
                        {deadline.text}
                        <span
                            className={cn(
                                'mt-0.5 block text-[12px] leading-snug',
                                muted,
                            )}
                        >
                            Saved from AI research — not verified
                        </span>
                    </span>
                );
            },
        },
        {
            label: 'Affordability',
            render: (c) =>
                c.assessment ? (
                    <div className="space-y-1.5">
                        <AffordabilityBadge
                            affordability={c.assessment.affordability}
                        />
                        {showDetails ? (
                            <p
                                className={cn(
                                    'text-[12.5px] leading-snug',
                                    muted,
                                )}
                            >
                                {c.assessment.affordability.summary}
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <span className={muted}>—</span>
                ),
        },
        {
            label: 'Fit check',
            render: (c) =>
                c.assessment ? (
                    <div className="space-y-3">
                        <ReasonCounts assessment={c.assessment} />
                        {showDetails ? (
                            <CollegeAssessmentView
                                assessment={{
                                    ...c.assessment,
                                    reasons: c.assessment.reasons.filter(
                                        (r) =>
                                            r.kind !== 'cost' &&
                                            r.kind !== 'budget',
                                    ),
                                }}
                                compact
                                showAffordability={false}
                            />
                        ) : null}
                    </div>
                ) : (
                    <span className={muted}>—</span>
                ),
        },
        {
            label: 'Requirements on file',
            render: (c) =>
                c.assessment ? (
                    <span>
                        {c.assessment.requirements.onFile} from sources
                        {c.assessment.requirements.conflicts > 0 ? (
                            <span className="block text-destructive">
                                {c.assessment.requirements.conflicts} in
                                conflict
                            </span>
                        ) : null}
                        {c.assessment.requirements.missingKeyTypes.length >
                        0 ? (
                            <span
                                className={cn(
                                    'mt-0.5 block text-[12px] leading-snug',
                                    muted,
                                )}
                            >
                                Missing:{' '}
                                {c.assessment.requirements.missingKeyTypes
                                    .map((t) => REQUIREMENT_LABELS[t] ?? t)
                                    .join(', ')}
                            </span>
                        ) : null}
                    </span>
                ) : (
                    <span className={muted}>—</span>
                ),
        },
        {
            label: 'Application',
            render: (c) =>
                c.application ? (
                    <span>
                        <Link
                            href={`/applications/${c.application.id}`}
                            className="font-medium text-foreground underline underline-offset-2 hover:decoration-2"
                        >
                            {c.application.progress.done} of{' '}
                            {c.application.progress.total} steps done
                        </Link>
                        {c.application.nextAction ? (
                            <span
                                className={cn(
                                    'mt-0.5 block text-[12px] leading-snug',
                                    muted,
                                )}
                            >
                                Next: {c.application.nextAction}
                            </span>
                        ) : null}
                    </span>
                ) : (
                    <span className={muted}>Not started</span>
                ),
        },
    ];

    const layout = TABLE_AT[Math.min(Math.max(colleges.length, 2), 4)];

    const collegeHeading = (c: CompareRow, compact = false) => (
        <div
            className={cn(
                'flex items-start',
                compact ? 'gap-2.5' : 'flex-col gap-2',
            )}
        >
            <TierIcon
                tier={c.tier}
                size={compact ? 'sm' : 'md'}
                className={compact ? 'mt-px' : undefined}
            />
            <div className="min-w-0">
                <Link
                    href={`/college-lists/${c.id}`}
                    className={cn(
                        'block leading-snug font-medium text-foreground underline-offset-2 hover:underline',
                        compact ? 'text-[13.5px]' : 'text-[14.5px]',
                    )}
                >
                    {c.name}
                </Link>
                {!compact ? (
                    <span
                        className={cn(
                            'nums mt-0.5 block text-[12.5px] font-normal',
                            muted,
                        )}
                    >
                        {joinParts([TIERS[c.tier].label, `Fit ${c.fitScore}`])}
                    </span>
                ) : null}
            </div>
        </div>
    );

    return (
        <>
            <Head title="Compare colleges" />
            <Page width="wide">
                <PageHeader
                    eyebrow={
                        <Link
                            href={collegeLists.url()}
                            className="inline-flex items-center gap-1 rounded-md transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <ArrowLeft className="size-3.5" aria-hidden />
                            College lists
                        </Link>
                    }
                    title="Compare colleges"
                    description="Reasons show where they come from: your profile, a source on file, or an unverified AI estimate."
                    actions={
                        colleges.length >= 2 ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="h-9 gap-2 rounded-full px-4"
                                aria-pressed={showDetails}
                                onClick={() => setShowDetails((v) => !v)}
                            >
                                <ListMagnifyingGlass className="size-4" />
                                {showDetails ? 'Hide details' : 'Show details'}
                            </Button>
                        ) : undefined
                    }
                />

                {colleges.length < 2 ? (
                    <EmptyState
                        icon={
                            <IconTile
                                icon={Columns}
                                tone="blue"
                                variant="glyph"
                                size="lg"
                            />
                        }
                        title="Pick colleges to compare"
                        description="Choose two to four saved colleges on your college list, then select Compare."
                        action={
                            <Button
                                variant="outline"
                                className="h-9 rounded-full px-4"
                                asChild
                            >
                                <Link href={collegeLists.url()}>
                                    Open college lists
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="@container">
                        <div
                            className={cn(
                                'overflow-hidden rounded-2xl border border-border bg-card',
                                layout.table,
                            )}
                        >
                            <table className="w-full table-fixed border-collapse text-left">
                                <thead>
                                    <tr className="border-b border-border">
                                        <th
                                            className="w-36 px-5 py-4 align-bottom"
                                            scope="col"
                                        >
                                            <span className="sr-only">
                                                Attribute
                                            </span>
                                        </th>
                                        {colleges.map((c) => (
                                            <th
                                                key={c.id}
                                                scope="col"
                                                className="px-4 py-4 align-top font-normal"
                                            >
                                                {collegeHeading(c)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {rows.map((row) => (
                                        <tr key={row.label}>
                                            <th
                                                scope="row"
                                                className="px-5 py-4 align-top text-[13px] font-normal text-muted-foreground"
                                            >
                                                {row.label}
                                            </th>
                                            {colleges.map((c) => (
                                                <td
                                                    key={c.id}
                                                    className="px-4 py-4 align-top text-[13.5px] leading-snug break-words text-foreground"
                                                >
                                                    {row.render(c)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className={cn('space-y-7', layout.stacked)}>
                            {rows.map((row) => (
                                <section key={row.label} aria-label={row.label}>
                                    <h2 className="mb-2 px-1 text-[13px] font-medium text-muted-foreground">
                                        {row.label}
                                    </h2>
                                    <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                        {colleges.map((c) => (
                                            <li
                                                key={c.id}
                                                className="px-4 py-3.5"
                                            >
                                                {collegeHeading(c, true)}
                                                <div className="mt-2 pl-[30px] text-[13.5px] leading-snug break-words text-foreground">
                                                    {row.render(c)}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    </div>
                )}
            </Page>
        </>
    );
}

CollegeCompare.layout = {
    breadcrumbs: [{ title: 'College lists', href: collegeLists.url() }],
};
