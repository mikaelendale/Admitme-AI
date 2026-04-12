'use client';

import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ChartLine,
    Gauge,
    Lightning,
    PencilSimpleLine,
    UserCircle,
} from '@phosphor-icons/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { Spinner } from '@/components/ui/spinner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { dashboard } from '@/routes';
import { edit as settingsProfile } from '@/routes/profile';
import { profileOverview } from '@/routes';
import { toast, Toaster } from 'sonner';

export type RadarDimension = {
    key: string;
    label: string;
    score: number | null;
    verdict: string | null;
};

export type ClarificationField = {
    key: string;
    type: string;
    label: string;
    options?: string[];
};

export type ProfileOverviewPayload = {
    status: string;
    profile_dump_text: string | null;
    resume_pdf_unreadable: boolean;
    demographics: {
        age_range: string | null;
        age_range_label: string | null;
        current_grade: string | null;
        graduation_year: number | null;
    };
    academics: {
        curriculum: string | null;
        gpa_value: string | null;
        gpa_scale: string | null;
        gpa_type: string | null;
        gpa_normalized_4: string | null;
        sat_score: number | null;
        act_score: number | null;
        test_optional_status: string | null;
        citizenship_residency: string | null;
        location_country: string | null;
        location_region: string | null;
        grade_trend: string | null;
    };
    targets: {
        intended_majors: string[];
        dream_schools: string[];
        region_preferences: string[];
        campus_size_pref: string | null;
        financial_aid_need_percent: number | null;
        target_countries: string[];
        budget_band: string | null;
    };
    completeness_score: number;
    completeness_callouts: string[];
    student_summary: string | null;
    spike_summary: string | null;
    open_questions: string[];
    activities: { label?: string }[];
    awards: { label?: string }[];
    ai: {
        headline: string | null;
        excerpt: string | null;
        signals: string[];
        takeaways: string[];
        radar: RadarDimension[];
        last_structured_at: string | null;
        last_context_at: string | null;
    };
};

type MentorPreferences = {
    mentor_tone: string;
    weekly_focus_nudges: boolean;
    parent_access: boolean;
    mood_checkins: boolean;
};

export type ResumePdfImportProps = {
    unlimited: boolean;
    remaining: number | null;
    used: number;
    max_per_pass: number;
};

type PageProps = {
    has_profile: boolean;
    completion: { current_step: number; completed_steps: number[] };
    clarification_fields: ClarificationField[];
    profile_modes: Record<string, boolean>;
    mentor_preferences: MentorPreferences;
    resume_pdf_import: ResumePdfImportProps;
    profile: ProfileOverviewPayload | null;
};

const STEPS = [
    { n: 1, label: 'Brain dump' },
    { n: 2, label: 'Clarify' },
    { n: 3, label: 'Goals' },
    { n: 4, label: 'Review' },
    { n: 5, label: 'Mentor' },
];

function isProfileWizardComplete(
    profile: ProfileOverviewPayload,
    completion: PageProps['completion'],
): boolean {
    if (profile.completeness_score >= 100) {
        return true;
    }
    const done = new Set(completion.completed_steps ?? []);
    return [1, 2, 3, 4, 5].every((n) => done.has(n));
}

const REGION_OPTIONS = [
    'Northeast',
    'South',
    'Midwest',
    'West Coast',
    'No preference',
    'Outside US',
];

/** Clarify step: required when present in `clarification_fields` (per product spec). */
const REQUIRED_CLARIFY_KEYS = new Set(['current_grade', 'gpa_value', 'gpa_scale']);

function firstErrorMessage(
    errors: Record<string, string | string[] | undefined>,
): string | null {
    for (const v of Object.values(errors)) {
        if (v == null) continue;
        if (Array.isArray(v)) return v[0] ?? null;
        if (typeof v === 'string' && v.length > 0) return v;
    }
    return null;
}

function toastSaved() {
    toast.success('Saved');
}

function toastServerErrors(errors: Record<string, unknown>) {
    const resume = errors.resume;
    if (
        typeof resume === 'string' &&
        /limited on your plan|subscribe for unlimited/i.test(resume)
    ) {
        toast.error(
            <div className="flex flex-col gap-1.5">
                <span>
                    You&apos;ve reached your PDF import limit. Upgrade to Pro for
                    unlimited imports.
                </span>
                <Link
                    href="/settings/billing"
                    className="font-medium text-primary underline-offset-4 hover:underline"
                >
                    Upgrade to Pro
                </Link>
            </div>,
            { duration: 10_000 },
        );
        return;
    }
    const msg = firstErrorMessage(errors as Record<string, string | string[] | undefined>);
    if (msg) {
        toast.error(msg);
    }
}

const TONE_PREVIEW: Record<string, string> = {
    brutally_honest:
        'Your GPA is below the median for your target schools. Here is what that means.',
    balanced:
        'Your GPA is a bit below average for these schools, but your ECs help offset it.',
    supportive:
        'You are in a competitive position — let us focus on the gaps that matter most.',
};

function normalizedGpaReadout(
    gpaValue: string | null,
    gpaScale: string | null,
    gpaType: string | null,
): { display: string; detail: string } | null {
    const v =
        gpaValue != null ? Number.parseFloat(gpaValue.replace(',', '.')) : NaN;
    const s =
        gpaScale != null ? Number.parseFloat(gpaScale.replace(',', '.')) : NaN;
    if (!Number.isFinite(v) || !Number.isFinite(s) || s <= 0) {
        return null;
    }
    const onFour = Math.min(4, Math.max(0, (v / s) * 4));
    const rounded = Math.round(onFour * 100) / 100;

    return {
        display: rounded.toFixed(2),
        detail: `${v} on ${s}${gpaType ? ` · ${gpaType}` : ''} → proportional 4.0-relative readout for scanning; not an official recalculation.`,
    };
}

function spikeThemes(
    radar: RadarDimension[],
    signals: string[],
): { label: string; strength: number }[] {
    const fromRadar = [...radar]
        .filter((d) => d.score !== null && !Number.isNaN(d.score))
        .sort((a, b) => (b.score ?? 0) - (a.score ?? 0))
        .slice(0, 4)
        .map((d) => ({ label: d.label, strength: d.score ?? 0 }));

    if (fromRadar.length > 0) {
        return fromRadar;
    }

    return signals.slice(0, 4).map((label, i) => ({
        label,
        strength: 72 + (i % 3) * 8,
    }));
}

function trajectorySeries(completeness: number): number[] {
    const c = Math.min(100, Math.max(0, completeness));
    return [
        52 + c * 0.08,
        48 + c * 0.1,
        55 + c * 0.09,
        58 + c * 0.11,
        62 + c * 0.1,
        65 + c * 0.12,
        68 + c * 0.11,
        70 + c * 0.13,
        72 + c * 0.12,
        Math.min(98, 74 + c * 0.14),
    ].map((n) => Math.round(n * 10) / 10);
}

function MiniSparkline({ values }: { values: number[] }) {
    if (values.length < 2) {
        return null;
    }
    const min = Math.min(...values);
    const max = Math.max(...values);
    const pad = 2;
    const w = 120;
    const h = 36;
    const span = max - min || 1;
    const pts = values
        .map((v, i) => {
            const x = pad + (i / (values.length - 1)) * (w - pad * 2);
            const y = pad + (1 - (v - min) / span) * (h - pad * 2);

            return `${x},${y}`;
        })
        .join(' ');

    return (
        <svg
            viewBox={`0 0 ${w} ${h}`}
            className="w-full max-w-30 text-primary"
            aria-hidden
        >
            <polyline
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
                opacity={0.85}
                points={pts}
            />
        </svg>
    );
}

function Panel({
    title,
    children,
    className,
}: {
    title: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-lg border border-border/50 bg-card/30 p-3',
                className,
            )}
        >
            <h3 className="ui-eyebrow">{title}</h3>
            <div className="mt-2">{children}</div>
        </div>
    );
}

function scoreBarWidth(score: number | null): string {
    if (score === null || Number.isNaN(score)) {
        return '0%';
    }

    return `${Math.min(100, Math.max(0, score))}%`;
}

function DefinitionList({
    items,
}: {
    items: { label: string; value: string | null }[];
}) {
    const rows = items.filter((i) => i.value !== null && i.value !== '');

    if (rows.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Nothing here yet — complete steps above.
            </p>
        );
    }

    return (
        <dl className="grid gap-2 sm:grid-cols-2">
            {rows.map(({ label, value }) => (
                <div key={label} className="min-w-0">
                    <dt className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
                        {label}
                    </dt>
                    <dd className="text-sm font-medium text-foreground">
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function ChipList({ items }: { items: string[] }) {
    if (items.length === 0) {
        return <p className="text-sm text-muted-foreground">—</p>;
    }

    return (
        <ul className="flex flex-wrap gap-1">
            {items.map((t) => (
                <li
                    key={t}
                    className="rounded-md border border-border/60 bg-muted/30 px-2 py-0.5 text-sm text-foreground"
                >
                    {t}
                </li>
            ))}
        </ul>
    );
}

function SmartProfileEngine({ profile }: { profile: ProfileOverviewPayload }) {
    const gpa = normalizedGpaReadout(
        profile.academics.gpa_value,
        profile.academics.gpa_scale,
        profile.academics.gpa_type,
    );
    const spikes = spikeThemes(profile.ai.radar, profile.ai.signals);
    const series = trajectorySeries(profile.completeness_score);

    return (
        <section className="overflow-hidden rounded-xl border border-border/60 bg-muted/20">
            <div className="flex items-center justify-between border-b border-border/50 px-3 py-2">
                <p className="ui-eyebrow tracking-[0.2em]">Smart profile engine</p>
                <span
                    className={cn(
                        'rounded border px-1.5 py-0.5 text-xs font-medium',
                        profile.status === 'completed'
                            ? 'border-primary/30 bg-primary/5 text-primary'
                            : 'border-border/80 text-muted-foreground',
                    )}
                >
                    {profile.status === 'completed' ? 'Live' : 'Draft'}
                </span>
            </div>
            <div className="grid grid-cols-1 divide-y divide-border/50 sm:grid-cols-2 sm:divide-x sm:divide-y-0 md:grid-cols-4 md:divide-x">
                <div className="flex flex-col gap-2 p-3">
                    <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                        <PencilSimpleLine
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        Profile intake
                    </div>
                    <p className="text-sm leading-snug text-muted-foreground">
                        Use the steps above to paste, clarify, and tune goals.
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        className="mt-auto h-8 w-fit border-border/70 px-2 text-sm"
                        asChild
                    >
                        <Link href={settingsProfile()}>Account settings</Link>
                    </Button>
                </div>
                <div className="flex flex-col gap-2 p-3">
                    <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                        <Gauge
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        GPA lens
                    </div>
                    {gpa ? (
                        <>
                            <p className="font-mono text-xl font-semibold tabular-nums tracking-tight text-foreground">
                                {gpa.display}
                                <span className="ml-1 text-sm font-sans font-normal text-muted-foreground">
                                    ~4.0
                                </span>
                            </p>
                            <p className="text-sm leading-snug text-muted-foreground">
                                {gpa.detail}
                            </p>
                        </>
                    ) : (
                        <p className="text-sm leading-snug text-muted-foreground">
                            Add GPA + scale in the wizard to unlock a quick
                            readout.
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2 p-3">
                    <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                        <Lightning
                            className="size-3.5 text-amber-500/90"
                            aria-hidden
                        />
                        Spike detector
                    </div>
                    {spikes.length > 0 ? (
                        <ul className="space-y-2">
                            {spikes.map((s) => (
                                <li
                                    key={s.label}
                                    className="flex items-center gap-2"
                                >
                                    <div className="h-6 w-0.5 shrink-0 rounded-full bg-primary/70" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-foreground">
                                            {s.label}
                                        </p>
                                        <div className="mt-0.5 h-0.5 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full rounded-full bg-amber-500/80"
                                                style={{
                                                    width: scoreBarWidth(
                                                        s.strength,
                                                    ),
                                                }}
                                            />
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm leading-snug text-muted-foreground">
                            Complete your dump and refresh insights to surface
                            themes.
                        </p>
                    )}
                </div>
                <div className="flex flex-col gap-2 p-3">
                    <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                        <ChartLine
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                        Trajectory
                    </div>
                    <div className="flex items-end gap-3">
                        <MiniSparkline values={series} />
                        <div className="pb-0.5 text-sm leading-snug text-muted-foreground">
                            Preview from completeness index (placeholder).
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

function buildClarifyDefaults(
    profile: ProfileOverviewPayload,
    fields: ClarificationField[],
): Record<string, string | number | ''> {
    const d: Record<string, string | number | ''> = {
        current_grade: profile.demographics.current_grade ?? '',
        gpa_value: profile.academics.gpa_value ?? '',
        gpa_scale: profile.academics.gpa_scale ?? '',
        gpa_type: profile.academics.gpa_type ?? '',
        curriculum: profile.academics.curriculum ?? '',
        sat_score: profile.academics.sat_score ?? '',
        act_score: profile.academics.act_score ?? '',
        test_optional_status: profile.academics.test_optional_status ?? '',
        citizenship_residency: profile.academics.citizenship_residency ?? '',
        location_country: profile.academics.location_country ?? '',
        location_region: profile.academics.location_region ?? '',
    };
    for (const f of fields) {
        if (d[f.key] === undefined || d[f.key] === '') {
            d[f.key] = '';
        }
    }

    return d;
}

function StepWizard({
    completion,
    clarification_fields,
    mentor_preferences,
    resume_pdf_import,
    profile,
    inModal = false,
}: {
    completion: PageProps['completion'];
    clarification_fields: ClarificationField[];
    mentor_preferences: MentorPreferences;
    resume_pdf_import: ResumePdfImportProps;
    profile: ProfileOverviewPayload;
    inModal?: boolean;
}) {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const [active, setActive] = useState(completion.current_step);
    const [finishProfileOpen, setFinishProfileOpen] = useState(false);
    const [step1DumpEmptyError, setStep1DumpEmptyError] = useState<string | null>(
        null,
    );
    const [clarifyBlurErrors, setClarifyBlurErrors] = useState<Record<string, string>>(
        {},
    );
    const [pdfParseUi, setPdfParseUi] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >('idle');
    const resumeFileInputRef = useRef<HTMLInputElement>(null);
    const profileDumpTextareaRef = useRef<HTMLTextAreaElement>(null);

    const clarifyDefaults = useMemo(
        () => buildClarifyDefaults(profile, clarification_fields),
        [profile, clarification_fields],
    );
    const dumpForm = useForm({ profile_dump_text: profile.profile_dump_text ?? '' });
    const clarifyForm = useForm(clarifyDefaults);
    const goalsForm = useForm({
        dream_schools: profile.targets.dream_schools.length
            ? profile.targets.dream_schools
            : [''],
        intended_majors: profile.targets.intended_majors.length
            ? profile.targets.intended_majors
            : [''],
        region_preferences: profile.targets.region_preferences.length
            ? profile.targets.region_preferences
            : [],
        campus_size_pref: profile.targets.campus_size_pref ?? '',
        financial_aid_need_percent: profile.targets.financial_aid_need_percent ?? 50,
    });
    const reviewForm = useForm({
        student_summary: profile.student_summary ?? '',
        spike_summary: profile.spike_summary ?? '',
    });
    const mentorForm = useForm({
        mentor_tone: mentor_preferences.mentor_tone,
        weekly_focus_nudges: mentor_preferences.weekly_focus_nudges,
        parent_access: mentor_preferences.parent_access,
        mood_checkins: mentor_preferences.mood_checkins,
    });
    const insightsForm = useForm({});

    const tonePreview = useMemo(
        () => TONE_PREVIEW[mentorForm.data.mentor_tone] ?? TONE_PREVIEW.balanced,
        [mentorForm.data.mentor_tone],
    );

    const topCompletenessGap = profile.completeness_callouts[0] ?? null;

    useEffect(() => {
        if (sessionStorage.getItem('profile_resume_parse_ok') === '1') {
            sessionStorage.removeItem('profile_resume_parse_ok');
            setPdfParseUi('success');
        }
    }, []);

    useEffect(() => {
        if (errors?.resume) {
            setPdfParseUi('error');
        }
    }, [errors?.resume]);

    const submitDump = (e: React.FormEvent) => {
        e.preventDefault();
        const noText = dumpForm.data.profile_dump_text.trim() === '';
        const hasFile =
            (resumeFileInputRef.current?.files?.length ?? 0) > 0 &&
            (resumeFileInputRef.current?.files?.[0]?.size ?? 0) > 0;
        if (noText && !hasFile) {
            setStep1DumpEmptyError(
                'Paste something or upload a resume to continue.',
            );
            return;
        }
        setStep1DumpEmptyError(null);
        dumpForm.post('/profile-completion/dump', {
            preserveScroll: true,
            onSuccess: () => {
                toastSaved();
            },
            onError: (errs) => {
                toastServerErrors(errs as Record<string, unknown>);
            },
        });
    };

    const submitClarify = (e: React.FormEvent) => {
        e.preventDefault();
        clarifyForm.post('/profile-completion/clarify', {
            preserveScroll: true,
            onSuccess: () => {
                setClarifyBlurErrors({});
                toastSaved();
            },
            onError: (errs) => {
                toastServerErrors(errs as Record<string, unknown>);
            },
        });
    };

    const submitGoals = (e: React.FormEvent) => {
        e.preventDefault();
        goalsForm.post('/profile-completion/goals', {
            preserveScroll: true,
            onSuccess: () => toastSaved(),
            onError: (errs) => toastServerErrors(errs as Record<string, unknown>),
        });
    };

    const submitReview = (e: React.FormEvent) => {
        e.preventDefault();
        reviewForm.post('/profile-completion/review', {
            preserveScroll: true,
            onSuccess: () => toastSaved(),
            onError: (errs) => toastServerErrors(errs as Record<string, unknown>),
        });
    };

    const submitMentor = (e: React.FormEvent) => {
        e.preventDefault();
        mentorForm.post('/profile-completion/mentor', {
            preserveScroll: true,
            onSuccess: () => toastSaved(),
            onError: (errs) => toastServerErrors(errs as Record<string, unknown>),
        });
    };

    const submitResume = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const fd = new FormData(e.currentTarget);
        const file = fd.get('resume');
        if (!(file instanceof File) || file.size === 0) {
            return;
        }
        setPdfParseUi('loading');
        router.post('/profile-completion/resume', fd, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                sessionStorage.setItem('profile_resume_parse_ok', '1');
                toastSaved();
                setPdfParseUi('success');
            },
            onError: (errs) => {
                setPdfParseUi('error');
                toastServerErrors(errs as Record<string, unknown>);
            },
        });
    };

    const refreshInsights = () => {
        insightsForm.post('/profile-completion/refresh-insights', {
            preserveScroll: true,
            onSuccess: () => toastSaved(),
            onError: (errs) => toastServerErrors(errs as Record<string, unknown>),
        });
    };

    const handleClarifyBlur = (key: string) => {
        if (!REQUIRED_CLARIFY_KEYS.has(key)) {
            return;
        }
        if (!clarification_fields.some((f) => f.key === key)) {
            return;
        }
        const val = String(
            (clarifyForm.data as Record<string, unknown>)[key] ?? '',
        ).trim();
        if (val === '') {
            setClarifyBlurErrors((prev) => ({
                ...prev,
                [key]: 'This field is required.',
            }));
        } else {
            setClarifyBlurErrors((prev) => {
                const next = { ...prev };
                delete next[key];
                return next;
            });
        }
    };

    const clearClarifyBlur = (key: string) => {
        setClarifyBlurErrors((prev) => {
            if (!(key in prev)) return prev;
            const next = { ...prev };
            delete next[key];
            return next;
        });
    };

    const shellClass = cn(
        'overflow-hidden',
        inModal
            ? 'rounded-lg bg-transparent'
            : 'mb-6 rounded-xl border border-border/60 bg-card/20',
    );

    return (
        <>
        <section className={shellClass}>
            {!inModal && (
                <div className="border-b border-border/50 px-3 py-2">
                    <p className="ui-eyebrow">Complete your profile</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Five quick steps — paste, clarify gaps, set goals, review,
                        then mentor tone.
                    </p>
                </div>
            )}
            <div
                className={cn(
                    'flex flex-wrap gap-1 border-b border-border/40 py-2',
                    inModal ? 'px-1' : 'px-2',
                )}
            >
                {STEPS.map((s) => (
                    <button
                        key={s.n}
                        type="button"
                        onClick={() => setActive(s.n)}
                        className={cn(
                            'rounded-md px-2 py-1 text-xs font-medium transition-colors',
                            active === s.n
                                ? 'bg-primary/15 text-primary'
                                : 'text-muted-foreground hover:bg-muted/60',
                        )}
                    >
                        {s.n}. {s.label}
                    </button>
                ))}
            </div>

            <div className={cn(inModal ? 'px-1 py-3 sm:px-3' : 'p-3')}>
                {active === 1 && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Paste or upload a resume PDF — we auto-fill academics,
                            goals, and activities with AI so you type less.
                        </p>
                        <form className="space-y-3" onSubmit={submitDump}>
                            <Label htmlFor="profile_dump_text">Brain dump</Label>
                            <textarea
                                ref={profileDumpTextareaRef}
                                id="profile_dump_text"
                                name="profile_dump_text"
                                value={dumpForm.data.profile_dump_text}
                                onChange={(e) => {
                                    setStep1DumpEmptyError(null);
                                    dumpForm.setData(
                                        'profile_dump_text',
                                        e.target.value,
                                    );
                                }}
                                rows={10}
                                placeholder="Paste anything — your GPA, test scores, activities, awards, goals, background, anything. The more you give, the better. Even rough notes work."
                                className="min-h-0 w-full resize-y rounded-md border border-border/70 bg-background px-2 py-2 text-sm text-foreground outline-none placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring"
                            />
                            {(dumpForm.errors.profile_dump_text ||
                                step1DumpEmptyError) && (
                                <p className="text-sm text-destructive">
                                    {dumpForm.errors.profile_dump_text ??
                                        step1DumpEmptyError}
                                </p>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={dumpForm.processing}
                                >
                                    {dumpForm.processing
                                        ? 'Saving…'
                                        : 'Save & extract'}
                                </Button>
                            </div>
                        </form>
                        <form
                            className="flex flex-wrap items-end gap-2 border-t border-border/40 pt-3"
                            onSubmit={submitResume}
                            encType="multipart/form-data"
                        >
                            <div className="grid min-w-0 flex-1 gap-2 rounded-lg border border-border/50 bg-muted/20 p-3">
                                <Label htmlFor="resume">Upload resume (PDF)</Label>
                                {!resume_pdf_import.unlimited && (
                                    <p className="text-xs text-muted-foreground">
                                        {resume_pdf_import.remaining === 0
                                            ? 'No PDF imports left on your pass.'
                                            : `${resume_pdf_import.remaining} PDF import${resume_pdf_import.remaining === 1 ? '' : 's'} left (Pro: unlimited).`}
                                    </p>
                                )}
                                {resume_pdf_import.unlimited ? (
                                    <p className="text-xs text-muted-foreground">
                                        Pro — unlimited PDF imports.
                                    </p>
                                ) : null}
                                <Input
                                    ref={resumeFileInputRef}
                                    id="resume"
                                    name="resume"
                                    type="file"
                                    accept="application/pdf"
                                    disabled={
                                        !resume_pdf_import.unlimited &&
                                        resume_pdf_import.remaining === 0
                                    }
                                    className="max-w-xs"
                                    onChange={() => {
                                        setPdfParseUi('idle');
                                    }}
                                />
                                {pdfParseUi === 'loading' && (
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Spinner className="size-4" />
                                        Parsing resume…
                                    </div>
                                )}
                                {pdfParseUi === 'success' && (
                                    <p className="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                        Resume parsed — review below
                                    </p>
                                )}
                                {pdfParseUi === 'error' && (
                                    <p className="text-sm text-destructive">
                                        Couldn&apos;t parse this PDF. Try pasting your
                                        info instead.{' '}
                                        <button
                                            type="button"
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                            onClick={() =>
                                                profileDumpTextareaRef.current?.focus()
                                            }
                                        >
                                            Focus brain dump
                                        </button>
                                    </p>
                                )}
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={
                                    (!resume_pdf_import.unlimited &&
                                        resume_pdf_import.remaining === 0) ||
                                    pdfParseUi === 'loading'
                                }
                            >
                                Parse PDF
                            </Button>
                        </form>
                        {errors?.resume ? (
                            <p className="text-sm text-destructive">{errors.resume}</p>
                        ) : null}
                        {!resume_pdf_import.unlimited &&
                        resume_pdf_import.remaining === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                <Link
                                    href="/settings/billing"
                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    Upgrade to Pro
                                </Link>{' '}
                                for unlimited resume PDF imports, or paste text above.
                            </p>
                        ) : null}
                        {profile.resume_pdf_unreadable ? (
                            <p className="text-sm text-amber-800 dark:text-amber-200">
                                Last PDF had no text layer — add details manually in
                                step 2.
                            </p>
                        ) : null}
                    </div>
                )}

                {active === 2 && (
                    <form className="space-y-3" onSubmit={submitClarify}>
                        <p className="text-sm text-muted-foreground">
                            Only the gaps we need — up to six fields.
                        </p>
                        {clarification_fields.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing to clarify right now. Continue to goals.
                            </p>
                        ) : (
                            clarification_fields.map((f) => {
                                const fieldErr =
                                    (clarifyForm.errors as Record<string, string | undefined>)[
                                        f.key
                                    ] ?? clarifyBlurErrors[f.key];
                                return (
                                    <div key={f.key} className="grid gap-1">
                                        <Label htmlFor={f.key}>{f.label}</Label>
                                        {f.type === 'select' && f.options ? (
                                            <Select
                                                value={
                                                    String(
                                                        (
                                                            clarifyForm.data as Record<
                                                                string,
                                                                unknown
                                                            >
                                                        )[f.key] ?? '',
                                                    )
                                                }
                                                onValueChange={(v) => {
                                                    clarifyForm.setData(
                                                        f.key as keyof typeof clarifyForm.data,
                                                        v,
                                                    );
                                                    clearClarifyBlur(f.key);
                                                }}
                                                onOpenChange={(open) => {
                                                    if (!open) {
                                                        handleClarifyBlur(f.key);
                                                    }
                                                }}
                                            >
                                                <SelectTrigger id={f.key}>
                                                    <SelectValue placeholder="Select…" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {f.options.map((o) => (
                                                        <SelectItem key={o} value={o}>
                                                            {o}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        ) : (
                                            <Input
                                                id={f.key}
                                                name={f.key}
                                                value={
                                                    String(
                                                        (
                                                            clarifyForm.data as Record<
                                                                string,
                                                                unknown
                                                            >
                                                        )[f.key] ?? '',
                                                    )
                                                }
                                                onChange={(e) => {
                                                    clarifyForm.setData(
                                                        f.key as keyof typeof clarifyForm.data,
                                                        e.target.value,
                                                    );
                                                    if (
                                                        REQUIRED_CLARIFY_KEYS.has(f.key) &&
                                                        e.target.value.trim() !== ''
                                                    ) {
                                                        clearClarifyBlur(f.key);
                                                    }
                                                }}
                                                onBlur={() => handleClarifyBlur(f.key)}
                                            />
                                        )}
                                        {fieldErr ? (
                                            <p className="text-sm text-destructive">
                                                {fieldErr}
                                            </p>
                                        ) : null}
                                    </div>
                                );
                            })
                        )}
                        <Button type="submit" size="sm" disabled={clarifyForm.processing}>
                            Save clarification
                        </Button>
                    </form>
                )}

                {active === 3 && (
                    <form className="space-y-4" onSubmit={submitGoals}>
                        <div className="grid gap-2">
                            <Label>Dream schools (up to 5)</Label>
                            {goalsForm.data.dream_schools.map((school, i) => (
                                <Input
                                    key={`d-${i}`}
                                    value={school}
                                    onChange={(e) => {
                                        const next = [...goalsForm.data.dream_schools];
                                        next[i] = e.target.value;
                                        goalsForm.setData('dream_schools', next);
                                    }}
                                    placeholder="School name"
                                />
                            ))}
                            {goalsForm.data.dream_schools.length < 5 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="w-fit"
                                    onClick={() =>
                                        goalsForm.setData('dream_schools', [
                                            ...goalsForm.data.dream_schools,
                                            '',
                                        ])
                                    }
                                >
                                    Add school
                                </Button>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label>Intended majors (up to 3)</Label>
                            {goalsForm.data.intended_majors.map((m, i) => (
                                <Input
                                    key={`m-${i}`}
                                    value={m}
                                    onChange={(e) => {
                                        const next = [...goalsForm.data.intended_majors];
                                        next[i] = e.target.value;
                                        goalsForm.setData('intended_majors', next);
                                    }}
                                />
                            ))}
                        </div>
                        <div className="grid gap-2">
                            <Label>Regions</Label>
                            <div className="flex flex-wrap gap-2">
                                {REGION_OPTIONS.map((r) => (
                                    <label
                                        key={r}
                                        className="flex items-center gap-1.5 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={goalsForm.data.region_preferences.includes(
                                                r,
                                            )}
                                            onChange={() => {
                                                const set = new Set(
                                                    goalsForm.data.region_preferences,
                                                );
                                                if (set.has(r)) {
                                                    set.delete(r);
                                                } else {
                                                    set.add(r);
                                                }
                                                goalsForm.setData(
                                                    'region_preferences',
                                                    [...set],
                                                );
                                            }}
                                        />
                                        {r}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div className="grid gap-1">
                            <Label>Campus size</Label>
                            <Select
                                value={goalsForm.data.campus_size_pref || 'none'}
                                onValueChange={(v) =>
                                    goalsForm.setData(
                                        'campus_size_pref',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="No preference" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">No preference</SelectItem>
                                    <SelectItem value="small">Small (&lt;5k)</SelectItem>
                                    <SelectItem value="medium">Medium (5–15k)</SelectItem>
                                    <SelectItem value="large">Large (&gt;15k)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1">
                            <Label>
                                Financial need: {goalsForm.data.financial_aid_need_percent}%
                            </Label>
                            <input
                                type="range"
                                min={0}
                                max={100}
                                value={goalsForm.data.financial_aid_need_percent}
                                onChange={(e) =>
                                    goalsForm.setData(
                                        'financial_aid_need_percent',
                                        Number(e.target.value),
                                    )
                                }
                                className="w-full max-w-md"
                            />
                        </div>
                        <Button type="submit" size="sm" disabled={goalsForm.processing}>
                            Save goals
                        </Button>
                    </form>
                )}

                {active === 4 && (
                    <form className="space-y-4" onSubmit={submitReview}>
                        {profile.spike_summary || profile.ai.headline ? (
                            <div className="rounded-lg border border-primary/25 bg-primary/5 px-3 py-2 text-sm">
                                <span className="font-semibold text-foreground">
                                    Spike / narrative
                                </span>
                                <p className="mt-1 text-muted-foreground">
                                    {profile.spike_summary ?? profile.ai.headline}
                                </p>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No spike label yet — add one below or refresh insights
                                after a solid dump.
                            </p>
                        )}
                        <div className="grid gap-1">
                            <Label htmlFor="spike_summary">Spike (short)</Label>
                            <Input
                                id="spike_summary"
                                value={reviewForm.data.spike_summary}
                                onChange={(e) =>
                                    reviewForm.setData('spike_summary', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="student_summary">Summary</Label>
                            <textarea
                                id="student_summary"
                                rows={5}
                                value={reviewForm.data.student_summary}
                                onChange={(e) =>
                                    reviewForm.setData(
                                        'student_summary',
                                        e.target.value,
                                    )
                                }
                                className="w-full rounded-md border border-border/70 bg-background px-2 py-2 text-sm"
                            />
                        </div>
                        {profile.completeness_callouts.length > 0 && (
                            <ul className="list-inside list-disc text-sm text-muted-foreground">
                                {profile.completeness_callouts.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" size="sm" disabled={reviewForm.processing}>
                                Save review
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={refreshInsights}
                                disabled={insightsForm.processing}
                            >
                                {insightsForm.processing
                                    ? 'Refreshing…'
                                    : 'Refresh admissions read'}
                            </Button>
                        </div>
                    </form>
                )}

                {active === 5 && (
                    <div className="space-y-4">
                    <form className="space-y-4" onSubmit={submitMentor}>
                        <div className="grid gap-2">
                            <Label>Mentor tone</Label>
                            <Select
                                value={mentorForm.data.mentor_tone}
                                onValueChange={(v) =>
                                    mentorForm.setData('mentor_tone', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="brutally_honest">
                                        Brutally honest
                                    </SelectItem>
                                    <SelectItem value="balanced">Balanced</SelectItem>
                                    <SelectItem value="supportive">Encouraging</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-sm italic text-muted-foreground">
                                Preview: {tonePreview}
                            </p>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={mentorForm.data.weekly_focus_nudges}
                                onChange={(e) =>
                                    mentorForm.setData(
                                        'weekly_focus_nudges',
                                        e.target.checked,
                                    )
                                }
                            />
                            Weekly focus nudges
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={mentorForm.data.parent_access}
                                onChange={(e) =>
                                    mentorForm.setData('parent_access', e.target.checked)
                                }
                            />
                            Parent access (view-only)
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={mentorForm.data.mood_checkins}
                                onChange={(e) =>
                                    mentorForm.setData('mood_checkins', e.target.checked)
                                }
                            />
                            Mood check-ins
                        </label>
                        <Button type="submit" size="sm" disabled={mentorForm.processing}>
                            Save mentor preferences
                        </Button>
                    </form>
                        <Button
                            type="button"
                            size="sm"
                            className="w-full sm:w-auto"
                            onClick={() => setFinishProfileOpen(true)}
                        >
                            Your profile is ready
                        </Button>
                    </div>
                )}
            </div>
        </section>
        <Dialog open={finishProfileOpen} onOpenChange={setFinishProfileOpen}>
            <DialogContent className="z-200 sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Profile complete</DialogTitle>
                </DialogHeader>
                <div className="space-y-3">
                    <p className="text-4xl font-semibold tabular-nums tracking-tight text-foreground">
                        {profile.completeness_score}{' '}
                        <span className="text-xl font-semibold text-muted-foreground">
                            / 100
                        </span>
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Here&apos;s what we&apos;ll focus on first:
                    </p>
                    <p className="text-sm font-medium text-foreground">
                        {topCompletenessGap ?? '—'}
                    </p>
                </div>
                <DialogFooter className="gap-2 sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            setFinishProfileOpen(false);
                            setActive(4);
                        }}
                    >
                        Keep editing
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            setFinishProfileOpen(false);
                            router.visit(dashboard());
                        }}
                    >
                        Go to dashboard
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        </>
    );
}

export default function ProfileOverview({
    has_profile,
    profile,
    completion,
    clarification_fields,
    mentor_preferences,
    resume_pdf_import,
    profile_modes,
}: PageProps) {
    const page = usePage();
    const [wizardOpen, setWizardOpen] = useState(false);

    useEffect(() => {
        if (!has_profile || !profile) {
            return;
        }
        const search = page.url.includes('?')
            ? (page.url.split('?')[1] ?? '')
            : '';
        const params = new URLSearchParams(search);
        if (params.get('wizard') !== '1') {
            return;
        }
        setWizardOpen(true);
        params.delete('wizard');
        const base = page.url.split('?')[0] ?? page.url;
        const next = base + (params.toString() ? `?${params.toString()}` : '');
        router.visit(next, {
            replace: true,
            preserveScroll: true,
            preserveState: true,
        });
    }, [has_profile, profile, page.url]);

    return (
        <>
            <Head title="Profile overview" />
            <Toaster richColors closeButton position="top-center" />

            <div className="page-shell">
                <header className="mb-4 space-y-1">
                    <h1 className="page-title">Profile overview</h1>
                    <p className="page-description max-w-2xl">
                        Structured data, engine readouts, and analyses — this page
                        reflects what you save in profile setup (same knowledge the AI
                        uses in Settings → AI).
                    </p>
                </header>

                {!has_profile || !profile ? (
                    <div className="rounded-xl border border-border/60 p-4">
                        <div className="flex items-start gap-2">
                            <UserCircle
                                className="size-8 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <div className="min-w-0 space-y-2">
                                <p className="text-sm font-medium text-foreground">
                                    No profile record
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Finish onboarding first.
                                </p>
                                <Button asChild size="sm" className="h-8">
                                    <Link href={dashboard()}>Home</Link>
                                </Button>
                            </div>
                        </div>
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex flex-col gap-4 rounded-xl border border-border/60 bg-card/30 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0 flex-1 space-y-2">
                                <p className="ui-eyebrow">Profile setup</p>
                                <p className="text-sm text-muted-foreground">
                                    {isProfileWizardComplete(profile, completion)
                                        ? 'Your profile is in good shape. Open the wizard anytime to edit — updates refresh your AI knowledge base.'
                                        : 'Finish the five-step wizard so chat, essays, and tools use your full context.'}
                                </p>
                                <div className="flex items-baseline gap-2">
                                    <span className="text-3xl font-semibold tabular-nums text-foreground">
                                        {profile.completeness_score}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        / 100
                                    </span>
                                </div>
                                <div className="h-1.5 max-w-xs overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-primary"
                                        style={{
                                            width: `${Math.min(100, Math.max(0, profile.completeness_score))}%`,
                                        }}
                                    />
                                </div>
                            </div>
                            <Button
                                type="button"
                                className="h-10 shrink-0 sm:self-center"
                                onClick={() => setWizardOpen(true)}
                            >
                                {isProfileWizardComplete(profile, completion)
                                    ? 'Edit profile'
                                    : 'Complete your profile'}
                            </Button>
                        </div>

                        <Dialog open={wizardOpen} onOpenChange={setWizardOpen}>
                            <DialogContent
                                showCloseButton
                                className="z-100 flex max-h-[min(92vh,900px)] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl"
                            >
                                <div className="shrink-0 border-b border-border/50 px-4 py-3 sm:px-6 sm:py-4">
                                    <DialogHeader className="gap-1 text-left">
                                        <DialogTitle>Profile setup</DialogTitle>
                                        <DialogDescription>
                                            Five steps — paste, clarify gaps, set goals,
                                            review, then mentor tone. This feeds your AI
                                            knowledge base used in chat and tools.
                                        </DialogDescription>
                                    </DialogHeader>
                                </div>
                                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-1 pb-4 sm:px-3">
                                    <StepWizard
                                        completion={completion}
                                        clarification_fields={clarification_fields}
                                        mentor_preferences={mentor_preferences}
                                        resume_pdf_import={resume_pdf_import}
                                        profile={profile}
                                        inModal
                                    />
                                </div>
                            </DialogContent>
                        </Dialog>

                        <SmartProfileEngine profile={profile} />

                        <div className="grid gap-2 sm:grid-cols-2">
                            <Panel title="AI · context" className="sm:col-span-1">
                                <p className="text-sm text-muted-foreground">
                                    {profile.ai.last_context_at
                                        ? new Date(
                                              profile.ai.last_context_at,
                                          ).toLocaleDateString(undefined, {
                                              month: 'short',
                                              day: 'numeric',
                                          })
                                        : '—'}
                                </p>
                            </Panel>
                            <Panel title="Modes" className="sm:col-span-1">
                                <ul className="text-xs text-muted-foreground">
                                    {Object.entries({
                                        Early: profile_modes.early_student,
                                        International: profile_modes.international,
                                        'High need aid': profile_modes.high_need_aid,
                                        'No test': profile_modes.no_test_score,
                                        'Low completeness': profile_modes.low_completeness,
                                        Spike: profile_modes.spike_detected,
                                    }).map(([k, v]) => (
                                        <li key={k}>
                                            {k}: {v ? 'yes' : 'no'}
                                        </li>
                                    ))}
                                </ul>
                            </Panel>
                        </div>

                        <div className="grid gap-2 lg:grid-cols-2">
                            <Panel title="Demographics">
                                <DefinitionList
                                    items={[
                                        {
                                            label: 'Age band',
                                            value: profile.demographics.age_range_label,
                                        },
                                        {
                                            label: 'Grade',
                                            value: profile.demographics.current_grade,
                                        },
                                        {
                                            label: 'Grad year',
                                            value:
                                                profile.demographics.graduation_year !==
                                                null
                                                    ? String(
                                                          profile.demographics
                                                              .graduation_year,
                                                      )
                                                    : null,
                                        },
                                    ]}
                                />
                            </Panel>
                            <Panel title="Academics">
                                <DefinitionList
                                    items={[
                                        {
                                            label: 'Curriculum',
                                            value: profile.academics.curriculum,
                                        },
                                        {
                                            label: 'GPA',
                                            value:
                                                profile.academics.gpa_value !== null
                                                    ? profile.academics.gpa_scale !==
                                                      null
                                                        ? `${profile.academics.gpa_value} / ${profile.academics.gpa_scale}${profile.academics.gpa_type ? ` (${profile.academics.gpa_type})` : ''}`
                                                        : profile.academics.gpa_value
                                                    : null,
                                        },
                                        {
                                            label: 'SAT',
                                            value:
                                                profile.academics.sat_score !== null
                                                    ? String(profile.academics.sat_score)
                                                    : null,
                                        },
                                        {
                                            label: 'ACT',
                                            value:
                                                profile.academics.act_score !== null
                                                    ? String(profile.academics.act_score)
                                                    : null,
                                        },
                                    ]}
                                />
                            </Panel>
                        </div>

                        <Panel title="Targets">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <p className="mb-1 text-xs uppercase tracking-wider text-muted-foreground">
                                        Majors
                                    </p>
                                    <ChipList items={profile.targets.intended_majors} />
                                </div>
                                <div>
                                    <p className="mb-1 text-xs uppercase tracking-wider text-muted-foreground">
                                        Dream schools
                                    </p>
                                    <ChipList items={profile.targets.dream_schools} />
                                </div>
                                <div>
                                    <p className="mb-1 text-xs uppercase tracking-wider text-muted-foreground">
                                        Regions
                                    </p>
                                    <ChipList
                                        items={profile.targets.region_preferences}
                                    />
                                </div>
                            </div>
                        </Panel>

                        {(profile.ai.headline ||
                            profile.ai.excerpt ||
                            profile.ai.radar.length > 0 ||
                            profile.ai.signals.length > 0 ||
                            profile.ai.takeaways.length > 0) && (
                            <Panel title="Analyses">
                                <div className="space-y-3">
                                    {profile.ai.headline && (
                                        <p className="text-sm font-medium leading-snug text-foreground">
                                            {profile.ai.headline}
                                        </p>
                                    )}
                                    {profile.ai.excerpt && (
                                        <p className="whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">
                                            {profile.ai.excerpt}
                                        </p>
                                    )}
                                    {profile.ai.radar.length > 0 && (
                                        <ul className="space-y-2">
                                            {profile.ai.radar.map((d) => (
                                                <li key={d.key} className="space-y-1">
                                                    <div className="flex justify-between gap-2 text-sm">
                                                        <span className="font-medium text-foreground">
                                                            {d.label}
                                                        </span>
                                                        {d.score !== null && (
                                                            <span className="tabular-nums text-muted-foreground">
                                                                {Math.round(d.score)}
                                                                /100
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="h-0.5 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-primary/70"
                                                            style={{
                                                                width: scoreBarWidth(
                                                                    d.score,
                                                                ),
                                                            }}
                                                        />
                                                    </div>
                                                    {d.verdict && (
                                                        <p className="text-sm text-muted-foreground">
                                                            {d.verdict}
                                                        </p>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {profile.ai.signals.length > 0 && (
                                        <div>
                                            <p className="mb-1 text-xs uppercase tracking-wider text-muted-foreground">
                                                Tokens
                                            </p>
                                            <ChipList items={profile.ai.signals} />
                                        </div>
                                    )}
                                    {profile.ai.takeaways.length > 0 && (
                                        <ul className="list-inside list-disc space-y-0.5 text-sm text-foreground">
                                            {profile.ai.takeaways.map((t) => (
                                                <li key={t}>{t}</li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </Panel>
                        )}

                        {(profile.student_summary ||
                            profile.open_questions.length > 0) && (
                            <Panel title="Narrative">
                                {profile.student_summary && (
                                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                                        {profile.student_summary}
                                    </p>
                                )}
                                {profile.open_questions.length > 0 && (
                                    <ol className="mt-2 list-inside list-decimal space-y-1 text-sm text-muted-foreground">
                                        {profile.open_questions.map((q) => (
                                            <li key={q}>{q}</li>
                                        ))}
                                    </ol>
                                )}
                            </Panel>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

ProfileOverview.layout = {
    breadcrumbs: [
        {
            title: 'Profile',
            href: profileOverview.url(),
        },
    ],
};
