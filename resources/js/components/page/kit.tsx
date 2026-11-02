'use client';

/**
 * The AdmitMe page kit — every workspace page is built from these, so the product reads as one system.
 *
 *   <Page>                              content column (width: narrow | default | wide | full)
 *     <PageHeader title description actions />
 *     <Section title icon actions>      quiet sentence-case label with an icon, then content
 *       <ListCard>                      rounded list with dividers
 *         <ListRow icon={<IconTile icon={FileText} tone="orange" />} title description meta href />
 *       </ListCard>
 *       <KeyValueList>                  label ← → value rows (settings / details)
 *         <KeyValueRow label value />
 *       </KeyValueList>
 *     </Section>
 *   </Page>
 *
 * Also: SegmentedTabs (pill switcher), UnderlineTabs (top tabs), StatusBadge, EmptyState, Stat, FolderCard, Banner.
 */

import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { CaretRight } from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/* ─── Icons ─────────────────────────────────────────────────────────────────────────────── */

export type Tone =
    | 'blue'
    | 'green'
    | 'orange'
    | 'pink'
    | 'violet'
    | 'amber'
    | 'sky'
    | 'teal'
    | 'red'
    | 'slate';

const GLYPH: Record<Tone, string> = {
    blue: 'text-blue-600 dark:text-blue-400',
    green: 'text-emerald-600 dark:text-emerald-400',
    orange: 'text-orange-500 dark:text-orange-400',
    pink: 'text-pink-500 dark:text-pink-400',
    violet: 'text-violet-600 dark:text-violet-400',
    amber: 'text-amber-500 dark:text-amber-400',
    sky: 'text-sky-500 dark:text-sky-400',
    teal: 'text-teal-600 dark:text-teal-400',
    red: 'text-red-500 dark:text-red-400',
    slate: 'text-zinc-500 dark:text-zinc-400',
};

const SOFT: Record<Tone, string> = {
    blue: 'bg-blue-500/10',
    green: 'bg-emerald-500/10',
    orange: 'bg-orange-500/10',
    pink: 'bg-pink-500/10',
    violet: 'bg-violet-500/10',
    amber: 'bg-amber-500/12',
    sky: 'bg-sky-500/10',
    teal: 'bg-teal-500/10',
    red: 'bg-red-500/10',
    slate: 'bg-zinc-500/10',
};

const GRADIENT: Record<Tone, string> = {
    blue: 'from-blue-400 to-blue-600',
    green: 'from-emerald-400 to-emerald-600',
    orange: 'from-amber-400 to-orange-500',
    pink: 'from-pink-400 to-rose-500',
    violet: 'from-violet-400 to-indigo-500',
    amber: 'from-yellow-300 to-amber-500',
    sky: 'from-sky-300 to-sky-500',
    teal: 'from-teal-300 to-teal-600',
    red: 'from-orange-400 to-red-500',
    slate: 'from-zinc-400 to-zinc-600',
};

/**
 * A category icon. `glyph`: a two-tone coloured icon on its own (list rows); `soft`: on a tinted square;
 * `gradient`: white on a gradient square (tools / apps).
 */
export function IconTile({
    icon: IconComponent,
    tone = 'slate',
    variant = 'soft',
    size = 'md',
    className,
}: {
    icon: Icon;
    tone?: Tone;
    variant?: 'glyph' | 'soft' | 'gradient';
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}) {
    const box =
        size === 'sm'
            ? 'size-6 rounded-[7px]'
            : size === 'lg'
              ? 'size-11 rounded-[12px]'
              : 'size-9 rounded-[10px]';
    const glyph =
        size === 'sm' ? 'size-3.5' : size === 'lg' ? 'size-6' : 'size-[19px]';

    if (variant === 'glyph') {
        return (
            <IconComponent
                aria-hidden
                weight="duotone"
                className={cn(
                    size === 'sm'
                        ? 'size-5'
                        : size === 'lg'
                          ? 'size-8'
                          : 'size-7',
                    'shrink-0',
                    GLYPH[tone],
                    className,
                )}
            />
        );
    }

    if (variant === 'gradient') {
        return (
            <span
                aria-hidden
                className={cn(
                    'flex shrink-0 items-center justify-center bg-linear-to-br text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.25)]',
                    GRADIENT[tone],
                    box,
                    className,
                )}
            >
                <IconComponent weight="fill" className={glyph} />
            </span>
        );
    }

    return (
        <span
            aria-hidden
            className={cn(
                'flex shrink-0 items-center justify-center',
                SOFT[tone],
                box,
                className,
            )}
        >
            <IconComponent
                weight="duotone"
                className={cn(glyph, GLYPH[tone])}
            />
        </span>
    );
}

/* ─── Layout ────────────────────────────────────────────────────────────────────────────── */

const WIDTH = {
    narrow: 'max-w-2xl',
    default: 'max-w-4xl',
    wide: 'max-w-6xl',
    full: 'max-w-none',
} as const;

export function Page({
    children,
    width = 'default',
    className,
}: {
    children: ReactNode;
    width?: keyof typeof WIDTH;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'mx-auto w-full px-5 py-7 sm:px-8 sm:py-9',
                WIDTH[width],
                className,
            )}
        >
            {children}
        </div>
    );
}

export function PageHeader({
    title,
    description,
    actions,
    eyebrow,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    eyebrow?: ReactNode;
    className?: string;
}) {
    return (
        <header
            className={cn(
                'mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6',
                className,
            )}
        >
            <div className="min-w-0 flex-1">
                {eyebrow ? (
                    <div className="mb-1.5 text-[13px] text-muted-foreground">
                        {eyebrow}
                    </div>
                ) : null}
                <h1 className="text-[26px] leading-tight font-semibold tracking-tight text-foreground">
                    {title}
                </h1>
                {description ? (
                    <p className="mt-1.5 max-w-2xl text-[14px] leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            {actions ? (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            ) : null}
        </header>
    );
}

export function Section({
    title,
    icon: IconComponent,
    description,
    actions,
    children,
    className,
}: {
    title?: ReactNode;
    icon?: Icon;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('mb-9 last:mb-0', className)}>
            {title || actions ? (
                <div className="mb-3 flex items-end justify-between gap-3">
                    <div className="min-w-0">
                        {title ? (
                            <h2 className="flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground">
                                {IconComponent ? (
                                    <IconComponent className="size-4" />
                                ) : null}
                                {title}
                            </h2>
                        ) : null}
                        {description ? (
                            <p className="mt-1 text-[13px] text-muted-foreground/80">
                                {description}
                            </p>
                        ) : null}
                    </div>
                    {actions ? (
                        <div className="flex shrink-0 items-center gap-2">
                            {actions}
                        </div>
                    ) : null}
                </div>
            ) : null}
            {children}
        </section>
    );
}

/* ─── Lists ─────────────────────────────────────────────────────────────────────────────── */

export function ListCard({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <ul
            className={cn(
                'divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card',
                className,
            )}
        >
            {children}
        </ul>
    );
}

/** One row: icon, title, quiet description, optional meta (right) and trailing actions. Link or button when given href/onClick. */
export function ListRow({
    icon,
    title,
    description,
    meta,
    trailing,
    href,
    onClick,
    chevron,
    className,
}: {
    icon?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    meta?: ReactNode;
    trailing?: ReactNode;
    href?: NonNullable<InertiaLinkProps['href']>;
    onClick?: () => void;
    chevron?: boolean;
    className?: string;
}) {
    const body = (
        <>
            {icon ? (
                <span className="flex shrink-0 items-center">{icon}</span>
            ) : null}
            <span className="min-w-0 flex-1">
                <span className="line-clamp-2 block text-[15px] font-medium text-foreground sm:line-clamp-1">
                    {title}
                </span>
                {description ? (
                    <span className="mt-0.5 line-clamp-2 block text-[13.5px] leading-snug text-muted-foreground">
                        {description}
                    </span>
                ) : null}
                {meta ? (
                    <span className="nums mt-1 block text-[12.5px] text-muted-foreground sm:hidden">
                        {meta}
                    </span>
                ) : null}
            </span>
            {meta ? (
                <span className="nums hidden shrink-0 text-[13px] text-muted-foreground sm:inline">
                    {meta}
                </span>
            ) : null}
            {trailing ? (
                <span className="flex shrink-0 items-center gap-2">
                    {trailing}
                </span>
            ) : null}
            {(chevron ?? !!href) ? (
                <CaretRight
                    className="size-4 shrink-0 text-muted-foreground/60"
                    aria-hidden
                />
            ) : null}
        </>
    );
    const rowClass = cn(
        'flex w-full items-center gap-3 px-4 py-3.5 text-left sm:gap-4 sm:px-5 sm:py-4',
        (href || onClick) && 'transition-colors hover:bg-muted/60',
        className,
    );

    return (
        <li>
            {href ? (
                <Link href={href} className={rowClass}>
                    {body}
                </Link>
            ) : onClick ? (
                <button type="button" onClick={onClick} className={rowClass}>
                    {body}
                </button>
            ) : (
                <div className={rowClass}>{body}</div>
            )}
        </li>
    );
}

export function KeyValueList({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <dl className={cn('divide-y divide-border', className)}>{children}</dl>
    );
}

export function KeyValueRow({
    label,
    value,
    hint,
    action,
}: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 py-3">
            <dt className="min-w-0 text-[14px] text-foreground">
                {label}
                {hint ? (
                    <span className="mt-0.5 block text-[12.5px] text-muted-foreground">
                        {hint}
                    </span>
                ) : null}
            </dt>
            <dd className="flex min-w-0 items-center gap-2 text-right text-[14px] font-medium text-foreground">
                {value}
                {action}
            </dd>
        </div>
    );
}

/* ─── Small parts ───────────────────────────────────────────────────────────────────────── */

export type BadgeTone =
    'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';

const BADGE: Record<BadgeTone, string> = {
    neutral: 'bg-muted text-muted-foreground',
    success: 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
    warning: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    danger: 'bg-red-500/10 text-red-600 dark:text-red-300',
    info: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
    brand: 'bg-brand-soft text-brand',
};

export function StatusBadge({
    tone = 'neutral',
    icon: IconComponent,
    children,
    className,
}: {
    tone?: BadgeTone;
    icon?: Icon;
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-6 shrink-0 items-center gap-1 rounded-full px-2 text-[12px] font-medium whitespace-nowrap',
                BADGE[tone],
                className,
            )}
        >
            {IconComponent ? (
                <IconComponent className="size-3.5" weight="bold" />
            ) : null}
            {children}
        </span>
    );
}

export function EmptyState({
    icon,
    title,
    description,
    action,
    className,
}: {
    icon?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center rounded-2xl border border-dashed border-border px-6 py-12 text-center',
                className,
            )}
        >
            {icon ? <div className="mb-3">{icon}</div> : null}
            <p className="text-[15px] font-medium text-foreground">{title}</p>
            {description ? (
                <p className="mt-1 max-w-sm text-[13.5px] leading-relaxed text-muted-foreground">
                    {description}
                </p>
            ) : null}
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}

export function Stat({
    label,
    value,
    hint,
    href,
}: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    href?: string;
}) {
    const body = (
        <>
            <span className="text-[13px] text-muted-foreground">{label}</span>
            <span className="nums mt-1 block text-[24px] leading-none font-semibold tracking-tight text-foreground">
                {value}
            </span>
            {hint ? (
                <span className="mt-1.5 block text-[12.5px] text-muted-foreground">
                    {hint}
                </span>
            ) : null}
        </>
    );

    return href ? (
        <Link
            href={href}
            className="block rounded-2xl border border-border bg-card p-4 transition-colors hover:bg-muted/50"
        >
            {body}
        </Link>
    ) : (
        <div className="rounded-2xl border border-border bg-card p-4">
            {body}
        </div>
    );
}

/** A pill switcher (reference: "Folders | Tags"). Use for filters and views on one page. */
export function SegmentedTabs<T extends string>({
    items,
    value,
    onChange,
    className,
    label,
}: {
    items: { value: T; label: ReactNode; count?: number }[];
    value: T;
    onChange: (value: T) => void;
    className?: string;
    label: string;
}) {
    return (
        <div
            role="tablist"
            aria-label={label}
            className={cn(
                'inline-flex items-center gap-0.5 rounded-xl bg-muted p-1',
                className,
            )}
        >
            {items.map((item) => {
                const active = item.value === value;

                return (
                    <button
                        key={item.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(item.value)}
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-[9px] px-3 text-[13px] font-medium whitespace-nowrap transition-[background-color,color,box-shadow] duration-150',
                            active
                                ? 'bg-background text-foreground shadow-card'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {item.label}
                        {item.count !== undefined && item.count > 0 ? (
                            <span className="nums text-[12px] text-muted-foreground">
                                {item.count}
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

/** Top-of-page tabs with an underline (reference: "Overview  Market  Settings"). Each tab is a link. */
export function UnderlineTabs({
    items,
    className,
    label,
}: {
    items: { href: string; label: ReactNode; active: boolean; icon?: Icon }[];
    className?: string;
    label: string;
}) {
    return (
        <nav
            aria-label={label}
            className={cn(
                'scrollbar-none flex items-center gap-6 overflow-x-auto border-b border-border',
                className,
            )}
        >
            {items.map((item) => (
                <Link
                    key={item.href}
                    href={item.href}
                    aria-current={item.active ? 'page' : undefined}
                    className={cn(
                        '-mb-px inline-flex h-11 shrink-0 items-center gap-1.5 border-b-2 text-[14px] transition-colors',
                        item.active
                            ? 'border-foreground font-medium text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {item.icon ? <item.icon className="size-4" /> : null}
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}

/** A folder card (reference: knowledge base folders). */
export function FolderCard({
    title,
    count,
    tone = 'slate',
    icon,
    active,
    onClick,
}: {
    title: ReactNode;
    count: ReactNode;
    tone?: Tone;
    icon: Icon;
    active?: boolean;
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'group flex flex-col items-start gap-4 rounded-2xl border p-4 text-left transition-[background-color,border-color,box-shadow] duration-150',
                active
                    ? 'border-foreground/25 bg-card shadow-raised'
                    : 'border-border bg-card hover:bg-muted/50',
            )}
        >
            <span className="relative flex h-14 w-full items-end">
                <span className="absolute inset-x-0 bottom-0 h-11 rounded-[10px] bg-muted transition-transform duration-200 group-hover:-translate-y-0.5" />
                <span className="absolute bottom-[38px] left-0 h-3 w-12 rounded-t-[8px] bg-muted" />
                <span className="relative mb-2 ml-3">
                    <IconTile
                        icon={icon}
                        tone={tone}
                        size="sm"
                        variant="gradient"
                    />
                </span>
            </span>
            <span>
                <span className="block text-[14px] font-medium text-foreground">
                    {title}
                </span>
                <span className="nums block text-[12.5px] text-muted-foreground">
                    {count}
                </span>
            </span>
        </button>
    );
}

/** A soft gradient banner (reference: profile header). Content sits on top. */
export function Banner({
    children,
    className,
}: {
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'relative h-36 overflow-hidden rounded-2xl sm:h-40',
                className,
            )}
            style={{
                background:
                    'radial-gradient(60% 120% at 10% 0%, oklch(0.86 0.08 265 / 0.9), transparent 60%), radial-gradient(50% 110% at 95% 100%, oklch(0.84 0.1 300 / 0.85), transparent 60%), radial-gradient(40% 80% at 55% 40%, oklch(0.93 0.04 230), transparent 70%), linear-gradient(120deg, oklch(0.95 0.02 250), oklch(0.97 0.01 300))',
            }}
        >
            <span
                aria-hidden
                className="absolute inset-0 hidden bg-black/60 dark:block"
            />
            {children}
        </div>
    );
}
