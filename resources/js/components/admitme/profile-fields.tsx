'use client';

import { Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    CircleNotch,
    Info,
    WarningCircle,
    X,
} from '@phosphor-icons/react';
import { useId, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type GuidedField = {
    key: string;
    label: string;
    type:
        | 'string'
        | 'text'
        | 'integer'
        | 'decimal'
        | 'list'
        | 'currency'
        | 'json';
    options: Record<string, string> | null;
    value: string;
    unknown: boolean;
};

/** Placeholders and one-line hints that make each question concrete. */
const FIELD_HELP: Record<
    string,
    {
        placeholder?: string;
        hint?: string;
        suggestions?: string[];
        suffix?: string;
        prefix?: string;
    }
> = {
    citizenship_residency: {
        placeholder: 'e.g. Nigerian citizen, US permanent resident',
    },
    location_country: { placeholder: 'The country you live in now' },
    location_region: { placeholder: 'e.g. Lagos, Ontario, California' },
    graduation_year: { placeholder: 'e.g. 2027' },
    target_intake: {
        placeholder: 'e.g. Fall 2027',
        suggestions: ['Fall 2026', 'Fall 2027', 'Spring 2027'],
    },
    curriculum: {
        placeholder: 'e.g. IB Diploma, A-Levels, WAEC, CBSE',
        suggestions: [
            'IB Diploma',
            'A-Levels',
            'US high school diploma',
            'WAEC',
            'CBSE',
        ],
    },
    current_grade: { placeholder: 'e.g. Grade 12, Year 13' },
    gpa_value: { placeholder: 'As written on your report, e.g. 3.7 or 17.5' },
    gpa_scale: { placeholder: 'The top of your scale, e.g. 4.0, 20, 100' },
    sat_score: { placeholder: '400–1600' },
    act_score: { placeholder: '1–36' },
    test_optional_status: {
        placeholder: 'e.g. Taking the SAT in March, or not testing',
    },
    intended_majors: {
        placeholder: 'Type a subject and press Enter',
        hint: 'Up to three. Unsure is fine.',
        suggestions: [
            'Economics',
            'Computer Science',
            'Biology',
            'Engineering',
            'Undecided',
        ],
    },
    target_countries: {
        placeholder: 'Type a country and press Enter',
        suggestions: [
            'United States',
            'United Kingdom',
            'Canada',
            'Germany',
            'Netherlands',
        ],
    },
    region_preferences: {
        placeholder: 'e.g. Northeast US, London — press Enter',
    },
    budget_amount: { placeholder: 'Per year, all costs', prefix: '' },
    budget_currency: {
        placeholder: 'USD',
        suggestions: ['USD', 'GBP', 'EUR', 'CAD', 'NGN', 'INR'],
    },
    financial_aid_need_percent: {
        placeholder: '0–100',
        suffix: '%',
        suggestions: ['0', '25', '50', '75', '100'],
    },
};

export function HintPill({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <p className={cn('hint-pill', className)}>
            <Info className="size-3.5 shrink-0" />
            <span>{children}</span>
        </p>
    );
}

export function ErrorPill({
    message,
    className,
}: {
    message?: string;
    className?: string;
}) {
    if (!message) {
        return null;
    }

    return (
        <p role="alert" className={cn('error-pill', className)}>
            <WarningCircle className="size-3.5 shrink-0" weight="fill" />
            <span>{message}</span>
        </p>
    );
}

/** A square checkbox row, after the reference's settings checkboxes. */
export function CheckRow({
    checked,
    onChange,
    label,
    description,
    disabled,
    className,
    ariaLabel,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: ReactNode;
    description?: ReactNode;
    disabled?: boolean;
    className?: string;
    ariaLabel?: string;
}) {
    const id = useId();

    return (
        <label
            htmlFor={id}
            className={cn(
                'group flex cursor-pointer items-start gap-2.5 text-[13.5px] select-none',
                disabled && 'cursor-not-allowed opacity-60',
                className,
            )}
        >
            <span className="relative mt-[3px] flex size-4 shrink-0 items-center justify-center">
                <input
                    id={id}
                    type="checkbox"
                    className="peer sr-only"
                    checked={checked}
                    disabled={disabled}
                    aria-label={ariaLabel}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <span className="absolute inset-0 rounded-[4px] border border-foreground/25 bg-card transition-colors peer-checked:border-foreground peer-checked:bg-foreground peer-focus-visible:ring-2 peer-focus-visible:ring-ring/50" />
                <Check
                    className={cn(
                        'relative size-3 text-background transition-opacity',
                        checked ? 'opacity-100' : 'opacity-0',
                    )}
                    weight="bold"
                />
            </span>
            <span className="min-w-0">
                <span className="block leading-snug text-foreground">
                    {label}
                </span>
                {description ? (
                    <span className="mt-0.5 block text-[12.5px] leading-snug text-muted-foreground">
                        {description}
                    </span>
                ) : null}
            </span>
        </label>
    );
}

/** Single choice as soft grey cards (the reference's "access scope" options). */
export function OptionCards({
    name,
    options,
    value,
    onChange,
    disabled,
    columns = 2,
}: {
    name: string;
    options: Record<string, string>;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    columns?: 1 | 2 | 3 | 4;
}) {
    return (
        <div className="@container">
            <div
                role="radiogroup"
                aria-label={name}
                className={cn(
                    'grid gap-2',
                    columns === 1
                        ? 'grid-cols-1'
                        : columns === 3
                          ? 'grid-cols-1 @lg:grid-cols-3'
                          : columns === 4
                            ? 'grid-cols-2 @lg:grid-cols-4'
                            : 'grid-cols-1 @sm:grid-cols-2',
                )}
            >
                {Object.entries(options).map(([optionValue, label]) => {
                    const selected = value === optionValue;

                    return (
                        <button
                            key={optionValue}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            disabled={disabled}
                            onClick={() =>
                                onChange(selected ? '' : optionValue)
                            }
                            className={cn(
                                'flex min-h-11 items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-[13.5px] transition-[background-color,box-shadow,color] duration-150 disabled:opacity-50',
                                selected
                                    ? 'bg-card text-foreground shadow-raised ring-1 ring-foreground/15'
                                    : 'bg-muted text-foreground/85 hover:bg-foreground/[0.07]',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors',
                                    selected
                                        ? 'border-foreground bg-foreground'
                                        : 'border-foreground/25 bg-card',
                                )}
                            >
                                {selected ? (
                                    <span className="size-1.5 rounded-full bg-background" />
                                ) : null}
                            </span>
                            <span className="leading-snug">{label}</span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

const inputClass =
    'h-10 w-full rounded-xl border border-input bg-card px-3 text-[14px] text-foreground shadow-card outline-none transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground disabled:opacity-70 aria-invalid:border-destructive/60';

/** Comma-separated values typed as removable chips; the form still receives a comma-separated string. */
function ChipsInput({
    id,
    value,
    onChange,
    placeholder,
    disabled,
    max,
    invalid,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    disabled?: boolean;
    max?: number;
    invalid?: boolean;
}) {
    const chips = value
        .split(',')
        .map((v) => v.trim())
        .filter(Boolean);
    const [text, setText] = useState('');
    const full = max !== undefined && chips.length >= max;

    const commit = (raw: string) => {
        const next = raw.trim().replace(/,$/, '');

        if (
            next === '' ||
            chips.some((c) => c.toLowerCase() === next.toLowerCase()) ||
            full
        ) {
            setText('');

            return;
        }

        onChange([...chips, next].join(', '));
        setText('');
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            commit(text);
        } else if (e.key === 'Backspace' && text === '' && chips.length > 0) {
            onChange(chips.slice(0, -1).join(', '));
        }
    };

    return (
        <div
            className={cn(
                'flex min-h-10 w-full flex-wrap items-center gap-1.5 rounded-xl border border-input bg-card px-2 py-1.5 shadow-card transition-[border-color,box-shadow] focus-within:border-foreground/30 focus-within:ring-3 focus-within:ring-ring/25',
                disabled && 'bg-muted opacity-70',
                invalid && 'border-destructive/60',
            )}
        >
            {chips.map((chip) => (
                <span
                    key={chip}
                    className="inline-flex h-7 items-center gap-1 rounded-lg bg-muted pr-1 pl-2 text-[13px] text-foreground"
                >
                    {chip}
                    {!disabled ? (
                        <button
                            type="button"
                            aria-label={`Remove ${chip}`}
                            onClick={() =>
                                onChange(
                                    chips.filter((c) => c !== chip).join(', '),
                                )
                            }
                            className="rounded p-0.5 text-muted-foreground hover:bg-foreground/10 hover:text-foreground"
                        >
                            <X className="size-3" />
                        </button>
                    ) : null}
                </span>
            ))}
            <input
                id={id}
                value={text}
                disabled={disabled || full}
                onChange={(e) =>
                    e.target.value.endsWith(',')
                        ? commit(e.target.value)
                        : setText(e.target.value)
                }
                onKeyDown={onKeyDown}
                onBlur={() => commit(text)}
                placeholder={
                    full
                        ? `Up to ${max}`
                        : chips.length === 0
                          ? placeholder
                          : 'Add another'
                }
                className="h-7 min-w-[8rem] flex-1 bg-transparent px-1 text-[14px] text-foreground outline-none placeholder:text-muted-foreground/70"
            />
        </div>
    );
}

/** One guided question: label, the right control for its type, quick picks, "don't know" and its error. */
export function GuidedFieldControl({
    field,
    value,
    unknown,
    error,
    onChange,
    onUnknownChange,
    wide,
}: {
    field: GuidedField;
    value: string;
    unknown: boolean;
    error?: string;
    onChange: (value: string) => void;
    onUnknownChange: (unknown: boolean) => void;
    wide?: boolean;
}) {
    const id = `field-${field.key}`;
    const help = FIELD_HELP[field.key] ?? {};
    const numeric = field.type === 'integer' || field.type === 'decimal';
    const max = field.key === 'intended_majors' ? 3 : undefined;
    const optionCount = field.options ? Object.keys(field.options).length : 0;

    return (
        <div
            className={cn(
                'flex flex-col gap-2',
                (wide || field.options || field.type === 'list') &&
                    'sm:col-span-2',
            )}
        >
            <label
                htmlFor={field.options ? undefined : id}
                className="text-[13px] font-medium text-foreground"
                id={`${id}-label`}
            >
                {field.label}
            </label>

            {field.options ? (
                <OptionCards
                    name={field.label}
                    options={field.options}
                    value={value}
                    onChange={onChange}
                    disabled={unknown}
                    columns={optionCount === 3 ? 3 : optionCount === 4 ? 4 : 2}
                />
            ) : field.type === 'list' ? (
                <ChipsInput
                    id={id}
                    value={value}
                    onChange={onChange}
                    placeholder={help.placeholder}
                    disabled={unknown}
                    max={max}
                    invalid={!!error}
                />
            ) : (
                <div className="relative">
                    {help.prefix ? (
                        <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                            {help.prefix}
                        </span>
                    ) : null}
                    <input
                        id={id}
                        className={cn(
                            inputClass,
                            help.suffix && 'pr-8',
                            field.type === 'currency' && 'uppercase',
                        )}
                        disabled={unknown}
                        inputMode={numeric ? 'decimal' : undefined}
                        maxLength={field.type === 'currency' ? 3 : undefined}
                        placeholder={help.placeholder}
                        value={value}
                        aria-invalid={!!error || undefined}
                        onChange={(e) =>
                            onChange(
                                field.type === 'currency'
                                    ? e.target.value.toUpperCase()
                                    : e.target.value,
                            )
                        }
                    />
                    {help.suffix ? (
                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                            {help.suffix}
                        </span>
                    ) : null}
                </div>
            )}

            {help.suggestions && !unknown ? (
                <div
                    className="flex flex-wrap gap-1.5"
                    aria-label={`Suggestions for ${field.label}`}
                >
                    {help.suggestions.map((s) => {
                        const active =
                            field.type === 'list'
                                ? value
                                      .split(',')
                                      .map((v) => v.trim().toLowerCase())
                                      .includes(s.toLowerCase())
                                : value === s;

                        return (
                            <button
                                key={s}
                                type="button"
                                onClick={() => {
                                    if (field.type === 'list') {
                                        const chips = value
                                            .split(',')
                                            .map((v) => v.trim())
                                            .filter(Boolean);

                                        if (
                                            !active &&
                                            (max === undefined ||
                                                chips.length < max)
                                        ) {
                                            onChange([...chips, s].join(', '));
                                        }
                                    } else {
                                        onChange(s);
                                    }
                                }}
                                className={cn(
                                    'h-7 rounded-lg border px-2 font-mono text-[11.5px] transition-colors',
                                    active
                                        ? 'border-foreground/20 bg-foreground text-background'
                                        : 'border-border bg-card text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {field.key === 'financial_aid_need_percent'
                                    ? `${s}%`
                                    : s}
                            </button>
                        );
                    })}
                </div>
            ) : null}

            {help.hint ? (
                <p className="text-[12px] text-muted-foreground">{help.hint}</p>
            ) : null}

            <CheckRow
                checked={unknown}
                onChange={onUnknownChange}
                ariaLabel={`${field.label}: I don't know / not applicable`}
                label={
                    <span className="text-[12.5px] text-muted-foreground">
                        I don&apos;t know / not applicable
                    </span>
                }
            />
            <ErrorPill message={error} />
        </div>
    );
}

export function StepFooter({
    backHref,
    children,
}: {
    backHref?: string | null;
    children: ReactNode;
}) {
    return (
        <div className="mt-7 flex flex-wrap items-center justify-between gap-3 border-t border-border/70 pt-5">
            {backHref ? (
                <Link
                    href={backHref}
                    className="inline-flex h-10 items-center gap-1.5 rounded-xl px-3 text-[13.5px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back
                </Link>
            ) : (
                <span />
            )}
            <div className="flex flex-wrap items-center gap-2">{children}</div>
        </div>
    );
}

export function PrimaryButton({
    processing,
    children,
    type = 'submit',
    onClick,
    disabled,
}: {
    processing?: boolean;
    children: ReactNode;
    type?: 'submit' | 'button';
    onClick?: () => void;
    disabled?: boolean;
}) {
    return (
        <Button
            type={type}
            onClick={onClick}
            disabled={processing || disabled}
            className="h-10 rounded-full px-5 text-[14px] shadow-raised"
        >
            {processing ? (
                <CircleNotch className="size-4 animate-spin" />
            ) : null}
            {children}
            {!processing ? <ArrowRight className="size-4" /> : null}
        </Button>
    );
}

/** The fields of one guided section, saved through the shared profile service. */
export function GuidedSectionForm({
    fields,
    revision,
    action,
    backHref,
    skipHref,
    submitLabel = 'Save and continue',
    extraData,
}: {
    fields: GuidedField[];
    revision: number;
    action: string;
    backHref?: string | null;
    skipHref?: string | null;
    submitLabel?: string;
    extraData?: Record<string, unknown>;
}) {
    const initial: Record<string, unknown> = {
        expected_revision: revision,
        unknown: fields.filter((f) => f.unknown).map((f) => f.key),
        ...(extraData ?? {}),
    };
    fields.forEach((f) => {
        initial[f.key] = f.value;
    });
    const form = useForm(initial as Record<string, string | number | string[]>);
    const unknown = (form.data.unknown as string[]) ?? [];
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(action, { preserveScroll: true });
            }}
        >
            {errors.expected_revision ? (
                <ErrorPill
                    className="mb-4"
                    message={errors.expected_revision}
                />
            ) : null}
            <div className="grid gap-x-5 gap-y-6 sm:grid-cols-2">
                {fields.map((field) => (
                    <GuidedFieldControl
                        key={field.key}
                        field={field}
                        value={String(form.data[field.key] ?? '')}
                        unknown={unknown.includes(field.key)}
                        error={errors[field.key] ?? errors[`${field.key}.0`]}
                        onChange={(v) => form.setData(field.key, v)}
                        onUnknownChange={(checked) =>
                            form.setData(
                                'unknown',
                                checked
                                    ? [...unknown, field.key]
                                    : unknown.filter((k) => k !== field.key),
                            )
                        }
                    />
                ))}
            </div>
            <StepFooter backHref={backHref}>
                {skipHref ? (
                    <Link
                        href={skipHref}
                        className="inline-flex h-10 items-center rounded-full px-4 text-[13.5px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        Skip for now
                    </Link>
                ) : null}
                <PrimaryButton processing={form.processing}>
                    {submitLabel}
                </PrimaryButton>
            </StepFooter>
        </form>
    );
}
