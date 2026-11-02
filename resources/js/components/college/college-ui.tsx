'use client';

/**
 * Small presentation pieces shared by the college pages (list, detail, compare, suggestions review).
 */

import {
    CaretRight,
    Mountains,
    ShieldCheck,
    Target,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { IconTile } from '@/components/page/kit';
import type { BadgeTone, Tone } from '@/components/page/kit';
import type {
    CollegeChoiceStatus,
    CollegeRow,
    FitTier,
} from '@/lib/college-list-display';
import { cn } from '@/lib/utils';

export const TIERS: Record<FitTier, { label: string; icon: Icon; tone: Tone }> =
    {
        reach: { label: 'Reach', icon: Mountains, tone: 'violet' },
        match: { label: 'Match', icon: Target, tone: 'blue' },
        safety: { label: 'Safety', icon: ShieldCheck, tone: 'green' },
    };

export const CHOICE_TONE: Record<CollegeChoiceStatus, BadgeTone> = {
    suggested: 'brand',
    shortlisted: 'neutral',
    applying: 'info',
    archived: 'neutral',
};

/** The tier's two-tone icon (violet mountains, blue target, green shield). */
export function TierIcon({
    tier,
    size = 'md',
    className,
}: {
    tier: FitTier;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}) {
    const style = TIERS[tier];

    return (
        <IconTile
            icon={style.icon}
            tone={style.tone}
            variant="glyph"
            size={size}
            className={className}
        />
    );
}

/** The saved location, or null when only a placeholder is stored. */
export function placeOf(row: Pick<CollegeRow, 'location'>): string | null {
    const value = (row.location ?? '').trim();

    return value !== '' && value !== '—' ? value : null;
}

/** Deadline text for display; `none` when nothing is saved (the server sends "— No deadlines saved"). */
export function deadlineOf(
    row: Pick<CollegeRow, 'deadlinesDisplay' | 'deadline'>,
): { text: string; none: boolean } {
    const raw =
        (row.deadlinesDisplay ?? '').trim() || (row.deadline ?? '').trim();

    if (raw === '' || raw === '—') {
        return { text: 'No deadlines saved', none: true };
    }

    if (raw.startsWith('—')) {
        return { text: raw.replace(/^—\s*/, ''), none: true };
    }

    return { text: raw, none: false };
}

/** "Text · text" with empty parts dropped. */
export function joinParts(
    parts: (string | null | undefined | false)[],
): string {
    return parts
        .filter(
            (part): part is string =>
                typeof part === 'string' && part.trim() !== '',
        )
        .join(' · ');
}

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** A calendar date (YYYY-MM-DD) as "Feb 1, 2027" — the same on the server and in the browser. */
export function formatDateOnly(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    if (!match) {
        return value;
    }

    const month = MONTHS[Number(match[2]) - 1];

    return month ? `${month} ${Number(match[3])}, ${match[1]}` : value;
}

/** A quiet native disclosure: a caret, a short label, then the content. */
export function Disclosure({
    summary,
    children,
    className,
    contentClassName,
    defaultOpen,
}: {
    summary: ReactNode;
    children: ReactNode;
    className?: string;
    contentClassName?: string;
    defaultOpen?: boolean;
}) {
    return (
        <details
            className={cn('group/disclosure', className)}
            open={defaultOpen}
        >
            <summary className="inline-flex cursor-pointer list-none items-center gap-1 rounded-md text-[13px] text-muted-foreground transition-colors select-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                <CaretRight
                    aria-hidden
                    className="size-3.5 transition-transform duration-150 group-open/disclosure:rotate-90"
                    weight="bold"
                />
                {summary}
            </summary>
            <div className={cn('mt-2.5', contentClassName)}>{children}</div>
        </details>
    );
}

/** A soft callout for "check this" notes (unresolved facts, list balance, conflicts). */
export function Callout({
    tone = 'warning',
    icon: IconComponent,
    title,
    children,
    action,
    className,
}: {
    tone?: 'warning' | 'danger' | 'neutral';
    icon?: Icon;
    title?: ReactNode;
    children?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    const palette =
        tone === 'danger'
            ? 'bg-red-500/[0.07] text-red-700 dark:text-red-300'
            : tone === 'warning'
              ? 'bg-amber-500/[0.09] text-amber-800 dark:text-amber-200'
              : 'bg-muted text-muted-foreground';

    return (
        <div
            className={cn(
                'flex flex-col gap-3 rounded-xl px-4 py-3 sm:flex-row sm:items-start',
                palette,
                className,
            )}
        >
            <div className="flex min-w-0 flex-1 gap-2.5">
                {IconComponent ? (
                    <IconComponent
                        aria-hidden
                        className="mt-0.5 size-4 shrink-0"
                        weight="fill"
                    />
                ) : null}
                <div className="min-w-0 text-[13.5px] leading-snug">
                    {title ? <p className="font-medium">{title}</p> : null}
                    {children ? (
                        <div
                            className={cn(
                                title && 'mt-1',
                                'text-foreground/80',
                            )}
                        >
                            {children}
                        </div>
                    ) : null}
                </div>
            </div>
            {action ? <div className="shrink-0">{action}</div> : null}
        </div>
    );
}
