'use client';

import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowSquareOut,
    ClipboardText,
    Info,
    NotePencil,
    Plus,
    Scales,
    WarningCircle,
} from '@phosphor-icons/react';
import { useCallback, useState } from 'react';
import { CollegeAssessmentView } from '@/components/admitme/college-assessment';
import { CollegeKnowledge } from '@/components/admitme/college-knowledge';
import {
    Callout,
    CHOICE_TONE,
    deadlineOf,
    joinParts,
    placeOf,
    TierIcon,
    TIERS,
} from '@/components/college/college-ui';
import {
    KeyValueList,
    KeyValueRow,
    Page,
    PageHeader,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { choiceStatusLabel } from '@/lib/college-list-display';
import type {
    ApplicationStatus,
    CollegeChoiceStatus,
    CollegeRow,
} from '@/lib/college-list-display';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import { collegeLists } from '@/routes';

type PageProps = {
    school: CollegeRow;
};

const STATUS_OPTIONS: { value: ApplicationStatus; label: string }[] = [
    { value: 'not_started', label: 'Not started' },
    { value: 'researching', label: 'Researching' },
    { value: 'applying', label: 'Applying' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'waitlisted', label: 'Waitlisted' },
];

const cardClass = 'rounded-2xl border border-border bg-card';

export default function CollegeListSchoolPage() {
    const { school } = usePage<PageProps>().props;

    const [applicationStatus, setApplicationStatus] =
        useState<ApplicationStatus>(school.applicationStatus);
    const [studentNotes, setStudentNotes] = useState(school.studentNotes ?? '');
    const [choiceStatus, setChoiceStatus] = useState<CollegeChoiceStatus>(
        school.choiceStatus,
    );
    const [revision, setRevision] = useState(school.revision);
    const [applicationId, setApplicationId] = useState(school.applicationId);
    const [conflict, setConflict] = useState<string | null>(null);
    const [savedFlash, setSavedFlash] = useState(false);
    const [saving, setSaving] = useState(false);

    // When the server sends a different version of this college, start again from it.
    const serverVersion = JSON.stringify([
        school.id,
        school.applicationStatus,
        school.studentNotes,
        school.choiceStatus,
        school.revision,
        school.applicationId,
    ]);
    const [syncedVersion, setSyncedVersion] = useState(serverVersion);

    if (syncedVersion !== serverVersion) {
        setSyncedVersion(serverVersion);
        setApplicationStatus(school.applicationStatus);
        setStudentNotes(school.studentNotes ?? '');
        setChoiceStatus(school.choiceStatus);
        setRevision(school.revision);
        setApplicationId(school.applicationId);
        setConflict(null);
    }

    const saveProgress = useCallback(
        async (patch: {
            application_status?: ApplicationStatus;
            student_notes?: string | null;
            choice_status?: CollegeChoiceStatus;
        }) => {
            setSaving(true);

            try {
                const res = await fetch(`/college-lists/${school.id}`, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                    body: JSON.stringify({
                        ...patch,
                        expected_revision: revision,
                    }),
                });
                const data = (await res.json().catch(() => ({}))) as {
                    message?: string;
                    revision?: number;
                    choiceStatus?: CollegeChoiceStatus;
                    applicationStatus?: ApplicationStatus;
                    applicationId?: string | null;
                };

                if (res.status === 409) {
                    // Someone (another tab, the mentor) changed this college first; don't overwrite it.
                    setConflict(
                        data.message ??
                            'This college changed since you opened it.',
                    );

                    return;
                }

                if (res.ok) {
                    if (typeof data.revision === 'number') {
                        setRevision(data.revision);
                    }

                    if (data.choiceStatus) {
                        setChoiceStatus(data.choiceStatus);
                    }

                    if (data.applicationStatus) {
                        setApplicationStatus(data.applicationStatus);
                    }

                    setApplicationId(data.applicationId ?? null);
                    setSavedFlash(true);
                    window.setTimeout(() => setSavedFlash(false), 2000);
                }
            } finally {
                setSaving(false);
            }
        },
        [school.id, revision],
    );

    const locked = saving || conflict !== null;
    const deadline = deadlineOf(school);
    const noAidEstimate =
        school.netCostEstimate === '—' && school.aidEstimate === '—';
    const whyFit =
        school.whyFit && school.whyFit !== '—' ? school.whyFit : null;

    const primaryAction =
        choiceStatus === 'suggested' ? (
            <Button
                className="h-9 gap-2 rounded-full px-4"
                disabled={locked}
                onClick={() =>
                    void saveProgress({ choice_status: 'shortlisted' })
                }
            >
                <Plus className="size-4" />
                Add to my shortlist
            </Button>
        ) : choiceStatus === 'shortlisted' ? (
            <Button
                className="h-9 gap-2 rounded-full px-4"
                disabled={locked}
                onClick={() => void saveProgress({ choice_status: 'applying' })}
            >
                <ClipboardText className="size-4" />
                Start application
            </Button>
        ) : choiceStatus === 'applying' && applicationId ? (
            <Button className="h-9 gap-2 rounded-full px-4" asChild>
                <Link href={`/applications/${applicationId}`}>
                    Open application
                    <ArrowRight className="size-4" />
                </Link>
            </Button>
        ) : undefined;

    return (
        <>
            <Head title={school.name} />

            <Page width="narrow">
                <PageHeader
                    eyebrow={
                        <Link
                            href={collegeLists.url()}
                            className="inline-flex items-center gap-1 rounded-md transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <ArrowLeft className="size-3.5" aria-hidden />
                            College lists
                        </Link>
                    }
                    title={<span className="text-balance">{school.name}</span>}
                    description={
                        <>
                            <span className="flex items-center gap-1.5">
                                <TierIcon tier={school.tier} size="sm" />
                                {joinParts([
                                    TIERS[school.tier].label,
                                    placeOf(school),
                                ])}
                            </span>
                            {whyFit ? (
                                <span className="mt-1.5 block">{whyFit}</span>
                            ) : null}
                        </>
                    }
                    actions={primaryAction}
                />

                {conflict ? (
                    <div role="alert" className="mb-8">
                        <Callout
                            tone="danger"
                            icon={WarningCircle}
                            title={conflict}
                            action={
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-8 rounded-full bg-card px-3.5"
                                    onClick={() => router.reload()}
                                >
                                    Reload latest
                                </Button>
                            }
                        />
                    </div>
                ) : null}

                <Section title="Key facts" icon={Info}>
                    <div className={cn(cardClass, 'px-5')}>
                        <KeyValueList>
                            <KeyValueRow
                                label="Overall fit"
                                value={
                                    <span className="nums">
                                        {school.fitScore}
                                        <span className="font-normal text-muted-foreground">
                                            /100
                                        </span>
                                    </span>
                                }
                            />
                            <KeyValueRow
                                label="Deadlines"
                                value={
                                    <span
                                        className={cn(
                                            'whitespace-pre-line',
                                            deadline.none &&
                                                'font-normal text-muted-foreground',
                                        )}
                                    >
                                        {deadline.text}
                                    </span>
                                }
                            />
                            {school.admissionsCycleLabel ? (
                                <KeyValueRow
                                    label="Admissions cycle"
                                    value={school.admissionsCycleLabel}
                                />
                            ) : null}
                            <KeyValueRow
                                label="Application portal"
                                hint={
                                    school.applicationPortalUrl
                                        ? undefined
                                        : 'Confirm on the college’s admissions site.'
                                }
                                value={
                                    school.applicationPortalUrl ? (
                                        <a
                                            href={school.applicationPortalUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1 underline-offset-2 hover:underline"
                                        >
                                            Open official portal
                                            <ArrowSquareOut
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        </a>
                                    ) : (
                                        <span className="font-normal text-muted-foreground">
                                            No verified link saved
                                        </span>
                                    )
                                }
                            />
                            {noAidEstimate ? (
                                <KeyValueRow
                                    label="Financial aid estimate"
                                    value={
                                        <span className="font-normal text-muted-foreground">
                                            Not yet available
                                        </span>
                                    }
                                />
                            ) : (
                                <>
                                    <KeyValueRow
                                        label="Net cost (rough band)"
                                        hint="AI research — not verified"
                                        value={school.netCostEstimate}
                                    />
                                    <KeyValueRow
                                        label="Aid outlook"
                                        hint="AI research — not verified"
                                        value={school.aidEstimate}
                                    />
                                </>
                            )}
                        </KeyValueList>
                    </div>
                </Section>

                {school.assessment ? (
                    <Section title="Fit check" icon={Scales}>
                        <div className={cn(cardClass, 'p-5')}>
                            <CollegeAssessmentView
                                assessment={school.assessment}
                            />
                        </div>
                    </Section>
                ) : null}

                <Section
                    title="Your progress"
                    icon={NotePencil}
                    actions={
                        <span aria-live="polite" className="text-[12.5px]">
                            {saving ? (
                                <span className="text-muted-foreground">
                                    Saving…
                                </span>
                            ) : savedFlash ? (
                                <span className="text-emerald-700 dark:text-emerald-300">
                                    Saved
                                </span>
                            ) : null}
                        </span>
                    }
                >
                    <div className={cn(cardClass, 'px-5')}>
                        <KeyValueList>
                            <KeyValueRow
                                label="List status"
                                value={
                                    <StatusBadge
                                        tone={CHOICE_TONE[choiceStatus]}
                                    >
                                        {choiceStatusLabel(choiceStatus)}
                                    </StatusBadge>
                                }
                            />
                            <KeyValueRow
                                label="Application status"
                                value={
                                    <Select
                                        value={applicationStatus}
                                        onValueChange={(v) => {
                                            const next = v as ApplicationStatus;
                                            setApplicationStatus(next);
                                            void saveProgress({
                                                application_status: next,
                                            });
                                        }}
                                        disabled={locked}
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="h-8 rounded-lg text-[13px] font-normal"
                                            aria-label="Application status"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent align="end">
                                            {STATUS_OPTIONS.map((o) => (
                                                <SelectItem
                                                    key={o.value}
                                                    value={o.value}
                                                >
                                                    {o.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                }
                            />
                        </KeyValueList>
                        <div className="border-t border-border py-4">
                            <label
                                htmlFor="school-notes"
                                className="text-[14px] text-foreground"
                            >
                                Your notes
                            </label>
                            <textarea
                                id="school-notes"
                                value={studentNotes}
                                onChange={(e) =>
                                    setStudentNotes(e.target.value)
                                }
                                onBlur={() => {
                                    void saveProgress({
                                        student_notes:
                                            studentNotes.trim() === ''
                                                ? null
                                                : studentNotes.trim(),
                                    });
                                }}
                                rows={4}
                                maxLength={1000}
                                disabled={locked}
                                className="mt-2 flex min-h-[100px] w-full resize-y rounded-xl border border-input bg-background px-3 py-2.5 text-[14px] leading-relaxed text-foreground transition-[border-color,box-shadow] outline-none placeholder:text-muted-foreground/70 focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25 disabled:opacity-60"
                                placeholder="Deadlines you’re tracking, visit plans, essay ideas…"
                            />
                            <p className="nums mt-1.5 text-[12.5px] text-muted-foreground">
                                {studentNotes.length}/1000 · saved when you
                                leave the field
                            </p>
                        </div>
                    </div>
                </Section>

                <CollegeKnowledge schoolId={school.id} />
            </Page>
        </>
    );
}

CollegeListSchoolPage.layout = {
    breadcrumbs: [{ title: 'College lists', href: collegeLists.url() }],
};
