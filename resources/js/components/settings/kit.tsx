'use client';

/**
 * Small parts shared by the settings pages and the account pages next to them (notifications, exports),
 * on top of the page kit: form rows, a save footer, flash notices and a switch that posts with a form.
 */

import {
    Check,
    CheckCircle,
    Info,
    WarningCircle,
    XCircle,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { AppLayoutHeaderTrailing } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

/**
 * The header crumb for a settings tab. One level, like the other pages: the header's gear icon already
 * says "Settings" and the page title repeats it.
 */
export function settingsBreadcrumbs(
    title: string,
    href: string,
): { title: string; href: string }[] {
    return [{ title, href }];
}

/** Text inputs and select triggers on settings pages. */
export const fieldClass =
    'h-10 rounded-xl bg-card px-3 text-[14px] shadow-card dark:bg-input/30';

/** Rows between hairlines (the label ←→ control lists of the settings pages). */
export function RowList({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'divide-y divide-border border-y border-border',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** A form field: label and hint on the left, the control on the right (stacked on phones). */
export function FieldRow({
    label,
    htmlFor,
    hint,
    children,
}: {
    label: ReactNode;
    htmlFor?: string;
    hint?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-x-8 gap-y-2 py-4 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)] sm:items-start">
            <div className="min-w-0 sm:pt-2.5">
                <label
                    htmlFor={htmlFor}
                    className="text-[14px] font-medium text-foreground"
                >
                    {label}
                </label>
                {hint ? (
                    <div className="mt-0.5 text-[12.5px] leading-snug text-muted-foreground">
                        {hint}
                    </div>
                ) : null}
            </div>
            <div className="min-w-0">{children}</div>
        </div>
    );
}

/**
 * A setting with a title, a quiet description and its control or action on the right. `inline` keeps the
 * control beside the text on phones too (switches); otherwise it moves under the text.
 */
export function SettingRow({
    title,
    description,
    htmlFor,
    children,
    inline = false,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    htmlFor?: string;
    children?: ReactNode;
    inline?: boolean;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex gap-x-8 gap-y-3 py-4',
                inline
                    ? 'items-center justify-between'
                    : 'flex-col sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
        >
            <div className="min-w-0">
                {htmlFor ? (
                    <label
                        htmlFor={htmlFor}
                        className="block text-[14px] font-medium text-foreground"
                    >
                        {title}
                    </label>
                ) : (
                    <p className="text-[14px] font-medium text-foreground">
                        {title}
                    </p>
                )}
                {description ? (
                    <div className="mt-0.5 text-[13px] leading-snug text-muted-foreground">
                        {description}
                    </div>
                ) : null}
            </div>
            {children ? (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {children}
                </div>
            ) : null}
        </div>
    );
}

/** The bottom of a form: an optional "Saved" note and the actions, right-aligned. */
export function FormFooter({
    saved,
    children,
    className,
}: {
    saved?: boolean;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-end gap-3 pt-4',
                className,
            )}
        >
            {saved ? (
                <span
                    role="status"
                    className="inline-flex animate-rise items-center gap-1 text-[13px] text-muted-foreground"
                >
                    <Check className="size-3.5 text-success" weight="bold" />
                    Saved
                </span>
            ) : null}
            {children}
        </div>
    );
}

/** Pill buttons used across these pages. */
export const pillButton = 'h-9 rounded-full px-4';

/**
 * While a form has unsaved changes, a "Discard" + "Save changes" pair sits at the right of the page header
 * (desktop). The save button submits the form by id, so it posts exactly what the form's own button posts.
 */
export function UnsavedChanges({
    dirty,
    formId,
    processing,
    onDiscard,
}: {
    dirty: boolean;
    formId: string;
    processing?: boolean;
    onDiscard: () => void;
}) {
    if (!dirty) {
        return null;
    }

    return (
        <AppLayoutHeaderTrailing>
            <div className="hidden items-center gap-2 sm:flex">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-full px-3.5"
                    onClick={onDiscard}
                >
                    Discard
                </Button>
                <Button
                    type="submit"
                    form={formId}
                    size="sm"
                    className="rounded-full px-3.5"
                    disabled={processing}
                >
                    Save changes
                </Button>
            </div>
        </AppLayoutHeaderTrailing>
    );
}

type NoticeTone = 'success' | 'info' | 'warning' | 'danger';

const NOTICE: Record<
    NoticeTone,
    { icon: Icon; className: string; iconClass: string }
> = {
    success: {
        icon: CheckCircle,
        className: 'bg-emerald-500/8 text-foreground',
        iconClass: 'text-emerald-600 dark:text-emerald-400',
    },
    info: {
        icon: Info,
        className: 'bg-muted text-foreground',
        iconClass: 'text-muted-foreground',
    },
    warning: {
        icon: WarningCircle,
        className: 'bg-amber-500/10 text-foreground',
        iconClass: 'text-amber-600 dark:text-amber-400',
    },
    danger: {
        icon: XCircle,
        className: 'bg-red-500/8 text-foreground',
        iconClass: 'text-red-600 dark:text-red-400',
    },
};

/** A short message after an action (flash status) or about the account's state. */
export function Notice({
    tone = 'info',
    title,
    children,
    className,
}: {
    tone?: NoticeTone;
    title?: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    const style = NOTICE[tone];

    return (
        <div
            role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'}
            className={cn(
                'flex items-start gap-2.5 rounded-xl px-3.5 py-3 text-[13.5px] leading-snug',
                style.className,
                className,
            )}
        >
            <style.icon
                className={cn('mt-px size-[18px] shrink-0', style.iconClass)}
                weight="fill"
            />
            <div className="min-w-0">
                {title ? (
                    <p className="font-medium text-foreground">{title}</p>
                ) : null}
                {children ? (
                    <div
                        className={cn(
                            title
                                ? 'mt-0.5 text-muted-foreground'
                                : 'text-foreground/90',
                        )}
                    >
                        {children}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

/**
 * A real checkbox drawn as a switch, so it still posts `name=value` with its form (and keeps its own
 * label, focus ring and disabled state).
 */
export function SwitchInput({
    className,
    ...props
}: Omit<ComponentProps<'input'>, 'type'>) {
    return (
        <span
            className={cn(
                'relative inline-flex h-[18px] w-8 shrink-0',
                className,
            )}
        >
            <input
                type="checkbox"
                role="switch"
                className="peer absolute inset-0 m-0 size-full cursor-pointer appearance-none rounded-full bg-input transition-colors outline-none checked:bg-foreground focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/80 dark:checked:bg-foreground"
                {...props}
            />
            <span
                aria-hidden
                className="pointer-events-none absolute top-px left-px size-4 rounded-full bg-background shadow-sm transition-transform duration-150 peer-checked:translate-x-[14px] dark:bg-foreground dark:peer-checked:bg-background"
            />
        </span>
    );
}

/** A real checkbox in the house style (ticks with a check mark). Pass `aria-label` when it has no label. */
export function CheckboxInput({
    className,
    ...props
}: Omit<ComponentProps<'input'>, 'type'>) {
    return (
        <span
            className={cn(
                'relative inline-flex size-[18px] shrink-0',
                className,
            )}
        >
            <input
                type="checkbox"
                className="peer absolute inset-0 m-0 size-full cursor-pointer appearance-none rounded-[5px] border border-foreground/25 bg-card transition-colors outline-none checked:border-foreground checked:bg-foreground focus-visible:ring-3 focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                {...props}
            />
            <Check
                aria-hidden
                weight="bold"
                className="pointer-events-none absolute inset-0 m-auto size-3 text-background opacity-0 transition-opacity peer-checked:opacity-100"
            />
        </span>
    );
}
