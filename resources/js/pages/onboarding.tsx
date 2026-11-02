'use client';

import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CheckCircle,
    Circle,
    CircleNotch,
    FileArrowUp,
    SignOut,
    WarningCircle,
} from '@phosphor-icons/react';
import { useEffect, useRef, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';
import {
    CheckRow,
    ErrorPill,
    GuidedSectionForm,
    HintPill,
    OptionCards,
    PrimaryButton,
    StepFooter,
} from '@/components/admitme/profile-fields';
import type { GuidedField } from '@/components/admitme/profile-fields';
import { LogoMark } from '@/components/brand/logo-mark';
import { StepFigure } from '@/components/figures/figures';
import ModeToggle from '@/components/mode-toggle';
import { cn } from '@/lib/utils';

type Step = {
    key: string;
    title: string;
    intro: string;
    completed: boolean;
    fields: GuidedField[];
};

type OnboardingDocument = {
    id: string;
    title: string;
    name: string;
    kindLabel: string;
    status: string | null;
    statusLabel: string | null;
    failureReason: string | null;
    pendingValues: number;
};

type Plan = {
    plan: 'trial' | 'sprint' | 'pro';
    cycle: 'monthly' | 'annual';
    headline: string;
    priceLine: string;
    badge: string | null;
    configured: boolean;
};

type Props = {
    steps: Step[];
    current: string;
    consent: {
        done: boolean;
        ageRange: string | null;
        ageRanges: Record<string, string>;
    };
    revision: number;
    readiness: Record<
        string,
        { label: string; ready: boolean; missing: string[] }
    >;
    missing: { task: string; label: string; keys: string[] }[];
    missingFields: GuidedField[];
    summary: {
        section: string;
        label: string;
        value: string | null;
        unknown: boolean;
    }[];
    documents: OnboardingDocument[];
    kinds: Record<string, string>;
    maxUploadMb: number;
    ocrAvailable: boolean;
    billing: {
        needed: boolean;
        has_access: boolean;
        checkout_verified: boolean;
        cancelled: boolean;
        trial_only: boolean;
        plans: Plan[];
    };
};

type Errors = Record<string, string | undefined>;

const stepHref = (key: string) => `/onboarding?step=${key}`;

function Progress({ steps, index }: { steps: Step[]; index: number }) {
    return (
        <div className="flex flex-1 items-center gap-1" aria-hidden>
            {steps.map((step, i) => (
                <span
                    key={step.key}
                    className={cn(
                        'h-1 flex-1 rounded-full transition-colors duration-300',
                        step.completed || i < index
                            ? 'bg-foreground/75'
                            : i === index
                              ? 'bg-foreground/35'
                              : 'bg-foreground/10',
                    )}
                />
            ))}
        </div>
    );
}

function StepNav({
    steps,
    current,
    locked,
}: {
    steps: Step[];
    current: string;
    locked: boolean;
}) {
    return (
        <ol className="flex flex-col gap-0.5" aria-label="Setup steps">
            {steps.map((step, i) => {
                const active = step.key === current;
                const disabled = locked && step.key !== 'consent';
                const content = (
                    <>
                        <span
                            className={cn(
                                'nums flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-medium',
                                step.completed
                                    ? 'bg-foreground text-background'
                                    : active
                                      ? 'border border-foreground/40 text-foreground'
                                      : 'border border-border text-muted-foreground',
                            )}
                        >
                            {step.completed ? (
                                <Check className="size-3" weight="bold" />
                            ) : (
                                i + 1
                            )}
                        </span>
                        <span
                            className={cn(
                                'min-w-0 flex-1 truncate text-[14px]',
                                active
                                    ? 'font-medium text-foreground'
                                    : 'text-foreground/75',
                            )}
                        >
                            {step.title}
                        </span>
                    </>
                );
                const className = cn(
                    'flex h-9 items-center gap-2.5 rounded-[10px] px-2.5 transition-colors',
                    active
                        ? 'bg-sidebar-accent'
                        : !disabled && 'hover:bg-sidebar-accent/70',
                    disabled && 'opacity-45',
                );

                return (
                    <li key={step.key}>
                        {disabled ? (
                            <span className={className} aria-disabled>
                                {content}
                            </span>
                        ) : (
                            <Link
                                href={stepHref(step.key)}
                                preserveScroll
                                className={className}
                                aria-current={active ? 'step' : undefined}
                            >
                                {content}
                            </Link>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

function ConsentStep({
    consent,
    errors,
}: {
    consent: Props['consent'];
    errors: Errors;
}) {
    const form = useForm({
        age_range: consent.ageRange ?? '',
        legal_accepted: consent.done,
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/onboarding/consent', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <section className="space-y-3">
                <h2 className="mono-label">Your age range</h2>
                <OptionCards
                    name="Your age range"
                    options={consent.ageRanges}
                    value={form.data.age_range}
                    onChange={(v) => form.setData('age_range', v)}
                    columns={4}
                />
                <ErrorPill
                    message={form.errors.age_range ?? errors.age_range}
                />
            </section>
            <section className="space-y-3">
                <h2 className="mono-label">Terms</h2>
                <CheckRow
                    checked={form.data.legal_accepted}
                    onChange={(v) => form.setData('legal_accepted', v)}
                    label={
                        <>
                            I am 13 or older and agree to the{' '}
                            <a
                                href="/legal/privacy"
                                target="_blank"
                                rel="noreferrer"
                                className="underline decoration-foreground/30 underline-offset-2 hover:decoration-foreground"
                            >
                                Privacy Policy
                            </a>{' '}
                            and{' '}
                            <a
                                href="/legal/terms"
                                target="_blank"
                                rel="noreferrer"
                                className="underline decoration-foreground/30 underline-offset-2 hover:decoration-foreground"
                            >
                                Terms of Service
                            </a>
                            .
                        </>
                    }
                    description="Your profile, documents and chats stay private to you. You can export or delete them at any time."
                />
                <ErrorPill
                    message={
                        form.errors.legal_accepted ?? errors.legal_accepted
                    }
                />
            </section>
            <HintPill>
                Next: a few short questions about where you are and what you
                want. Every answer is saved as you go.
            </HintPill>
            <StepFooter>
                <PrimaryButton
                    processing={form.processing}
                    disabled={!form.data.age_range || !form.data.legal_accepted}
                >
                    Agree and start
                </PrimaryButton>
            </StepFooter>
        </form>
    );
}

function statusTone(status: string | null): string {
    if (status === 'failed') {
        return 'bg-destructive/10 text-destructive';
    }

    if (status === 'queued' || status === 'processing') {
        return 'bg-muted text-muted-foreground';
    }

    return 'bg-success/12 text-success';
}

function DocumentsStep({
    props,
    back,
    next,
}: {
    props: Props;
    back: string | null;
    next: string | null;
}) {
    const upload = useForm<{ kind: string; file: File | null }>({
        kind: 'transcript',
        file: null,
    });
    const finish = useForm({ expected_revision: props.revision });
    const fileRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const reading = props.documents.some(
        (d) => d.status === 'queued' || d.status === 'processing',
    );

    // Documents are read in the background; refresh their state until they settle.
    useEffect(() => {
        if (!reading) {
            return;
        }

        const id = window.setInterval(
            () =>
                router.reload({ only: ['documents', 'summary', 'readiness'] }),
            3000,
        );

        return () => window.clearInterval(id);
    }, [reading]);

    const send = (file: File) => {
        upload.transform((data) => ({ ...data, file }));
        upload.post('/onboarding/documents', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => upload.reset('file'),
        });
    };

    const onDrop = (e: DragEvent<HTMLLabelElement>) => {
        e.preventDefault();
        setDragging(false);
        const file = e.dataTransfer.files?.[0];

        if (file) {
            send(file);
        }
    };

    const quickKinds = Object.fromEntries(
        Object.entries(props.kinds).filter(([k]) =>
            ['transcript', 'test_score_report', 'resume', 'other'].includes(k),
        ),
    );

    return (
        <div className="space-y-6">
            <section className="space-y-3">
                <h2 className="mono-label">What are you uploading?</h2>
                <OptionCards
                    name="Document type"
                    options={quickKinds}
                    value={upload.data.kind}
                    onChange={(v) => upload.setData('kind', v || 'other')}
                />
            </section>

            <label
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                className={cn(
                    'flex cursor-pointer flex-col items-center gap-2 rounded-2xl border border-dashed px-6 py-8 text-center transition-colors',
                    dragging
                        ? 'border-foreground/40 bg-muted'
                        : 'border-foreground/15 bg-muted/40 hover:bg-muted/70',
                )}
            >
                {upload.processing ? (
                    <CircleNotch className="size-6 animate-spin text-muted-foreground" />
                ) : (
                    <FileArrowUp className="size-6 text-muted-foreground" />
                )}
                <span className="text-[14px] font-medium text-foreground">
                    {upload.processing
                        ? 'Uploading…'
                        : 'Drop a file here or choose one'}
                </span>
                <span className="font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
                    PDF · DOCX · TXT{props.ocrAvailable ? ' · PNG · JPG' : ''} ·
                    up to {props.maxUploadMb} MB
                </span>
                <input
                    ref={fileRef}
                    type="file"
                    accept=".pdf,.docx,.txt,.png,.jpg,.jpeg"
                    className="sr-only"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        e.target.value = '';

                        if (file) {
                            send(file);
                        }
                    }}
                />
            </label>
            <ErrorPill message={upload.errors.file ?? upload.errors.kind} />
            {!props.ocrAvailable ? (
                <HintPill>
                    Photos and scanned PDFs can&apos;t be read yet. Upload a PDF
                    with selectable text, or type the values in the earlier
                    steps.
                </HintPill>
            ) : null}

            {props.documents.length > 0 ? (
                <ul className="divide-y divide-border/70 rounded-2xl border border-border/80">
                    {props.documents.map((doc) => (
                        <li
                            key={doc.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[14px] font-medium text-foreground">
                                    {doc.name}
                                </span>
                                <span className="block truncate text-[12.5px] text-muted-foreground">
                                    {doc.kindLabel}
                                    {doc.status === 'failed' &&
                                    doc.failureReason
                                        ? ` · ${doc.failureReason}`
                                        : ''}
                                    {doc.pendingValues > 0
                                        ? ` · ${doc.pendingValues} value${doc.pendingValues === 1 ? '' : 's'} to check after setup`
                                        : ''}
                                </span>
                            </span>
                            <span
                                className={cn(
                                    'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11.5px] font-medium',
                                    statusTone(doc.status),
                                )}
                            >
                                {doc.status === 'queued' ||
                                doc.status === 'processing' ? (
                                    <CircleNotch className="size-3 animate-spin" />
                                ) : doc.status === 'failed' ? (
                                    <WarningCircle
                                        className="size-3"
                                        weight="fill"
                                    />
                                ) : (
                                    <Check className="size-3" weight="bold" />
                                )}
                                {doc.statusLabel ?? 'Saved'}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : null}

            <HintPill>
                Values read from a document are suggestions. You check each one
                on the Documents page before it reaches your profile.
            </HintPill>

            <StepFooter backHref={back ? stepHref(back) : null}>
                {next ? (
                    <Link
                        href={stepHref(next)}
                        className="inline-flex h-10 items-center rounded-full px-4 text-[13.5px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        Skip for now
                    </Link>
                ) : null}
                <PrimaryButton
                    processing={finish.processing}
                    type="button"
                    onClick={() =>
                        finish.post('/onboarding/sections/documents', {
                            preserveScroll: true,
                        })
                    }
                >
                    Continue
                </PrimaryButton>
            </StepFooter>
        </div>
    );
}

function ReviewStep({ props, back }: { props: Props; back: string | null }) {
    const complete = useForm({});
    const sectionTitles = Object.fromEntries(
        props.steps.map((s) => [s.key, s.title]),
    );
    const grouped = props.summary.reduce<Record<string, Props['summary']>>(
        (acc, row) => {
            (acc[row.section] ??= []).push(row);

            return acc;
        },
        {},
    );

    return (
        <div className="space-y-7">
            <section className="space-y-3">
                <h2 className="mono-label">Ready for</h2>
                <ul className="grid gap-2 sm:grid-cols-2">
                    {Object.entries(props.readiness).map(([key, task]) => (
                        <li
                            key={key}
                            className={cn(
                                'rounded-xl px-3.5 py-3',
                                task.ready ? 'bg-success/[0.08]' : 'bg-muted',
                            )}
                        >
                            <p className="flex items-center gap-2 text-[14px] font-medium text-foreground">
                                {task.ready ? (
                                    <CheckCircle
                                        className="size-4 text-success"
                                        weight="fill"
                                    />
                                ) : (
                                    <Circle className="size-4 text-muted-foreground" />
                                )}
                                {task.label}
                            </p>
                            <p className="mt-1 text-[12.5px] leading-snug text-muted-foreground">
                                {task.ready
                                    ? 'You have answered everything this needs.'
                                    : `Still open: ${task.missing.join(', ')}.`}
                            </p>
                        </li>
                    ))}
                </ul>
            </section>

            {props.missingFields.length > 0 ? (
                <section className="space-y-3">
                    <h2 className="mono-label">A few questions still open</h2>
                    <HintPill>
                        {props.missing
                            .map((m) => `${m.task}: ${m.label.toLowerCase()}`)
                            .join(' · ')}
                        . Answer what you can — &quot;don&apos;t know&quot;
                        counts.
                    </HintPill>
                    <div className="rounded-2xl border border-border/80 p-4 sm:p-5">
                        <GuidedSectionForm
                            key={props.revision}
                            fields={props.missingFields}
                            revision={props.revision}
                            action="/onboarding/answers"
                            submitLabel="Save answers"
                            extraData={{
                                keys: props.missingFields.map((f) => f.key),
                            }}
                        />
                    </div>
                </section>
            ) : null}

            <section className="space-y-3">
                <h2 className="mono-label">Your answers</h2>
                <div className="divide-y divide-border/70 rounded-2xl border border-border/80">
                    {Object.entries(grouped).map(([section, rows]) => (
                        <div key={section} className="px-4 py-3.5">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="text-[13.5px] font-medium text-foreground">
                                    {sectionTitles[section] ?? section}
                                </p>
                                <Link
                                    href={stepHref(section)}
                                    className="text-[12.5px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                >
                                    Edit
                                </Link>
                            </div>
                            <dl className="grid gap-x-4 gap-y-1.5 text-[13px] sm:grid-cols-2">
                                {rows.map((row) => (
                                    <div
                                        key={row.label}
                                        className="flex min-w-0 justify-between gap-3 sm:block"
                                    >
                                        <dt className="truncate text-muted-foreground">
                                            {row.label}
                                        </dt>
                                        <dd
                                            className={cn(
                                                'truncate',
                                                row.value
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground/70',
                                            )}
                                        >
                                            {row.unknown
                                                ? "Don't know"
                                                : (row.value ?? '—')}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    ))}
                </div>
            </section>

            <StepFooter backHref={back ? stepHref(back) : null}>
                {props.billing.needed ? (
                    <Link
                        href={stepHref('plan')}
                        className="inline-flex h-10 items-center gap-2 rounded-full bg-primary px-5 text-[14px] font-medium text-primary-foreground shadow-raised"
                    >
                        Continue
                        <ArrowRight className="size-4" />
                    </Link>
                ) : (
                    <PrimaryButton
                        processing={complete.processing}
                        type="button"
                        onClick={() => complete.post('/onboarding/complete')}
                    >
                        Finish setup
                    </PrimaryButton>
                )}
            </StepFooter>
        </div>
    );
}

function PlanStep({
    props,
    back,
    errors,
}: {
    props: Props;
    back: string | null;
    errors: Errors;
}) {
    const configured = props.billing.plans.filter((p) => p.configured);
    const [choice, setChoice] = useState(
        configured[0] ? `${configured[0].plan}:${configured[0].cycle}` : '',
    );
    const checkout = useForm({ plan: '', billing_cycle: 'monthly' });
    const skip = useForm({});
    const complete = useForm({});
    const done = props.billing.checkout_verified || props.billing.has_access;

    if (done) {
        return (
            <div className="space-y-6">
                <p className="flex items-center gap-2 rounded-xl bg-success/[0.08] px-4 py-3 text-[14px] text-foreground">
                    <CheckCircle
                        className="size-5 text-success"
                        weight="fill"
                    />
                    Your access is active.
                </p>
                <StepFooter backHref={back ? stepHref(back) : null}>
                    <PrimaryButton
                        processing={complete.processing}
                        type="button"
                        onClick={() => complete.post('/onboarding/complete')}
                    >
                        Finish setup
                    </PrimaryButton>
                </StepFooter>
            </div>
        );
    }

    const options = Object.fromEntries(
        configured.map((p) => [
            `${p.plan}:${p.cycle}`,
            `${p.headline} — ${p.priceLine}${p.badge ? ` · ${p.badge}` : ''}`,
        ]),
    );

    return (
        <div className="space-y-6">
            {props.billing.cancelled ? (
                <HintPill>
                    Checkout was cancelled. Nothing was charged.
                </HintPill>
            ) : null}
            {props.billing.trial_only ? (
                <HintPill>
                    Sprint and Pro are paused for now; the $1 trial includes
                    full access.
                </HintPill>
            ) : null}
            {configured.length > 0 ? (
                <section className="space-y-3">
                    <h2 className="mono-label">Choose a plan</h2>
                    <OptionCards
                        name="Plan"
                        options={options}
                        value={choice}
                        onChange={(v) => setChoice(v)}
                        columns={1}
                    />
                </section>
            ) : (
                <HintPill>
                    No plans are set up for checkout here yet. You can finish
                    setup and choose a plan later in Settings → Billing.
                </HintPill>
            )}
            <ErrorPill
                message={
                    checkout.errors.plan ??
                    errors.plan ??
                    errors.billing ??
                    errors.onboarding
                }
            />
            <StepFooter backHref={back ? stepHref(back) : null}>
                <button
                    type="button"
                    disabled={skip.processing}
                    onClick={() => skip.post('/onboarding/skip-billing')}
                    className="inline-flex h-10 items-center rounded-full px-4 text-[13.5px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-50"
                >
                    Skip for now
                </button>
                {configured.length > 0 ? (
                    <PrimaryButton
                        processing={checkout.processing}
                        type="button"
                        disabled={!choice}
                        onClick={() => {
                            const [plan, cycle] = choice.split(':');
                            checkout.transform(() => ({
                                plan,
                                billing_cycle: cycle,
                            }));
                            checkout.post('/onboarding/checkout');
                        }}
                    >
                        Continue to checkout
                    </PrimaryButton>
                ) : null}
            </StepFooter>
        </div>
    );
}

export default function OnboardingPage(props: Props) {
    const { errors } = usePage<{ errors: Errors }>().props;
    const steps = props.steps;
    const index = Math.max(
        0,
        steps.findIndex((s) => s.key === props.current),
    );
    const step = steps[index] ?? steps[0];
    const back = index > 0 ? steps[index - 1].key : null;
    const next = index < steps.length - 1 ? steps[index + 1].key : null;
    const locked = !props.consent.done;

    return (
        <>
            <Head title="Set up your profile" />
            <div className="flex h-svh min-h-0 bg-canvas">
                <aside className="hidden w-[264px] shrink-0 flex-col px-3 py-3 lg:flex">
                    <div className="mb-5 flex items-center gap-2.5 px-1 py-1">
                        <span className="flex size-7 items-center justify-center rounded-[8px] bg-foreground text-background">
                            <LogoMark className="size-3.5" />
                        </span>
                        <span className="text-[15px] font-semibold tracking-tight text-foreground">
                            AdmitMe
                        </span>
                    </div>
                    <p className="mb-1.5 px-2.5 text-[12.5px] text-muted-foreground">
                        Set up your profile
                    </p>
                    <div className="scrollbar-none min-h-0 flex-1 overflow-y-auto">
                        <StepNav
                            steps={steps}
                            current={step.key}
                            locked={locked}
                        />
                    </div>
                    <div className="mt-3 rounded-xl border border-border bg-background px-3 py-2.5 text-[12px] leading-snug text-muted-foreground shadow-card">
                        Saved after every step. Stop any time — you&apos;ll pick
                        up where you left off.
                    </div>
                </aside>

                <main className="flex min-w-0 flex-1 flex-col lg:py-2 lg:pr-2">
                    <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-background lg:rounded-2xl lg:border lg:border-border lg:shadow-window">
                        <header className="flex h-14 shrink-0 items-center gap-3 border-b border-border px-4 sm:px-6">
                            <span className="flex size-7 shrink-0 items-center justify-center rounded-[8px] bg-foreground text-background lg:hidden">
                                <LogoMark className="size-3.5" />
                            </span>
                            <span className="nums shrink-0 text-[13px] text-muted-foreground">
                                Step {index + 1} of {steps.length}
                            </span>
                            <Progress steps={steps} index={index} />
                            <ModeToggle className="size-8" />
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                                aria-label="Log out"
                            >
                                <SignOut className="size-4" />
                            </Link>
                        </header>

                        <div className="min-h-0 flex-1 scrollbar-thin overflow-y-auto">
                            <div
                                className="mx-auto w-full max-w-[640px] animate-rise px-5 py-8 sm:px-8 sm:py-12"
                                key={step.key}
                            >
                                <StepFigure
                                    step={step.key}
                                    className="mb-6 h-24 w-auto"
                                />
                                <h1 className="text-[26px] leading-tight font-semibold tracking-tight text-foreground sm:text-[28px]">
                                    {step.key === 'consent'
                                        ? 'Welcome to AdmitMe'
                                        : step.title}
                                </h1>
                                <p className="mt-2 max-w-xl text-[14.5px] leading-relaxed text-muted-foreground">
                                    {step.intro}
                                </p>

                                <div className="mt-8">
                                    {step.key === 'consent' ? (
                                        <ConsentStep
                                            consent={props.consent}
                                            errors={errors}
                                        />
                                    ) : step.key === 'documents' ? (
                                        <DocumentsStep
                                            props={props}
                                            back={back}
                                            next={next}
                                        />
                                    ) : step.key === 'review' ? (
                                        <ReviewStep props={props} back={back} />
                                    ) : step.key === 'plan' ? (
                                        <PlanStep
                                            props={props}
                                            back={back}
                                            errors={errors}
                                        />
                                    ) : (
                                        <GuidedSectionForm
                                            key={`${step.key}:${props.revision}`}
                                            fields={step.fields}
                                            revision={props.revision}
                                            action={`/onboarding/sections/${step.key}`}
                                            backHref={
                                                back ? stepHref(back) : null
                                            }
                                            skipHref={
                                                next ? stepHref(next) : null
                                            }
                                        />
                                    )}
                                </div>
                                {errors.onboarding && step.key !== 'plan' ? (
                                    <ErrorPill
                                        className="mt-4"
                                        message={errors.onboarding}
                                    />
                                ) : null}
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}
