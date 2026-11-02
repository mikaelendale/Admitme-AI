import { Head, Link, useForm } from '@inertiajs/react';
import { Check, CheckCircle, Circle } from '@phosphor-icons/react';
import {
    GuidedSectionForm,
    HintPill,
    PrimaryButton,
    StepFooter,
} from '@/components/admitme/profile-fields';
import type { GuidedField } from '@/components/admitme/profile-fields';
import { StepFigure } from '@/components/figures/figures';
import { Page, PageHeader } from '@/components/page/kit';
import { AppLayoutHeaderMeta } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

type Section = {
    key: string;
    title: string;
    intro: string;
    completed: boolean;
    fields: GuidedField[];
};

type PageProps = {
    sections: Section[];
    current: string;
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
    documents: { count: number; pendingValues: number };
};

const href = (key: string) => `/profile-setup?section=${key}`;

export default function ProfileSetupPage(props: PageProps) {
    const { sections, current, revision } = props;
    const index = Math.max(
        0,
        sections.findIndex((s) => s.key === current),
    );
    const section = sections[index] ?? sections[0];
    const back = index > 0 ? href(sections[index - 1].key) : null;
    const next =
        index < sections.length - 1 ? href(sections[index + 1].key) : null;
    const done = sections.filter((s) => s.completed).length;

    return (
        <>
            <Head title="Profile setup" />
            <AppLayoutHeaderMeta>{`${done} of ${sections.length} steps done`}</AppLayoutHeaderMeta>

            <Page width="wide">
                <PageHeader
                    title="Profile setup"
                    description="Answers save after each step. If you don't know something, say so — the mentor treats it as unknown instead of guessing."
                />

                <div className="grid gap-8 md:grid-cols-[13.5rem_minmax(0,1fr)]">
                    <nav aria-label="Setup steps">
                        <ol className="flex flex-col gap-0.5">
                            {sections.map((item, i) => {
                                const active = item.key === section.key;

                                return (
                                    <li key={item.key}>
                                        <Link
                                            href={href(item.key)}
                                            preserveScroll
                                            aria-current={
                                                active ? 'step' : undefined
                                            }
                                            className={cn(
                                                'flex h-9 items-center gap-2.5 rounded-[10px] px-2.5 transition-colors',
                                                active
                                                    ? 'bg-muted'
                                                    : 'hover:bg-muted/60',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'nums flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-medium',
                                                    item.completed
                                                        ? 'bg-foreground text-background'
                                                        : active
                                                          ? 'border border-foreground/40 text-foreground'
                                                          : 'border border-border text-muted-foreground',
                                                )}
                                            >
                                                {item.completed ? (
                                                    <Check
                                                        className="size-3"
                                                        weight="bold"
                                                    />
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
                                                {item.title}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ol>
                    </nav>

                    <section
                        className="min-w-0"
                        aria-labelledby="section-heading"
                    >
                        <StepFigure
                            key={section.key}
                            step={section.key}
                            className="mb-5 h-20 w-auto"
                        />
                        <h2
                            id="section-heading"
                            className="text-[21px] font-semibold tracking-tight text-foreground"
                        >
                            {section.title}
                        </h2>
                        <p className="mt-1 mb-7 text-[14px] text-muted-foreground">
                            {section.intro}
                        </p>

                        {section.key === 'documents' ? (
                            <DocumentsStep
                                revision={revision}
                                documents={props.documents}
                                backHref={back}
                            />
                        ) : section.key === 'review' ? (
                            <ReviewStep props={props} backHref={back} />
                        ) : (
                            <GuidedSectionForm
                                key={`${section.key}:${revision}`}
                                fields={section.fields}
                                revision={revision}
                                action={`/profile-setup/${section.key}`}
                                backHref={back}
                                skipHref={next}
                            />
                        )}
                    </section>
                </div>
            </Page>
        </>
    );
}

function DocumentsStep({
    revision,
    documents,
    backHref,
}: {
    revision: number;
    documents: PageProps['documents'];
    backHref: string | null;
}) {
    const form = useForm({ expected_revision: revision });

    return (
        <div className="space-y-4">
            <p className="text-[14px] text-foreground">
                {documents.count === 0
                    ? 'You have not uploaded any documents yet.'
                    : `You have ${documents.count} document${documents.count === 1 ? '' : 's'}; ${documents.pendingValues} value${documents.pendingValues === 1 ? '' : 's'} wait for your check.`}
            </p>
            <HintPill>
                Values read from a document reach your profile only after you
                accept them on the Documents page.
            </HintPill>
            <StepFooter backHref={backHref}>
                <Link
                    href="/documents"
                    className="inline-flex h-10 items-center rounded-full border border-border/80 bg-card px-4 text-[13.5px] font-medium text-foreground shadow-card hover:bg-muted"
                >
                    Open documents
                </Link>
                <PrimaryButton
                    processing={form.processing}
                    type="button"
                    onClick={() =>
                        form.post('/profile-setup/documents', {
                            preserveScroll: true,
                        })
                    }
                >
                    {documents.count === 0 ? 'Skip for now' : 'Continue'}
                </PrimaryButton>
            </StepFooter>
        </div>
    );
}

function ReviewStep({
    props,
    backHref,
}: {
    props: PageProps;
    backHref: string | null;
}) {
    const finish = useForm({ expected_revision: props.revision });
    const titles = Object.fromEntries(
        props.sections.map((s) => [s.key, s.title]),
    );
    const grouped = props.summary.reduce<Record<string, PageProps['summary']>>(
        (acc, row) => {
            (acc[row.section] ??= []).push(row);

            return acc;
        },
        {},
    );

    return (
        <div className="space-y-7">
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
                                ? 'Everything this needs is answered.'
                                : `Still open: ${task.missing.join(', ')}.`}
                        </p>
                    </li>
                ))}
            </ul>

            {props.missingFields.length > 0 ? (
                <section className="space-y-3">
                    <h3 className="mono-label">A few questions still open</h3>
                    <div className="rounded-2xl border border-border/80 p-4 sm:p-5">
                        <GuidedSectionForm
                            key={props.revision}
                            fields={props.missingFields}
                            revision={props.revision}
                            action="/profile-setup/answers"
                            submitLabel="Save answers"
                            extraData={{
                                keys: props.missingFields.map((f) => f.key),
                            }}
                        />
                    </div>
                </section>
            ) : null}

            <section className="space-y-3">
                <h3 className="mono-label">Your answers</h3>
                <div className="divide-y divide-border/70 rounded-2xl border border-border/80">
                    {Object.entries(grouped).map(([section, rows]) => (
                        <div key={section} className="px-4 py-3.5">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="text-[13.5px] font-medium text-foreground">
                                    {titles[section] ?? section}
                                </p>
                                <Link
                                    href={href(section)}
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

            <StepFooter backHref={backHref}>
                <Link
                    href="/profile-overview"
                    className="inline-flex h-10 items-center rounded-full border border-border/80 bg-card px-4 text-[13.5px] font-medium text-foreground shadow-card hover:bg-muted"
                >
                    See full profile
                </Link>
                <PrimaryButton
                    processing={finish.processing}
                    type="button"
                    onClick={() =>
                        finish.post('/profile-setup/review', {
                            preserveScroll: true,
                        })
                    }
                >
                    Mark reviewed
                </PrimaryButton>
            </StepFooter>
        </div>
    );
}
