'use client';

/**
 * Small shared parts for the writing, essay and gap tools: form controls that match the page kit, quiet notices,
 * a progress line, the plan overlay and a markdown helper. Pages are still built from `@/components/page/kit`.
 */

import {
    CaretDown,
    CircleNotch,
    Info,
    LockSimple,
    WarningCircle,
} from '@phosphor-icons/react';
import { useMemo } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import { cn } from '@/lib/utils';

/**
 * Text a tool saved in localStorage, read once after hydration so the server HTML and the first client render
 * match. Returns '' on the server and when nothing is stored.
 */
export function useStoredText(key: string): string {
    const hydrated = useHydrated();

    return useMemo(() => {
        if (!hydrated) {
            return '';
        }

        try {
            return localStorage.getItem(key) ?? '';
        } catch {
            return '';
        }
    }, [hydrated, key]);
}

/** Text inputs and native selects (same look as the documents page). */
export const fieldClass =
    'h-10 w-full min-w-0 rounded-xl border border-input bg-background px-3 text-[14px] text-foreground outline-none transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-60 dark:scheme-dark';

export const textareaClass =
    'block w-full min-w-0 resize-y rounded-xl border border-input bg-background px-3 py-2.5 text-[14px] leading-relaxed text-foreground outline-none transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25';

/** Radix select triggers sized like the inputs above. */
export const selectTriggerClass =
    'w-full rounded-xl bg-background px-3 text-[14px] data-[size=default]:h-10 dark:bg-background';

/** One calm surface for a tool's inputs. */
export const toolCardClass =
    'rounded-2xl border border-border bg-card p-5 sm:p-6';

/** A label above a control, with an optional quiet hint below. */
export function Field({
    label,
    htmlFor,
    id,
    hint,
    children,
    className,
}: {
    label: ReactNode;
    htmlFor?: string;
    id?: string;
    hint?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('min-w-0', className)}>
            <label
                id={id}
                htmlFor={htmlFor}
                className="mb-1.5 block text-[13px] text-muted-foreground"
            >
                {label}
            </label>
            {children}
            {hint ? (
                <p className="mt-1.5 text-[12.5px] leading-snug text-muted-foreground">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

/** "(optional)" after a label. */
export function Optional() {
    return <span className="text-muted-foreground/70"> (optional)</span>;
}

/** A native select with a quiet caret (kept native so browser tests and phones get the real control). */
export function NativeSelect({
    className,
    children,
    wrapperClassName,
    ...props
}: ComponentProps<'select'> & { wrapperClassName?: string }) {
    return (
        <span className={cn('relative block min-w-0', wrapperClassName)}>
            <select
                {...props}
                className={cn(
                    fieldClass,
                    'cursor-pointer appearance-none truncate pr-9',
                    className,
                )}
            >
                {children}
            </select>
            <CaretDown
                aria-hidden
                className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
        </span>
    );
}

type NoticeTone = 'neutral' | 'warning' | 'danger';

const NOTICE: Record<NoticeTone, string> = {
    neutral: 'bg-muted text-foreground',
    warning: 'bg-amber-500/10 text-amber-900 dark:text-amber-100',
    danger: 'bg-destructive/10 text-destructive',
};

/** A soft inline message (no border). */
export function Notice({
    tone = 'neutral',
    children,
    className,
    role,
}: {
    tone?: NoticeTone;
    children: ReactNode;
    className?: string;
    role?: 'status' | 'alert' | 'note';
}) {
    const Icon = tone === 'neutral' ? Info : WarningCircle;

    return (
        <div
            role={role}
            className={cn(
                'flex items-start gap-2.5 rounded-xl px-3.5 py-3 text-[13.5px] leading-relaxed',
                NOTICE[tone],
                className,
            )}
        >
            <Icon
                className="mt-[3px] size-4 shrink-0"
                weight={tone === 'neutral' ? 'regular' : 'fill'}
                aria-hidden
            />
            <div className="min-w-0">{children}</div>
        </div>
    );
}

/** A "working on it" line with a spinner, for background jobs and model calls. */
export function Working({
    title,
    detail,
    hint,
    className,
}: {
    title: ReactNode;
    detail?: ReactNode;
    hint?: ReactNode;
    className?: string;
}) {
    return (
        <div
            aria-live="polite"
            className={cn(
                'flex items-start gap-3 rounded-2xl bg-muted/70 px-4 py-3.5',
                className,
            )}
        >
            <CircleNotch
                className="mt-0.5 size-4 shrink-0 animate-spin text-muted-foreground"
                aria-hidden
            />
            <div className="min-w-0 text-[13.5px] leading-relaxed">
                <p className="font-medium text-foreground">{title}</p>
                {detail ? (
                    <p className="text-muted-foreground">{detail}</p>
                ) : null}
                {hint ? (
                    <p className="mt-1 text-[12.5px] text-muted-foreground/80">
                        {hint}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

/** Shown over a plan-gated page; the content underneath stays blurred and inert. */
export function PlanOverlay({
    title,
    description,
    onUnlock,
}: {
    title: string;
    description: string;
    onUnlock: () => void;
}) {
    return (
        <div className="absolute inset-0 z-30 flex justify-center bg-background/60 px-4 pt-24 supports-backdrop-filter:backdrop-blur-[2px] sm:pt-32">
            <section
                aria-labelledby="plan-overlay-title"
                className="sticky top-24 h-fit w-full max-w-sm rounded-2xl border border-border bg-card p-6 text-center shadow-raised"
            >
                <span className="mx-auto flex size-11 items-center justify-center rounded-full bg-muted">
                    <LockSimple
                        className="size-5 text-foreground"
                        weight="duotone"
                        aria-hidden
                    />
                </span>
                <h2
                    id="plan-overlay-title"
                    className="mt-4 text-[16px] font-semibold text-foreground"
                >
                    {title}
                </h2>
                <p className="mt-1.5 text-[13.5px] leading-relaxed text-muted-foreground">
                    {description}
                </p>
                <Button
                    type="button"
                    className="mt-5 h-10 w-full rounded-xl"
                    onClick={onUnlock}
                >
                    Unlock now
                </Button>
            </section>
        </div>
    );
}

/**
 * For model output shown as a document: calmer headings than the shared markdown defaults (no rules under h2s,
 * smaller sizes), applied through the renderer's wrapper so the shared component stays untouched.
 */
export const proseDocClass =
    'text-[14.5px] [&_h2]:mt-7 [&_h2]:mb-2 [&_h2]:border-0 [&_h2]:pb-0 [&_h2]:text-[17px] [&_h2]:font-semibold [&_h2]:tracking-tight [&_h3]:mt-5 [&_h3]:mb-1.5 [&_h3]:text-[15px] [&_h4]:text-[14.5px]';

/** Model output may start with "# Title"; the page already has its one h1, so headings start at h2. */
export function demoteHeadings(markdown: string): string {
    return markdown
        .split(/(```[\s\S]*?```)/g)
        .map((part) =>
            part.startsWith('```') ? part : part.replace(/^#(?=\s)/gm, '##'),
        )
        .join('');
}

/** "reach" → "Reach". */
export function sentenceCase(value: string): string {
    return value === ''
        ? value
        : value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');
}
