import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowSquareOut,
    ArrowsClockwise,
    Browsers,
    CaretDown,
    CheckCircle,
    Circle,
    CircleHalf,
    ClipboardText,
    Hourglass,
    Info,
    Lightning,
    ListChecks,
    MinusCircle,
    PenNib,
    Plus,
    WarningCircle,
} from '@phosphor-icons/react';
import type { Icon, IconWeight } from '@phosphor-icons/react';
import { useEffect, useId, useState } from 'react';
import type { ReactNode } from 'react';
import {
    APPLICATION_STATUS,
    fieldClass,
    formatDay,
    formatMoment,
    labelClass,
} from '@/components/admitme/application-parts';
import { BrowserSessionCard } from '@/components/admitme/browser-session';
import type { BrowserSessionData } from '@/components/admitme/browser-session';
import { ErrorPill } from '@/components/admitme/profile-fields';
import { TaskGoalCard } from '@/components/admitme/task-goal-card';
import InputError from '@/components/input-error';
import {
    KeyValueList,
    ListCard,
    Page,
    PageHeader,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import type { BadgeTone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';

type Option = { value: string; label: string };

type ChecklistItem = {
    id: string;
    revision: number;
    kind: string;
    title: string;
    details: string | null;
    origin: 'requirement' | 'default' | 'manual';
    owner: 'student' | 'third_party';
    ownerLabel: string | null;
    status: string;
    statusLabel: string;
    statusNote: string | null;
    allowedStatuses: Option[];
    completionBases: Option[];
    complete: boolean;
    completionBasis: string | null;
    completedAt: string | null;
    dueDate: string | null;
    dueTime: string | null;
    timezone: string | null;
    overdue: boolean;
    document: { id: string; title: string } | null;
    sourceMissing: boolean;
    source: {
        url: string;
        title: string | null;
        label: string;
        checkedAt: string | null;
        quote: string | null;
    } | null;
};

type Workspace = {
    application: {
        id: string;
        revision: number;
        status: string;
        statusLabel: string;
        cycle: string | null;
        applicationSystem: string | null;
        submittedAt: string | null;
        submissionEvidence: string | null;
    };
    college: { id: string; name: string; choiceStatus: string };
    portal: { url: string | null; savedByYou: boolean; note: string };
    deadline: {
        label: string;
        date: string;
        time: string | null;
        timezone: string | null;
        fromSource: boolean;
        daysLeft: number;
        basis: string;
    } | null;
    progress: {
        done: number;
        total: number;
        yoursDone: number;
        yoursTotal: number;
        othersReceived: number;
        othersRequested: number;
        othersTotal: number;
    };
    checklist: ChecklistItem[];
    blockers: { kind: string; text: string; itemId: string | null }[];
    nextActions: { kind: string; text: string; itemId: string | null }[];
    requirements: {
        onFile: number;
        unresolved: string[];
        checklistBuiltAt: string | null;
        changedSinceBuilt: boolean;
    };
    essays: { id: string; prompt: string; status: string }[];
    documents: { id: string; title: string; kind: string }[];
    formPlan: {
        note: string | null;
        formLabel: string | null;
        goalId: string | null;
        plan: {
            id: string;
            createdAt: string | null;
            outdated: boolean;
            entries: {
                section: string;
                field: string;
                required: boolean;
                value: string | null;
                basis: string;
                note: string | null;
            }[];
        } | null;
    };
};

type BrowserAssistance = {
    linked: boolean;
    session: BrowserSessionData | null;
};

type PageProps = {
    workspace: Workspace;
    statusOptions: Option[];
    nextStatuses: string[];
    evidenceOptions: Option[];
    systemOptions: string[];
    kindOptions: string[];
    browser: BrowserAssistance;
};

/** Where a form-plan value comes from. */
const BASIS_LABELS: Record<string, { label: string; dot: string }> = {
    profile: { label: 'From your profile', dot: 'bg-success' },
    writing: { label: 'From your writing', dot: 'bg-success' },
    checklist: { label: 'From your checklist', dot: 'bg-success' },
    validation: { label: 'Check', dot: 'bg-warning' },
    model_judgment: { label: 'AI judgment', dot: 'bg-info' },
    suggestion: { label: 'AI suggestion — review', dot: 'bg-info' },
};

/** Checklist status → leading icon and badge tone. */
const ITEM_STATUS: Record<
    string,
    { icon: Icon; weight: IconWeight; color: string; tone: BadgeTone }
> = {
    todo: {
        icon: Circle,
        weight: 'regular',
        color: 'text-muted-foreground/60',
        tone: 'neutral',
    },
    not_requested: {
        icon: Circle,
        weight: 'regular',
        color: 'text-muted-foreground/60',
        tone: 'neutral',
    },
    in_progress: {
        icon: CircleHalf,
        weight: 'fill',
        color: 'text-info',
        tone: 'info',
    },
    requested: {
        icon: Hourglass,
        weight: 'duotone',
        color: 'text-warning',
        tone: 'warning',
    },
    done: {
        icon: CheckCircle,
        weight: 'fill',
        color: 'text-success',
        tone: 'success',
    },
    received: {
        icon: CheckCircle,
        weight: 'fill',
        color: 'text-success',
        tone: 'success',
    },
    blocked: {
        icon: WarningCircle,
        weight: 'fill',
        color: 'text-destructive',
        tone: 'danger',
    },
    not_applicable: {
        icon: MinusCircle,
        weight: 'regular',
        color: 'text-muted-foreground/60',
        tone: 'neutral',
    },
};

function daysText(days: number): string {
    if (days === 0) {
        return 'today';
    }

    const n = Math.abs(days);

    return days < 0
        ? `${n} day${n === 1 ? '' : 's'} ago`
        : `${n} day${n === 1 ? '' : 's'} left`;
}

function formatDue(item: {
    dueDate: string | null;
    dueTime: string | null;
    timezone: string | null;
}): string | null {
    if (!item.dueDate) {
        return null;
    }

    return [
        formatDay(item.dueDate),
        item.dueTime,
        item.timezone ? `(${item.timezone})` : null,
    ]
        .filter(Boolean)
        .join(' ');
}

/**
 * Short facts separated by quiet dots. Each dot sits in the gap before its fact; the list is shifted left and
 * clipped, so a fact that starts a line (first, or wrapped) never shows a stray leading dot.
 */
function MetaList({
    lead,
    leadClassName,
    items,
    className,
}: {
    lead?: ReactNode;
    leadClassName?: string;
    items: ReactNode[];
    className?: string;
}) {
    const shown = items.filter(
        (item) =>
            item !== null &&
            item !== undefined &&
            item !== false &&
            item !== '',
    );

    if (!lead && shown.length === 0) {
        return null;
    }

    return (
        <div className={cn('overflow-hidden py-0.5', className)}>
            <ul className="-ml-4 flex flex-wrap items-center gap-y-1.5 text-[13px] text-muted-foreground">
                {lead ? (
                    <li className={cn('flex items-center pl-4', leadClassName)}>
                        {lead}
                    </li>
                ) : null}
                {shown.map((item, index) => (
                    <li
                        key={index}
                        className="relative flex min-w-0 items-center pl-4"
                    >
                        <span
                            aria-hidden
                            className="absolute inset-y-0 left-[5px] flex items-center text-muted-foreground/50"
                        >
                            ·
                        </span>
                        {item}
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function ApplicationWorkspacePage({
    workspace,
    statusOptions,
    nextStatuses,
    evidenceOptions,
    browser,
}: PageProps) {
    const {
        application,
        college,
        portal,
        deadline,
        progress,
        checklist,
        blockers,
        nextActions,
        requirements,
    } = workspace;
    const hydrated = useHydrated();
    const writing = checklist.filter((item) => item.kind === 'essay');
    const mine = checklist.filter(
        (item) => item.owner === 'student' && item.kind !== 'essay',
    );
    const theirs = checklist.filter(
        (item) => item.owner === 'third_party' && item.kind !== 'essay',
    );
    const [rebuilding, setRebuilding] = useState(false);
    const status =
        APPLICATION_STATUS[application.status] ?? APPLICATION_STATUS.preparing;
    const builtAt = hydrated
        ? formatMoment(requirements.checklistBuiltAt)
        : null;

    const rebuild = () => {
        setRebuilding(true);
        router.post(
            `/applications/${application.id}/checklist`,
            {},
            { preserveScroll: true, onFinish: () => setRebuilding(false) },
        );
    };

    const rowProps = {
        applicationId: application.id,
        documents: workspace.documents,
    };

    return (
        <>
            <Head title={`${college.name} application`} />

            <Page>
                <PageHeader
                    eyebrow={
                        <Link
                            href="/applications"
                            className="inline-flex items-center gap-1 transition-colors hover:text-foreground"
                        >
                            <ArrowLeft className="size-3.5" aria-hidden />
                            Applications
                        </Link>
                    }
                    title={college.name}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-x-2.5 gap-y-1">
                            <StatusBadge tone={status.tone}>
                                {application.statusLabel}
                            </StatusBadge>
                            <span>
                                {application.cycle
                                    ? `${application.cycle} · `
                                    : ''}
                                {progress.done} of {progress.total} steps done
                            </span>
                        </span>
                    }
                    actions={
                        <Button
                            variant="outline"
                            asChild
                            className="h-9 rounded-full px-4"
                        >
                            <Link href={`/college-lists/${college.id}`}>
                                College details
                            </Link>
                        </Button>
                    }
                />

                <Section>
                    <div className="rounded-2xl border border-border bg-card px-5">
                        <KeyValueList>
                            <StatusRow
                                workspace={workspace}
                                statusOptions={statusOptions}
                                nextStatuses={nextStatuses}
                                evidenceOptions={evidenceOptions}
                            />

                            <DetailRow
                                label="Deadline"
                                hint={
                                    deadline ? (
                                        <span className="inline-flex items-start gap-1.5">
                                            {deadline.fromSource ? null : (
                                                <WarningCircle
                                                    weight="fill"
                                                    className="mt-0.5 size-3.5 shrink-0 text-warning"
                                                    aria-hidden
                                                />
                                            )}
                                            <span>{deadline.basis}</span>
                                        </span>
                                    ) : undefined
                                }
                            >
                                {deadline ? (
                                    <>
                                        <span className="font-medium">
                                            {deadline.label.split(':')[0]}
                                        </span>
                                        <span className="nums text-muted-foreground">
                                            {formatDue({
                                                dueDate: deadline.date,
                                                dueTime: deadline.time,
                                                timezone: deadline.timezone,
                                            })}
                                        </span>
                                        <StatusBadge
                                            tone={
                                                deadline.daysLeft < 0
                                                    ? 'danger'
                                                    : deadline.daysLeft <= 7
                                                      ? 'warning'
                                                      : 'neutral'
                                            }
                                        >
                                            {daysText(deadline.daysLeft)}
                                        </StatusBadge>
                                    </>
                                ) : (
                                    <span className="text-muted-foreground">
                                        No deadline on file yet.
                                    </span>
                                )}
                            </DetailRow>

                            <DetailRow label="Your steps">
                                <span className="nums font-medium">
                                    {progress.yoursDone} of{' '}
                                    {progress.yoursTotal} done
                                </span>
                            </DetailRow>

                            <DetailRow label="From others">
                                <span className="nums font-medium">
                                    {progress.othersReceived} of{' '}
                                    {progress.othersTotal} received
                                </span>
                                <span className="nums text-muted-foreground">
                                    · {progress.othersRequested} requested
                                </span>
                            </DetailRow>

                            <DetailRow
                                label="Portal"
                                hint={
                                    <>
                                        {portal.url && !portal.savedByYou
                                            ? 'From research — confirm it on the college’s site. '
                                            : ''}
                                        {portal.note}
                                    </>
                                }
                                after={
                                    !portal.savedByYou ? (
                                        <PortalLinkForm
                                            applicationId={application.id}
                                            revision={application.revision}
                                        />
                                    ) : null
                                }
                            >
                                {portal.url ? (
                                    <a
                                        href={portal.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 font-medium text-foreground underline-offset-2 hover:underline"
                                    >
                                        Open the portal
                                        <ArrowSquareOut
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                    </a>
                                ) : (
                                    <span className="text-muted-foreground">
                                        No portal link saved yet.
                                    </span>
                                )}
                            </DetailRow>
                        </KeyValueList>
                    </div>
                </Section>

                <Section title="Up next" icon={Lightning}>
                    {nextActions.length === 0 ? (
                        <p className="rounded-2xl border border-dashed border-border px-5 py-4 text-[13.5px] text-muted-foreground">
                            Nothing open — every step is done or marked not
                            needed.
                        </p>
                    ) : (
                        <ListCard>
                            {nextActions.map((action, index) => {
                                const body = (
                                    <>
                                        <span className="nums flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-[12px] font-medium text-muted-foreground">
                                            {index + 1}
                                        </span>
                                        <span className="min-w-0 flex-1 text-[14px] leading-snug text-foreground">
                                            {action.text}
                                        </span>
                                    </>
                                );

                                return (
                                    <li key={index}>
                                        {action.itemId ? (
                                            <a
                                                href={`#item-${action.itemId}`}
                                                className="flex items-center gap-3.5 px-5 py-3.5 transition-colors hover:bg-muted/60"
                                            >
                                                {body}
                                                <ArrowDown
                                                    className="size-4 shrink-0 text-muted-foreground/60"
                                                    aria-hidden
                                                />
                                            </a>
                                        ) : (
                                            <div className="flex items-center gap-3.5 px-5 py-3.5">
                                                {body}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ListCard>
                    )}

                    {blockers.length > 0 ? (
                        <div className="mt-3 rounded-2xl bg-warning/10 px-5 py-4">
                            <h3 className="flex items-center gap-2 text-[13.5px] font-medium text-foreground">
                                <WarningCircle
                                    weight="fill"
                                    className="size-4 shrink-0 text-warning"
                                    aria-hidden
                                />
                                Blockers and open questions
                            </h3>
                            <ul className="mt-2 list-disc space-y-1 pl-6 text-[13.5px] leading-snug text-foreground marker:text-muted-foreground">
                                {blockers.map((blocker, index) => (
                                    <li key={index}>{blocker.text}</li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="mt-3 flex items-center gap-2 px-1 text-[13px] text-muted-foreground">
                            <CheckCircle
                                weight="fill"
                                className="size-4 shrink-0 text-success"
                                aria-hidden
                            />
                            No blockers or open questions right now.
                        </p>
                    )}
                </Section>

                <Section
                    title="Checklist"
                    icon={ListChecks}
                    actions={
                        <Button
                            size="sm"
                            variant="ghost"
                            className="-mr-2 -mb-1.5 h-8 gap-1.5 rounded-full px-3 text-muted-foreground"
                            disabled={rebuilding}
                            onClick={rebuild}
                        >
                            <ArrowsClockwise
                                className={cn(
                                    'size-4',
                                    rebuilding && 'animate-spin',
                                )}
                                aria-hidden
                            />
                            {rebuilding
                                ? 'Updating…'
                                : 'Update from requirements'}
                        </Button>
                    }
                >
                    <p className="mb-5 max-w-2xl text-[13px] leading-relaxed text-muted-foreground">
                        {requirements.onFile > 0
                            ? `Built from ${requirements.onFile} requirement${requirements.onFile === 1 ? '' : 's'} on file${builtAt ? `, last updated ${builtAt}` : ''}, plus standard steps.`
                            : 'No requirements on file for this college yet — only standard steps are listed. Add its official admissions page on the college details page.'}
                    </p>

                    {requirements.changedSinceBuilt ? (
                        <p
                            role="status"
                            className="mb-5 flex items-start gap-2.5 rounded-2xl bg-info/10 px-4 py-3 text-[13.5px] leading-snug text-foreground"
                        >
                            <Info
                                weight="fill"
                                className="mt-px size-4 shrink-0 text-info"
                                aria-hidden
                            />
                            New requirements were added since this checklist was
                            built. Update it to include them — your progress is
                            kept.
                        </p>
                    ) : null}

                    <div className="space-y-8">
                        <ChecklistGroup
                            title="Your steps"
                            items={mine}
                            {...rowProps}
                        />
                        {writing.length > 0 ? (
                            <ChecklistGroup
                                title="Writing"
                                note={
                                    <>
                                        “Write it” opens your writing workspace
                                        with the prompt. The coach comments; it
                                        never writes for you.
                                        {workspace.essays.length > 0
                                            ? ` Older essay-suite drafts: ${workspace.essays.map((e) => e.status.replace('_', ' ')).join(', ')}.`
                                            : ''}
                                    </>
                                }
                                items={writing}
                                {...rowProps}
                            />
                        ) : null}
                        <ChecklistGroup
                            title="From others"
                            note="Someone else does these. Asking for one is not the same as it arriving — mark it received only when you see it."
                            items={theirs}
                            doneWord="received"
                            {...rowProps}
                        />
                    </div>

                    <AddStep applicationId={application.id} />
                </Section>

                <FormPlanSection
                    applicationId={application.id}
                    formPlan={workspace.formPlan}
                />

                <BrowserAssistanceSection
                    applicationId={application.id}
                    browser={browser}
                />
            </Page>
        </>
    );
}

/** A label ← → value row in the summary; the value side can hold a hint and a small form. */
function DetailRow({
    label,
    hint,
    after,
    children,
}: {
    label: ReactNode;
    hint?: ReactNode;
    after?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-x-6 gap-y-1.5 py-4 sm:grid-cols-[112px_minmax(0,1fr)]">
            <dt className="text-[13.5px] leading-7 text-muted-foreground">
                {label}
            </dt>
            <dd className="min-w-0">
                <div className="flex min-h-7 flex-wrap items-center gap-x-2 gap-y-1 text-[14px] text-foreground">
                    {children}
                </div>
                {hint ? (
                    <div className="mt-1 max-w-xl text-[12.5px] leading-relaxed text-muted-foreground">
                        {hint}
                    </div>
                ) : null}
                {after}
            </dd>
        </div>
    );
}

function StatusRow({
    workspace,
    statusOptions,
    nextStatuses,
    evidenceOptions,
}: {
    workspace: Workspace;
    statusOptions: Option[];
    nextStatuses: string[];
    evidenceOptions: Option[];
}) {
    const { application } = workspace;
    const hydrated = useHydrated();
    const form = useForm({
        status: application.status,
        submission_evidence_type: '',
        submission_evidence_note: '',
        expected_revision: application.revision,
        from_workspace: true,
    });
    const allowed = statusOptions.filter((option) =>
        nextStatuses.includes(option.value),
    );
    const needsEvidence =
        form.data.status === 'submitted' && !application.submittedAt;
    const error =
        form.errors.status ??
        form.errors.submission_evidence_type ??
        form.errors.expected_revision;
    const submitted = application.submittedAt
        ? ` Submitted${hydrated ? ` ${formatMoment(application.submittedAt, 'date')}` : ''} (${application.submissionEvidence}).`
        : '';
    const saveButton = (
        <Button
            size="sm"
            type="submit"
            className="h-8 rounded-full px-3.5"
            disabled={form.processing || !form.isDirty}
        >
            Save status
        </Button>
    );

    return (
        <DetailRow
            label={<label htmlFor="app-status">Status</label>}
            hint={`AdmitMe never marks an application submitted on its own. When you submit in the portal, record how you know it went in.${submitted}`}
            after={
                error ? (
                    <InputError
                        message={error}
                        className="mt-2 text-[12.5px]"
                    />
                ) : null
            }
        >
            <form
                className="flex w-full flex-col gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(`/applications/${application.id}`, {
                        preserveScroll: true,
                    });
                }}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        id="app-status"
                        className={cn(
                            fieldClass,
                            'h-8 w-auto max-w-full pr-8 font-medium',
                        )}
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        {allowed.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    {form.isDirty && !needsEvidence ? saveButton : null}
                </div>
                {needsEvidence ? (
                    <div className="grid gap-3 rounded-xl bg-muted/50 p-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)_auto] sm:items-end">
                        <div className="min-w-0">
                            <label
                                htmlFor="app-evidence"
                                className={labelClass}
                            >
                                How do you know?
                            </label>
                            <select
                                id="app-evidence"
                                className={cn(fieldClass, 'h-8 text-[13.5px]')}
                                value={form.data.submission_evidence_type}
                                onChange={(e) =>
                                    form.setData(
                                        'submission_evidence_type',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">Choose…</option>
                                {evidenceOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="min-w-0">
                            <label
                                htmlFor="app-evidence-note"
                                className={labelClass}
                            >
                                Confirmation number or note
                            </label>
                            <input
                                id="app-evidence-note"
                                value={form.data.submission_evidence_note}
                                onChange={(e) =>
                                    form.setData(
                                        'submission_evidence_note',
                                        e.target.value,
                                    )
                                }
                                aria-label="Confirmation number or note"
                                className={cn(fieldClass, 'h-8 text-[13.5px]')}
                            />
                        </div>
                        {saveButton}
                    </div>
                ) : null}
            </form>
        </DetailRow>
    );
}

function PortalLinkForm({
    applicationId,
    revision,
}: {
    applicationId: string;
    revision: number;
}) {
    const form = useForm({
        portal_url: '',
        expected_revision: revision,
        from_workspace: true,
    });

    return (
        <>
            <form
                className="mt-3 flex max-w-md items-center gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.patch(`/applications/${applicationId}`, {
                        preserveScroll: true,
                    });
                }}
            >
                <input
                    type="url"
                    value={form.data.portal_url}
                    onChange={(e) => form.setData('portal_url', e.target.value)}
                    placeholder="https://… (the portal you confirmed)"
                    aria-label="Portal link you confirmed"
                    className={cn(fieldClass, 'h-8 text-[13.5px]')}
                />
                <Button
                    size="sm"
                    type="submit"
                    variant="outline"
                    className="h-8 rounded-full px-3.5"
                    disabled={
                        form.processing || form.data.portal_url.trim() === ''
                    }
                >
                    Save
                </Button>
            </form>
            <InputError
                message={
                    form.errors.portal_url ?? form.errors.expected_revision
                }
                className="mt-1.5 text-[12.5px]"
            />
        </>
    );
}

function ChecklistGroup({
    title,
    note,
    items,
    doneWord = 'done',
    applicationId,
    documents,
}: {
    title: string;
    note?: ReactNode;
    items: ChecklistItem[];
    doneWord?: string;
    applicationId: string;
    documents: Workspace['documents'];
}) {
    const done = items.filter((item) => item.complete).length;

    return (
        <div>
            <div className="mb-2.5 flex items-baseline justify-between gap-3">
                <h3 className="text-[14px] font-medium text-foreground">
                    {title}
                </h3>
                {items.length > 0 ? (
                    <span className="nums shrink-0 text-[12.5px] text-muted-foreground">
                        {done} of {items.length} {doneWord}
                    </span>
                ) : null}
            </div>
            {note ? (
                <p className="-mt-1 mb-3 max-w-2xl text-[12.5px] leading-relaxed text-muted-foreground">
                    {note}
                </p>
            ) : null}
            {items.length === 0 ? (
                <p className="rounded-2xl border border-dashed border-border px-5 py-4 text-[13px] text-muted-foreground">
                    Nothing here yet.
                </p>
            ) : (
                <ListCard>
                    {items.map((item) => (
                        <ChecklistRow
                            key={item.id}
                            applicationId={applicationId}
                            item={item}
                            documents={documents}
                        />
                    ))}
                </ListCard>
            )}
        </div>
    );
}

/** "admissions.example.edu (official site)" → "Official site": the kind of source, short enough for a meta line. */
function sourceKind(label: string): string {
    const kind = /\(([^)]+)\)\s*$/.exec(label)?.[1];

    return kind ? kind.charAt(0).toUpperCase() + kind.slice(1) : 'Source';
}

function ChecklistRow({
    applicationId,
    item,
    documents,
}: {
    applicationId: string;
    item: ChecklistItem;
    documents: Workspace['documents'];
}) {
    const [noteFor, setNoteFor] = useState<'blocked' | 'not_applicable' | null>(
        null,
    );
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [showMore, setShowMore] = useState(false);
    const moreId = useId();
    const due = formatDue(item);
    const look = ITEM_STATUS[item.status] ?? ITEM_STATUS.todo;
    const StatusGlyph = look.icon;
    const isThirdParty = item.owner === 'third_party';
    const canUndo =
        item.complete ||
        item.status === 'not_applicable' ||
        item.status === 'blocked';
    // Details, the full source and the rarer "Blocked…" / "Not needed…" sit behind "More".
    const hasMore =
        Boolean(item.details) ||
        item.source !== null ||
        item.origin === 'default' ||
        !canUndo;
    const pill = 'h-7 rounded-full px-3 text-[13px]';
    const quiet = 'h-7 rounded-full px-2.5 text-[13px] text-muted-foreground';

    const save = (data: Record<string, string | null>) => {
        setSaving(true);
        setErrors({});
        router.patch(
            `/applications/${applicationId}/items/${item.id}`,
            { ...data, expected_revision: item.revision },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e as Record<string, string>),
                onSuccess: () => {
                    setNoteFor(null);
                    setNote('');
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const writeIt =
        item.kind === 'essay' ? (
            <Button
                size="sm"
                variant="outline"
                className={cn(pill, 'gap-1.5')}
                onClick={() =>
                    router.post(
                        `/applications/${applicationId}/items/${item.id}/writing`,
                    )
                }
                aria-label={`Write it: ${item.title}`}
            >
                <PenNib className="size-3.5" aria-hidden />
                Write it
            </Button>
        ) : null;
    const undo = canUndo ? (
        <Button
            size="sm"
            variant="ghost"
            className={quiet}
            disabled={saving}
            onClick={() =>
                save({ status: isThirdParty ? 'not_requested' : 'todo' })
            }
        >
            Undo
        </Button>
    ) : null;
    const moreToggle = hasMore ? (
        <button
            type="button"
            aria-expanded={showMore}
            aria-controls={moreId}
            aria-label={`More about ${item.title}`}
            onClick={() => setShowMore(!showMore)}
            className="inline-flex h-7 items-center gap-1 rounded-full px-2 text-[13px] text-muted-foreground transition-colors hover:text-foreground"
        >
            More
            <CaretDown
                aria-hidden
                className={cn(
                    'size-3.5 transition-transform duration-200',
                    showMore && 'rotate-180',
                )}
            />
        </button>
    ) : null;

    return (
        <li id={`item-${item.id}`} className="scroll-mt-6 px-4 py-4 sm:px-5">
            <div className="flex gap-3">
                <StatusGlyph
                    aria-hidden
                    weight={look.weight}
                    className={cn('mt-px size-5 shrink-0', look.color)}
                />
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-3">
                        <p
                            className={cn(
                                'min-w-0 text-[14.5px] leading-snug font-medium text-foreground',
                                item.status === 'not_applicable' &&
                                    'text-muted-foreground line-through decoration-muted-foreground/50',
                            )}
                        >
                            {item.title}
                        </p>
                        <StatusBadge
                            tone={look.tone}
                            className="-my-px hidden sm:inline-flex"
                        >
                            {item.statusLabel}
                        </StatusBadge>
                    </div>

                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <MetaList
                            className="min-w-0 flex-[1_1_14rem]"
                            lead={
                                <StatusBadge tone={look.tone}>
                                    {item.statusLabel}
                                </StatusBadge>
                            }
                            leadClassName="sm:hidden"
                            items={[
                                isThirdParty
                                    ? `Done by: ${item.ownerLabel ?? 'someone else'}`
                                    : null,
                                due ? (
                                    <span className="nums">Due {due}</span>
                                ) : null,
                                item.overdue ? (
                                    <span className="font-medium text-destructive">
                                        Overdue
                                    </span>
                                ) : null,
                                item.source ? (
                                    <a
                                        href={item.source.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        title={`From ${item.source.title ?? item.source.url} · ${item.source.label}`}
                                        className="inline-flex items-center gap-1 underline-offset-2 hover:text-foreground hover:underline"
                                    >
                                        {sourceKind(item.source.label)}
                                        <ArrowSquareOut
                                            className="size-3 shrink-0"
                                            aria-hidden
                                        />
                                    </a>
                                ) : item.origin === 'default' ? (
                                    'Standard step'
                                ) : null,
                                item.complete ? item.completionBasis : null,
                            ]}
                        />
                        {item.complete ? (
                            <span className="ml-auto flex shrink-0 items-center gap-1">
                                {writeIt}
                                {undo}
                                {moreToggle}
                            </span>
                        ) : null}
                    </div>

                    {item.statusNote ? (
                        <p className="mt-2 text-[13px] text-foreground">
                            Note: {item.statusNote}
                        </p>
                    ) : null}
                    {item.sourceMissing ? (
                        <p className="mt-2 flex items-start gap-1.5 text-[12.5px] text-foreground">
                            <WarningCircle
                                weight="fill"
                                className="mt-px size-3.5 shrink-0 text-warning"
                                aria-hidden
                            />
                            The source no longer states this — check the
                            college’s site.
                        </p>
                    ) : null}

                    {item.kind === 'document' && !isThirdParty ? (
                        <div className="mt-3 flex items-center gap-2">
                            <label
                                htmlFor={`doc-${item.id}`}
                                className="shrink-0 text-[13px] text-muted-foreground"
                            >
                                Document
                            </label>
                            <select
                                id={`doc-${item.id}`}
                                className={cn(
                                    fieldClass,
                                    'h-8 w-auto max-w-[16rem] text-[13px]',
                                )}
                                value={item.document?.id ?? ''}
                                disabled={saving}
                                onChange={(e) =>
                                    save({
                                        document_id: e.target.value || null,
                                    })
                                }
                            >
                                <option value="">None attached</option>
                                {documents.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.title}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ) : null}

                    {!item.complete ? (
                        <div className="mt-3 flex flex-wrap items-center gap-1.5">
                            {writeIt}
                            {!isThirdParty ? (
                                <>
                                    <Button
                                        size="sm"
                                        className={pill}
                                        disabled={saving}
                                        onClick={() => save({ status: 'done' })}
                                        aria-label={`I did this: ${item.title}`}
                                    >
                                        I did this
                                    </Button>
                                    {item.status !== 'in_progress' ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className={pill}
                                            disabled={saving}
                                            onClick={() =>
                                                save({ status: 'in_progress' })
                                            }
                                            aria-label={`Start "${item.title}"`}
                                        >
                                            Start
                                        </Button>
                                    ) : null}
                                </>
                            ) : (
                                <>
                                    {item.status !== 'requested' ? (
                                        <Button
                                            size="sm"
                                            className={pill}
                                            disabled={saving}
                                            onClick={() =>
                                                save({ status: 'requested' })
                                            }
                                            aria-label={`I’ve asked them: ${item.title}`}
                                        >
                                            I’ve asked them
                                        </Button>
                                    ) : null}
                                    {item.completionBases.map(
                                        (basis, index) => {
                                            const text =
                                                basis.value ===
                                                'portal_shows_received'
                                                    ? 'Portal shows it received'
                                                    : 'Sender confirmed';

                                            return (
                                                <Button
                                                    key={basis.value}
                                                    size="sm"
                                                    variant={
                                                        item.status ===
                                                            'requested' &&
                                                        index === 0
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    className={pill}
                                                    disabled={saving}
                                                    onClick={() =>
                                                        save({
                                                            status: 'received',
                                                            completion_basis:
                                                                basis.value,
                                                        })
                                                    }
                                                    aria-label={`${text}: ${item.title}`}
                                                    title={basis.label}
                                                >
                                                    {text}
                                                </Button>
                                            );
                                        },
                                    )}
                                </>
                            )}
                            {undo}
                            <span className="ml-auto">{moreToggle}</span>
                        </div>
                    ) : null}

                    <div
                        id={moreId}
                        hidden={!hasMore || !showMore}
                        className="mt-3 space-y-2.5 rounded-xl bg-muted/50 px-4 py-3 text-[13px] leading-relaxed"
                    >
                        {item.details ? (
                            <p className="whitespace-pre-line text-foreground/85">
                                {item.details}
                            </p>
                        ) : null}
                        {item.source ? (
                            <a
                                href={item.source.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-start gap-1 text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                            >
                                <span>
                                    From {item.source.title ?? item.source.url}{' '}
                                    · {item.source.label}
                                </span>
                                <ArrowSquareOut
                                    className="mt-1 size-3 shrink-0"
                                    aria-hidden
                                />
                            </a>
                        ) : item.origin === 'default' ? (
                            <p className="text-muted-foreground">
                                Standard step for most applications — not from
                                this college’s sources.
                            </p>
                        ) : null}
                        {!canUndo ? (
                            <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className={pill}
                                    disabled={saving}
                                    onClick={() => setNoteFor('blocked')}
                                >
                                    Blocked…
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className={pill}
                                    disabled={saving}
                                    onClick={() => setNoteFor('not_applicable')}
                                >
                                    Not needed…
                                </Button>
                            </div>
                        ) : null}
                    </div>

                    {noteFor ? (
                        <form
                            className="mt-3 flex flex-wrap items-center gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                save({ status: noteFor, status_note: note });
                            }}
                        >
                            <input
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder={
                                    noteFor === 'blocked'
                                        ? 'What is blocking it?'
                                        : 'Why is it not needed?'
                                }
                                aria-label={
                                    noteFor === 'blocked'
                                        ? 'What is blocking it?'
                                        : 'Why is it not needed?'
                                }
                                className={cn(
                                    fieldClass,
                                    'h-8 max-w-sm min-w-48 flex-1 text-[13.5px]',
                                )}
                            />
                            <Button
                                size="sm"
                                type="submit"
                                className={pill}
                                disabled={saving || note.trim() === ''}
                            >
                                Save
                            </Button>
                            <Button
                                size="sm"
                                type="button"
                                variant="ghost"
                                className={quiet}
                                onClick={() => setNoteFor(null)}
                            >
                                Cancel
                            </Button>
                        </form>
                    ) : null}

                    {Object.values(errors).length > 0 ? (
                        <p role="alert" className="error-pill mt-3">
                            <WarningCircle
                                weight="fill"
                                className="size-3.5 shrink-0"
                                aria-hidden
                            />
                            <span>
                                {Object.values(errors)[0]}{' '}
                                {errors.expected_revision ? (
                                    <button
                                        type="button"
                                        className="underline underline-offset-2"
                                        onClick={() => router.reload()}
                                    >
                                        Reload
                                    </button>
                                ) : null}
                            </span>
                        </p>
                    ) : null}
                </div>
            </div>
        </li>
    );
}

function AddStep({ applicationId }: { applicationId: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: '',
        owner_type: 'student',
        owner_label: '',
        due_date: '',
    });

    if (!open) {
        return (
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="mt-4 h-8 gap-1.5 rounded-full px-3 text-muted-foreground"
                onClick={() => setOpen(true)}
            >
                <Plus className="size-4" aria-hidden />
                Add your own step
            </Button>
        );
    }

    return (
        <form
            className="mt-5 rounded-2xl border border-border bg-card p-4 sm:p-5"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/applications/${applicationId}/items`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        setOpen(false);
                    },
                });
            }}
        >
            <p className="mb-4 text-[14px] font-medium text-foreground">
                Add your own step
            </p>
            <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,11rem)_minmax(0,10rem)]">
                <div className="min-w-0">
                    <label htmlFor="new-step" className={labelClass}>
                        Step
                    </label>
                    <input
                        id="new-step"
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="e.g. Ask Mr. Okafor to review my essay"
                        className={fieldClass}
                    />
                    <InputError
                        message={form.errors.title}
                        className="mt-1.5 text-[12.5px]"
                    />
                </div>
                <div className="min-w-0">
                    <label htmlFor="new-step-owner" className={labelClass}>
                        Who does it
                    </label>
                    <select
                        id="new-step-owner"
                        className={fieldClass}
                        value={form.data.owner_type}
                        onChange={(e) =>
                            form.setData('owner_type', e.target.value)
                        }
                    >
                        <option value="student">Me</option>
                        <option value="third_party">Someone else</option>
                    </select>
                    {form.data.owner_type === 'third_party' ? (
                        <input
                            value={form.data.owner_label}
                            onChange={(e) =>
                                form.setData('owner_label', e.target.value)
                            }
                            placeholder="Who?"
                            aria-label="Who does it (name or role)"
                            className={cn(fieldClass, 'mt-2')}
                        />
                    ) : null}
                    <InputError
                        message={form.errors.owner_label}
                        className="mt-1.5 text-[12.5px]"
                    />
                </div>
                <div className="min-w-0">
                    <label htmlFor="new-step-due" className={labelClass}>
                        Due (optional)
                    </label>
                    <input
                        id="new-step-due"
                        type="date"
                        value={form.data.due_date}
                        onChange={(e) =>
                            form.setData('due_date', e.target.value)
                        }
                        className={fieldClass}
                    />
                </div>
            </div>
            <div className="mt-4 flex items-center gap-2">
                <Button
                    size="sm"
                    type="submit"
                    disabled={
                        form.processing || form.data.title.trim().length < 2
                    }
                    className="h-8 rounded-full px-4"
                >
                    Add step
                </Button>
                <Button
                    size="sm"
                    type="button"
                    variant="ghost"
                    className="h-8 rounded-full px-3 text-muted-foreground"
                    onClick={() => {
                        form.reset();
                        form.clearErrors();
                        setOpen(false);
                    }}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * A field-by-field plan for filling in the form by hand, labelled by where each value comes from.
 */
function FormPlanSection({
    applicationId,
    formPlan,
}: {
    applicationId: string;
    formPlan: Workspace['formPlan'];
}) {
    const [starting, setStarting] = useState(false);
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const plan = formPlan.plan;
    const sections = plan
        ? [...new Set(plan.entries.map((e) => e.section))]
        : [];
    const counts = new Map<string, number>();

    for (const entry of plan?.entries ?? []) {
        counts.set(entry.basis, (counts.get(entry.basis) ?? 0) + 1);
    }

    return (
        <Section
            title="Form plan"
            icon={ClipboardText}
            actions={
                <Button
                    size="sm"
                    variant={plan ? 'outline' : 'default'}
                    className="-mb-1.5 h-8 rounded-full px-3.5"
                    disabled={starting || formPlan.goalId !== null}
                    onClick={() => {
                        setStarting(true);
                        router.post(
                            `/applications/${applicationId}/form-plan`,
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => setStarting(false),
                            },
                        );
                    }}
                >
                    {formPlan.goalId
                        ? 'Preparing…'
                        : plan
                          ? 'Prepare again'
                          : 'Prepare the form plan'}
                </Button>
            }
        >
            {formPlan.note ? (
                <p className="mb-4 max-w-2xl text-[13px] leading-relaxed text-muted-foreground">
                    {formPlan.note}
                </p>
            ) : null}

            {formPlan.goalId ? (
                <div className="mb-4">
                    <TaskGoalCard
                        goalId={formPlan.goalId}
                        onFinished={() =>
                            router.reload({ only: ['workspace'] })
                        }
                    />
                </div>
            ) : null}

            {plan ? (
                <div className="overflow-hidden rounded-2xl border border-border bg-card">
                    <div className="flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4">
                        <div className="min-w-0 flex-1 basis-60">
                            <p className="text-[14.5px] font-medium text-foreground">
                                {formPlan.formLabel ?? 'Application form'}
                            </p>
                            <p className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[12.5px] text-muted-foreground">
                                <span className="nums">
                                    {plan.entries.length} field
                                    {plan.entries.length === 1 ? '' : 's'}
                                </span>
                                {[...counts.entries()].map(([basis, count]) => (
                                    <span
                                        key={basis}
                                        className="nums inline-flex items-center gap-1.5"
                                    >
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'size-1.5 rounded-full',
                                                BASIS_LABELS[basis]?.dot ??
                                                    'bg-muted-foreground',
                                            )}
                                        />
                                        {BASIS_LABELS[basis]?.label ?? basis} ·{' '}
                                        {count}
                                    </span>
                                ))}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-expanded={open}
                            aria-controls={panelId}
                            onClick={() => setOpen(!open)}
                            className="-ml-3 h-8 gap-1 rounded-full px-3 text-muted-foreground sm:ml-auto"
                        >
                            {open ? 'Hide fields' : 'Show fields'}
                            <CaretDown
                                aria-hidden
                                className={cn(
                                    'size-3.5 transition-transform duration-200',
                                    open && 'rotate-180',
                                )}
                            />
                        </Button>
                    </div>

                    {plan.outdated ? (
                        <p className="flex items-start gap-2 border-t border-border bg-warning/10 px-5 py-3 text-[13px] text-foreground">
                            <WarningCircle
                                weight="fill"
                                className="mt-px size-4 shrink-0 text-warning"
                                aria-hidden
                            />
                            Your saved details changed since this plan was made
                            — prepare it again.
                        </p>
                    ) : null}

                    <div
                        id={panelId}
                        hidden={!open}
                        className="border-t border-border"
                    >
                        <div className="hidden grid-cols-[minmax(0,11rem)_minmax(0,1fr)_minmax(0,9.5rem)] gap-4 bg-muted/40 px-5 py-2 text-[12.5px] text-muted-foreground md:grid">
                            <span>Field</span>
                            <span>Value</span>
                            <span>Comes from</span>
                        </div>
                        {sections.map((section) => (
                            <div
                                key={section}
                                className="border-t border-border first:border-t-0 md:first:border-t"
                            >
                                <p className="px-5 pt-3.5 pb-1 text-[13px] font-medium text-foreground">
                                    {section}
                                </p>
                                <ul className="divide-y divide-border">
                                    {plan.entries
                                        .filter((e) => e.section === section)
                                        .map((entry) => (
                                            <li
                                                key={entry.field}
                                                className="grid gap-x-4 gap-y-0.5 px-5 py-2.5 text-[13.5px] md:grid-cols-[minmax(0,11rem)_minmax(0,1fr)_minmax(0,9.5rem)]"
                                                data-basis={entry.basis}
                                            >
                                                <span className="text-muted-foreground">
                                                    {entry.field}
                                                    {entry.required ? ' *' : ''}
                                                </span>
                                                <span className="min-w-0 break-words text-foreground">
                                                    {entry.value ?? '—'}
                                                    {entry.note ? (
                                                        <span className="mt-0.5 block text-[12.5px] leading-snug text-muted-foreground">
                                                            {entry.note}
                                                        </span>
                                                    ) : null}
                                                </span>
                                                <span className="inline-flex items-start gap-1.5 text-[12.5px] text-muted-foreground">
                                                    <span
                                                        aria-hidden
                                                        className={cn(
                                                            'mt-[6px] size-1.5 shrink-0 rounded-full',
                                                            BASIS_LABELS[
                                                                entry.basis
                                                            ]?.dot ??
                                                                'bg-muted-foreground',
                                                        )}
                                                    />
                                                    {BASIS_LABELS[entry.basis]
                                                        ?.label ?? entry.basis}
                                                </span>
                                            </li>
                                        ))}
                                </ul>
                            </div>
                        ))}
                        <p className="border-t border-border px-5 py-3 text-[12.5px] text-muted-foreground">
                            * Required by the form.
                        </p>
                    </div>
                </div>
            ) : null}
        </Section>
    );
}

/**
 * The extension's work on this college's form: what it observed, fills to approve (the same proposals the
 * side panel shows) and each step's read-back. Refreshes itself while the extension is connected.
 */
function BrowserAssistanceSection({
    applicationId,
    browser,
}: {
    applicationId: string;
    browser: BrowserAssistance;
}) {
    const [preparing, setPreparing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const session = browser.session;
    const connected = session?.status === 'active';

    useEffect(() => {
        if (!connected) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['browser'] }),
            4000,
        );

        return () => clearInterval(timer);
    }, [connected]);

    async function prepare() {
        setPreparing(true);
        setError(null);

        try {
            const res = await fetch(
                `/applications/${applicationId}/browser-fill`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                },
            );

            if (!res.ok) {
                const data = (await res.json().catch(() => ({}))) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
                setError(
                    Object.values(data.errors ?? {})[0]?.[0] ??
                        data.message ??
                        'Could not prepare a fill.',
                );

                return;
            }

            router.reload({ only: ['browser'] });
        } catch {
            setError('Could not prepare a fill. Check your connection.');
        } finally {
            setPreparing(false);
        }
    }

    return (
        <Section title="Browser assistance" icon={Browsers}>
            <p className="mb-4 max-w-2xl text-[13px] leading-relaxed text-muted-foreground">
                The AdmitMe extension can type plain details from your profile
                into this college’s real form, after you approve them. You
                review and submit the form yourself; it never pays or uploads.
            </p>

            {session && session.status !== 'closed' && session.snapshot ? (
                <Button
                    size="sm"
                    variant="outline"
                    className="mb-4 h-8 rounded-full px-3.5"
                    disabled={preparing}
                    onClick={() => void prepare()}
                >
                    {preparing
                        ? 'Preparing…'
                        : 'Prepare fill from the observed page'}
                </Button>
            ) : null}

            {error ? <ErrorPill message={error} className="mb-4" /> : null}

            {!browser.linked && !session ? (
                <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 rounded-2xl border border-dashed border-border px-5 py-4">
                    <p className="text-[13.5px] text-muted-foreground">
                        No browser is linked. Link the extension to use it here.
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        asChild
                        className="h-8 rounded-full px-3.5"
                    >
                        <Link href="/settings/browser-extension">
                            Link the extension
                        </Link>
                    </Button>
                </div>
            ) : session ? (
                <BrowserSessionCard session={session} />
            ) : (
                <p className="rounded-2xl border border-dashed border-border px-5 py-4 text-[13.5px] leading-relaxed text-muted-foreground">
                    Open this college’s application form, then in the
                    extension’s side panel choose this application and press
                    “Use this tab”.
                </p>
            )}
        </Section>
    );
}

ApplicationWorkspacePage.layout = {
    breadcrumbs: [{ title: 'Applications', href: '/applications' }],
};
