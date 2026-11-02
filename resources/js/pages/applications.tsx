import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowSquareOut,
    Buildings,
    CaretDown,
    ClipboardText,
    Plus,
} from '@phosphor-icons/react';
import { useId, useState } from 'react';
import {
    APPLICATION_STATUS,
    fieldClass,
    formatDay,
    formatMoment,
    labelClass,
    textareaClass,
} from '@/components/admitme/application-parts';
import InputError from '@/components/input-error';
import {
    EmptyState,
    IconTile,
    ListCard,
    ListRow,
    Page,
    PageHeader,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import { cn } from '@/lib/utils';

type Option = { value: string; label: string };

type ApplicationRow = {
    id: string;
    revision: number;
    status: string;
    statusLabel: string;
    nextStatuses: string[];
    applicationSystem: string | null;
    portalUrl: string | null;
    cycleLabel: string | null;
    notes: string | null;
    submittedAt: string | null;
    submissionEvidenceType: string | null;
    submissionEvidenceNote: string | null;
    college: {
        id: string;
        name: string;
        tier: string;
        choiceStatus: string;
        deadlines: Record<string, string>;
    };
};

type StartableCollege = {
    id: string;
    name: string;
    tier: string;
    revision: number;
};

type PageProps = {
    applications: ApplicationRow[];
    startable: StartableCollege[];
    statusOptions: Option[];
    systemOptions: string[];
    evidenceOptions: Option[];
};

const SYSTEM_LABELS: Record<string, string> = {
    common_app: 'Common App',
    coalition: 'Coalition / Scoir',
    ucas: 'UCAS',
    institution_portal: 'College’s own portal',
    other: 'Other',
};

const TIER_LABELS: Record<string, string> = {
    reach: 'Reach',
    match: 'Match',
    safety: 'Safety',
};

export default function ApplicationsPage({
    applications,
    startable,
    statusOptions,
    systemOptions,
    evidenceOptions,
}: PageProps) {
    return (
        <>
            <Head title="Applications" />

            <Page>
                <PageHeader
                    title="Applications"
                    description="One for each college you apply to. AdmitMe never marks an application submitted on its own — you record how you know it went in."
                />

                {applications.length === 0 ? (
                    <Section>
                        <EmptyState
                            icon={
                                <IconTile
                                    icon={ClipboardText}
                                    tone="blue"
                                    variant="glyph"
                                    size="lg"
                                />
                            }
                            title="No applications yet"
                            description={
                                <>
                                    {startable.length > 0
                                        ? 'Start one below from a shortlisted college, or open'
                                        : 'Open'}{' '}
                                    a college on your{' '}
                                    <Link
                                        href="/college-lists"
                                        className="font-medium text-foreground underline-offset-2 hover:underline"
                                    >
                                        list
                                    </Link>{' '}
                                    and choose “Start application”.
                                </>
                            }
                        />
                    </Section>
                ) : (
                    <Section
                        title={`${applications.length} ${applications.length === 1 ? 'application' : 'applications'}`}
                        icon={ClipboardText}
                    >
                        <ListCard>
                            {applications.map((application) => (
                                <ApplicationItem
                                    key={`${application.id}:${application.revision}`}
                                    application={application}
                                    statusOptions={statusOptions}
                                    systemOptions={systemOptions}
                                    evidenceOptions={evidenceOptions}
                                />
                            ))}
                        </ListCard>
                    </Section>
                )}

                {startable.length > 0 ? (
                    <StartApplications colleges={startable} />
                ) : null}
            </Page>
        </>
    );
}

function StartApplications({ colleges }: { colleges: StartableCollege[] }) {
    const [busyId, setBusyId] = useState<string | null>(null);

    return (
        <Section
            title="Start an application"
            icon={Plus}
            description="Shortlisted colleges that don’t have one yet."
        >
            <ListCard>
                {colleges.map((college) => (
                    <ListRow
                        key={college.id}
                        icon={
                            <IconTile
                                icon={Buildings}
                                tone="slate"
                                variant="glyph"
                            />
                        }
                        title={college.name}
                        description={TIER_LABELS[college.tier] ?? college.tier}
                        trailing={
                            <Button
                                size="sm"
                                variant="outline"
                                className="rounded-full px-3.5"
                                disabled={busyId !== null}
                                aria-label={`Start application for ${college.name}`}
                                onClick={() => {
                                    setBusyId(college.id);
                                    router.post(
                                        '/applications',
                                        {
                                            college_list_school_id: college.id,
                                            expected_revision: college.revision,
                                        },
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setBusyId(null),
                                        },
                                    );
                                }}
                            >
                                {busyId === college.id ? (
                                    'Starting…'
                                ) : (
                                    <>
                                        Start
                                        <span className="hidden sm:inline">
                                            {' '}
                                            application
                                        </span>
                                    </>
                                )}
                            </Button>
                        }
                    />
                ))}
            </ListCard>
        </Section>
    );
}

/** One application: a row that opens its workspace, and a disclosure to update status, system, portal and notes. */
function ApplicationItem({
    application,
    statusOptions,
    systemOptions,
    evidenceOptions,
}: {
    application: ApplicationRow;
    statusOptions: Option[];
    systemOptions: string[];
    evidenceOptions: Option[];
}) {
    const form = useForm({
        status: application.status,
        application_system: application.applicationSystem ?? '',
        portal_url: application.portalUrl ?? '',
        cycle_label: application.cycleLabel ?? '',
        notes: application.notes ?? '',
        submission_evidence_type: application.submissionEvidenceType ?? '',
        submission_evidence_note: application.submissionEvidenceNote ?? '',
        expected_revision: application.revision,
    });
    const hydrated = useHydrated();
    const [open, setOpen] = useState(false);
    const panelId = useId();

    const allowedStatuses = statusOptions.filter((option) =>
        application.nextStatuses.includes(option.value),
    );
    const showEvidence =
        form.data.status === 'submitted' || application.submittedAt !== null;
    const style =
        APPLICATION_STATUS[application.status] ?? APPLICATION_STATUS.preparing;
    // Errors from the server always stay in view, even if the panel was closed.
    const expanded = open || form.hasErrors;
    const deadlines = Object.entries(application.college.deadlines).map(
        ([round, date]) => `${round} ${formatDay(date)}`,
    );
    const submitted =
        application.submittedAt && hydrated
            ? `Submitted ${formatMoment(application.submittedAt, 'date')}`
            : null;
    const summary = [
        submitted,
        deadlines.length > 0 ? deadlines.join(' · ') : 'No deadline saved',
        application.applicationSystem
            ? (SYSTEM_LABELS[application.applicationSystem] ??
              application.applicationSystem)
            : null,
        application.cycleLabel,
        TIER_LABELS[application.college.tier] ?? application.college.tier,
    ]
        .filter(Boolean)
        .join(' · ');

    /** Editing opens the panel, so a change is never made out of sight. */
    const set = (
        key: Exclude<keyof typeof form.data, 'expected_revision'>,
        value: string,
    ) => {
        form.setData(key, value);
        setOpen(true);
    };

    return (
        <li>
            <div className="relative">
                <Link
                    href={`/applications/${application.id}`}
                    className="flex w-full items-center gap-3.5 py-4 pr-14 pl-4 text-left transition-colors hover:bg-muted/60 sm:gap-4 sm:pr-[104px] sm:pl-5"
                >
                    <IconTile
                        icon={Buildings}
                        tone={style.icon}
                        variant="glyph"
                    />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-[15px] font-medium text-foreground">
                            {application.college.name}
                        </span>
                        <span className="mt-0.5 block truncate text-[13.5px] text-muted-foreground">
                            <span className="sm:hidden">
                                {application.statusLabel} ·{' '}
                            </span>
                            {summary}
                        </span>
                    </span>
                    <StatusBadge
                        tone={style.tone}
                        className="hidden sm:inline-flex"
                    >
                        {application.statusLabel}
                    </StatusBadge>
                </Link>
                <div className="absolute inset-y-0 right-2.5 flex items-center sm:right-4">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-expanded={expanded}
                        aria-controls={panelId}
                        aria-label={`${expanded ? 'Close' : 'Edit'} details for ${application.college.name}${form.isDirty ? ' (unsaved changes)' : ''}`}
                        onClick={() => {
                            if (expanded) {
                                // Closing keeps unsaved values (the dot shows them) but drops stale messages.
                                form.clearErrors();
                                setOpen(false);
                            } else {
                                setOpen(true);
                            }
                        }}
                        className="relative gap-1 rounded-full px-2.5 text-muted-foreground"
                    >
                        <span className="hidden sm:inline">
                            {expanded ? 'Close' : 'Edit'}
                        </span>
                        <CaretDown
                            aria-hidden
                            className={cn(
                                'size-4 transition-transform duration-200',
                                expanded && 'rotate-180',
                            )}
                        />
                        {form.isDirty && !expanded ? (
                            <span
                                aria-hidden
                                className="absolute top-1 right-1 size-1.5 rounded-full bg-brand"
                            />
                        ) : null}
                    </Button>
                </div>
            </div>

            <div
                id={panelId}
                hidden={!expanded}
                className="border-t border-border bg-muted/30 px-5 py-5"
            >
                <form
                    className="grid gap-x-4 gap-y-4 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(`/applications/${application.id}`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    {form.errors.expected_revision ? (
                        <div
                            role="alert"
                            className="flex flex-col gap-2 rounded-xl bg-destructive/10 px-3.5 py-2.5 text-[13px] text-foreground sm:col-span-2 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <span>{form.errors.expected_revision}</span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-7 rounded-full"
                                onClick={() => router.reload()}
                            >
                                Reload latest
                            </Button>
                        </div>
                    ) : null}

                    <div className="min-w-0">
                        <label
                            htmlFor={`status-${application.id}`}
                            className={labelClass}
                        >
                            Status
                        </label>
                        <select
                            id={`status-${application.id}`}
                            className={fieldClass}
                            value={form.data.status}
                            onChange={(event) =>
                                set('status', event.target.value)
                            }
                        >
                            {allowedStatuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError
                            message={form.errors.status}
                            className="mt-1.5 text-[12.5px]"
                        />
                    </div>

                    <div className="min-w-0">
                        <label
                            htmlFor={`system-${application.id}`}
                            className={labelClass}
                        >
                            Application system
                        </label>
                        <select
                            id={`system-${application.id}`}
                            className={fieldClass}
                            value={form.data.application_system}
                            onChange={(event) =>
                                set('application_system', event.target.value)
                            }
                        >
                            <option value="">Not decided</option>
                            {systemOptions.map((system) => (
                                <option key={system} value={system}>
                                    {SYSTEM_LABELS[system] ?? system}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="min-w-0">
                        <label
                            htmlFor={`portal-${application.id}`}
                            className={labelClass}
                        >
                            Portal link
                        </label>
                        <div className="flex items-center gap-2">
                            <input
                                id={`portal-${application.id}`}
                                type="url"
                                placeholder="https://"
                                className={fieldClass}
                                value={form.data.portal_url}
                                onChange={(event) =>
                                    set('portal_url', event.target.value)
                                }
                            />
                            {application.portalUrl ? (
                                <a
                                    href={application.portalUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    aria-label="Open portal in a new tab"
                                >
                                    <ArrowSquareOut className="size-4" />
                                </a>
                            ) : null}
                        </div>
                        <InputError
                            message={form.errors.portal_url}
                            className="mt-1.5 text-[12.5px]"
                        />
                    </div>

                    <div className="min-w-0">
                        <label
                            htmlFor={`cycle-${application.id}`}
                            className={labelClass}
                        >
                            Admissions cycle
                        </label>
                        <input
                            id={`cycle-${application.id}`}
                            className={fieldClass}
                            placeholder="e.g. Fall 2027 entry"
                            value={form.data.cycle_label}
                            onChange={(event) =>
                                set('cycle_label', event.target.value)
                            }
                        />
                    </div>

                    {showEvidence ? (
                        <>
                            <div className="min-w-0">
                                <label
                                    htmlFor={`evidence-${application.id}`}
                                    className={labelClass}
                                >
                                    How do you know it was submitted?
                                </label>
                                <select
                                    id={`evidence-${application.id}`}
                                    className={fieldClass}
                                    value={form.data.submission_evidence_type}
                                    onChange={(event) =>
                                        set(
                                            'submission_evidence_type',
                                            event.target.value,
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
                                <InputError
                                    message={
                                        form.errors.submission_evidence_type
                                    }
                                    className="mt-1.5 text-[12.5px]"
                                />
                            </div>
                            <div className="min-w-0">
                                <label
                                    htmlFor={`evidence-note-${application.id}`}
                                    className={labelClass}
                                >
                                    Evidence note (confirmation number, email
                                    date…)
                                </label>
                                <input
                                    id={`evidence-note-${application.id}`}
                                    className={fieldClass}
                                    value={form.data.submission_evidence_note}
                                    onChange={(event) =>
                                        set(
                                            'submission_evidence_note',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </>
                    ) : null}

                    <div className="min-w-0 sm:col-span-2">
                        <label
                            htmlFor={`notes-${application.id}`}
                            className={labelClass}
                        >
                            Notes
                        </label>
                        <textarea
                            id={`notes-${application.id}`}
                            rows={3}
                            maxLength={5000}
                            value={form.data.notes}
                            onChange={(event) =>
                                set('notes', event.target.value)
                            }
                            className={textareaClass}
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-2 sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            className="rounded-full px-4"
                            disabled={form.processing || !form.isDirty}
                        >
                            Save changes
                        </Button>
                        {form.isDirty ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="rounded-full px-4"
                                disabled={form.processing}
                                onClick={() => {
                                    form.reset();
                                    form.clearErrors();
                                    setOpen(false);
                                }}
                            >
                                Discard
                            </Button>
                        ) : null}
                        {form.recentlySuccessful ? (
                            <span className="text-[13px] text-muted-foreground">
                                Saved
                            </span>
                        ) : null}
                    </div>
                </form>
            </div>
        </li>
    );
}
