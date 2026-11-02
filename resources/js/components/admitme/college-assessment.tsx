import {
    ArrowSquareOut,
    CheckCircle,
    Info,
    Question,
    WarningCircle,
} from '@phosphor-icons/react';
import { StatusBadge } from '@/components/page/kit';
import type { BadgeTone } from '@/components/page/kit';
import { AFFORDABILITY_LABELS } from '@/lib/college-assessment';
import type {
    Affordability,
    AffordabilityStatus,
    AssessmentReason,
    CollegeAssessment,
} from '@/lib/college-assessment';
import { cn } from '@/lib/utils';

const REASON_ICONS = {
    good: {
        Icon: CheckCircle,
        tone: 'text-emerald-600 dark:text-emerald-400',
        label: 'Fits',
    },
    concern: {
        Icon: WarningCircle,
        tone: 'text-red-500 dark:text-red-400',
        label: 'Concern',
    },
    unknown: {
        Icon: Question,
        tone: 'text-amber-500 dark:text-amber-400',
        label: 'Unknown',
    },
    info: { Icon: Info, tone: 'text-muted-foreground', label: 'Note' },
} as const;

const AFFORDABILITY_TONE: Record<AffordabilityStatus, BadgeTone> = {
    within_budget: 'success',
    needs_aid: 'warning',
    over_budget: 'danger',
    incomplete: 'neutral',
};

/**
 * The affordability verdict as a quiet pill. `short` drops the word "Affordability" where a column
 * header already says it.
 */
export function AffordabilityBadge({
    affordability,
    className,
    short = false,
}: {
    affordability: Affordability;
    className?: string;
    short?: boolean;
}) {
    const label =
        short && affordability.status === 'incomplete'
            ? 'Unknown'
            : AFFORDABILITY_LABELS[affordability.status];

    return (
        <StatusBadge
            tone={AFFORDABILITY_TONE[affordability.status]}
            className={className}
        >
            {label}
            {!affordability.complete &&
            affordability.status !== 'incomplete' ? (
                <span className="font-normal opacity-80">· incomplete</span>
            ) : null}
        </StatusBadge>
    );
}

/** "✓ 2  ⚠ 1  ? 4" — how many reasons fit, concern, or can't be checked yet. */
export function ReasonCounts({
    assessment,
    className,
}: {
    assessment: CollegeAssessment;
    className?: string;
}) {
    const items = [
        {
            key: 'good',
            n: assessment.counts.good,
            ...REASON_ICONS.good,
            text: 'good',
        },
        {
            key: 'concern',
            n: assessment.counts.concern,
            ...REASON_ICONS.concern,
            text: assessment.counts.concern === 1 ? 'concern' : 'concerns',
        },
        {
            key: 'unknown',
            n: assessment.counts.unknown,
            ...REASON_ICONS.unknown,
            text: 'unknown',
        },
    ].filter((item) => item.n > 0);

    if (items.length === 0) {
        return (
            <span
                className={cn('text-[13px] text-muted-foreground', className)}
            >
                No reasons yet
            </span>
        );
    }

    return (
        <span
            className={cn(
                'inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted-foreground',
                className,
            )}
        >
            {items.map(({ key, n, Icon, tone, text }) => (
                <span key={key} className="nums inline-flex items-center gap-1">
                    <Icon
                        aria-hidden
                        className={cn('size-3.5', tone)}
                        weight="fill"
                    />
                    {n} {text}
                </span>
            ))}
        </span>
    );
}

function ReasonRow({ reason }: { reason: AssessmentReason }) {
    const { Icon, tone, label } = REASON_ICONS[reason.status];

    return (
        <li className="flex gap-2.5">
            <Icon
                className={cn('mt-[3px] size-4 shrink-0', tone)}
                aria-label={label}
                weight="fill"
            />
            <div className="min-w-0">
                <p className="text-[13.5px] leading-snug text-foreground">
                    {reason.text}
                </p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                    {reason.source?.url ? (
                        <a
                            href={reason.source.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-0.5 underline-offset-2 hover:text-foreground hover:underline"
                        >
                            {reason.basisLabel}
                            <ArrowSquareOut className="size-3" aria-hidden />
                        </a>
                    ) : (
                        reason.basisLabel
                    )}
                </p>
            </div>
        </li>
    );
}

/**
 * Why a college does or doesn't fit, with where each reason came from, and an honest affordability view.
 * `compact` keeps the four most telling reasons; `showAffordability={false}` leaves affordability to its own row.
 */
export function CollegeAssessmentView({
    assessment,
    compact = false,
    showBadge = true,
    showAffordability = true,
    className,
}: {
    assessment: CollegeAssessment;
    compact?: boolean;
    showBadge?: boolean;
    showAffordability?: boolean;
    className?: string;
}) {
    const reasons = compact
        ? assessment.reasons.filter((r) => r.status !== 'info').slice(0, 4)
        : assessment.reasons;
    const { affordability } = assessment;
    // The summary usually already names what is missing; only list what it doesn't say.
    const missing = affordability.missing.filter(
        (item) => !affordability.summary.includes(item),
    );

    return (
        <div className={cn('space-y-3', className)}>
            {showAffordability ? (
                <div className="space-y-1.5">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                        {showBadge ? (
                            <AffordabilityBadge affordability={affordability} />
                        ) : null}
                        <span className="text-[13px] leading-snug text-muted-foreground">
                            {affordability.summary}
                        </span>
                    </div>
                    {!affordability.complete &&
                    missing.length > 0 &&
                    !compact ? (
                        <p className="text-[12.5px] text-muted-foreground">
                            Still needed for a complete affordability check:{' '}
                            {missing.join('; ')}.
                        </p>
                    ) : null}
                </div>
            ) : null}
            {reasons.length > 0 ? (
                <ul className="space-y-2.5" aria-label="Reasons">
                    {reasons.map((reason, index) => (
                        <ReasonRow
                            key={`${reason.kind}-${index}`}
                            reason={reason}
                        />
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
