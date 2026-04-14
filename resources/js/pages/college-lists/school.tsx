'use client';

import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarBlank,
    Coin,
    LinkSimple,
    MapPin,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    type ApplicationStatus,
    type CollegeRow,
    fitScoreStyles,
    tierLabel,
    tierStyles,
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

export default function CollegeListSchoolPage() {
    const { school } = usePage<PageProps>().props;

    const [applicationStatus, setApplicationStatus] = useState<ApplicationStatus>(
        school.applicationStatus,
    );
    const [studentNotes, setStudentNotes] = useState(school.studentNotes ?? '');
    const [savedFlash, setSavedFlash] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setApplicationStatus(school.applicationStatus);
        setStudentNotes(school.studentNotes ?? '');
    }, [school.applicationStatus, school.studentNotes, school.id]);

    const saveProgress = useCallback(
        async (patch: { application_status?: ApplicationStatus; student_notes?: string | null }) => {
            setSaving(true);
            try {
                const res = await fetch(`/college-lists/${school.id}`, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                    body: JSON.stringify(patch),
                });
                if (res.ok) {
                    setSavedFlash(true);
                    window.setTimeout(() => setSavedFlash(false), 2000);
                }
            } finally {
                setSaving(false);
            }
        },
        [school.id],
    );

    return (
        <>
            <Head title={school.name} />

            <div className="page-shell">
                <div className="mb-4">
                    <Button variant="ghost" size="sm" className="h-8 gap-1.5 px-2 text-xs" asChild>
                        <Link href={collegeLists.url()}>
                            <ArrowLeft className="size-3.5" aria-hidden />
                            Back to list
                        </Link>
                    </Button>
                </div>

                <header className="mb-6 space-y-2 border-b border-border/50 pb-4">
                    <h1 className="page-title text-balance">{school.name}</h1>
                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <MapPin className="size-4 shrink-0" aria-hidden />
                        {school.location}
                    </p>
                </header>

                <div className="mx-auto max-w-2xl space-y-0 divide-y divide-border/40">
                    <section className="py-4">
                        <h2 className="mb-3 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            Fit breakdown
                        </h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <span
                                className={cn(
                                    'rounded-md border border-border/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                    tierStyles(school.tier),
                                )}
                            >
                                {tierLabel(school.tier)}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Overall fit{' '}
                                <strong
                                    className={cn(
                                        'text-base font-semibold text-foreground',
                                        fitScoreStyles(school.fitScore),
                                    )}
                                >
                                    {school.fitScore}
                                </strong>
                                <span className="text-muted-foreground">/100</span>
                            </span>
                        </div>
                    </section>
                    <section className="py-4">
                        <h2 className="mb-3 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            Why this school
                        </h2>
                        <p className="text-sm leading-relaxed text-foreground">{school.whyFit}</p>
                    </section>
                    <section className="py-4">
                        <h2 className="mb-3 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            My progress
                        </h2>
                        <div className="space-y-3">
                            <div>
                                <Label className="text-xs text-muted-foreground">Application status</Label>
                                <Select
                                    value={applicationStatus}
                                    onValueChange={(v) => {
                                        const next = v as ApplicationStatus;
                                        setApplicationStatus(next);
                                        void saveProgress({ application_status: next });
                                    }}
                                    disabled={saving}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {STATUS_OPTIONS.map((o) => (
                                            <SelectItem key={o.value} value={o.value}>
                                                {o.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="school-notes" className="text-xs text-muted-foreground">
                                    Your notes
                                </Label>
                                <textarea
                                    id="school-notes"
                                    value={studentNotes}
                                    onChange={(e) => setStudentNotes(e.target.value)}
                                    onBlur={() => {
                                        void saveProgress({
                                            student_notes: studentNotes.trim() === '' ? null : studentNotes.trim(),
                                        });
                                    }}
                                    rows={4}
                                    maxLength={1000}
                                    disabled={saving}
                                    className="mt-1 flex min-h-[100px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    placeholder="Deadlines you’re tracking, visit plans, essay ideas…"
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {studentNotes.length}/1000 · saved when you leave the field
                                </p>
                            </div>
                            {savedFlash ? (
                                <p className="text-xs font-medium text-chart-2">Saved</p>
                            ) : null}
                        </div>
                    </section>
                    <section className="py-4">
                        <h2 className="mb-3 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            <CalendarBlank className="size-3" aria-hidden />
                            Deadlines
                        </h2>
                        <p className="whitespace-pre-line text-sm leading-snug text-foreground">
                            {school.deadlinesDisplay?.trim() || school.deadline || '—'}
                        </p>
                        {school.admissionsCycleLabel ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Cycle:{' '}
                                <span className="font-medium text-foreground">
                                    {school.admissionsCycleLabel}
                                </span>
                            </p>
                        ) : null}
                    </section>
                    <section className="py-4">
                        <h2 className="mb-3 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            <LinkSimple className="size-3" aria-hidden />
                            Application
                        </h2>
                        {school.applicationPortalUrl ? (
                            <a
                                href={school.applicationPortalUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm text-primary underline-offset-2 hover:underline"
                            >
                                Open official portal
                            </a>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No verified portal link saved — confirm on the school’s admissions site.
                            </p>
                        )}
                    </section>
                    {school.netCostEstimate === '—' && school.aidEstimate === '—' ? (
                        <p className="text-sm text-muted-foreground">
                            Financial aid estimate not yet available for this school.
                        </p>
                    ) : (
                        <section className="py-4">
                            <h2 className="mb-3 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                                <Coin className="size-3" aria-hidden />
                                Financial aid estimator
                            </h2>
                            <p className="text-xs text-muted-foreground">Net cost line (rough band)</p>
                            <p className="mt-1 text-sm font-medium text-foreground">
                                {school.netCostEstimate}
                            </p>
                            <p className="mt-3 text-xs text-muted-foreground">Aid outlook</p>
                            <p className="mt-0.5 text-sm leading-snug text-foreground">
                                {school.aidEstimate}
                            </p>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

CollegeListSchoolPage.layout = {
    breadcrumbs: [{ title: 'College lists', href: collegeLists.url() }],
};
