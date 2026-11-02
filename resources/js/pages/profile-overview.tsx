'use client';

import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    BookOpenText,
    CaretDown,
    CaretRight,
    ChartPieSlice,
    ChatCircle,
    ClipboardText,
    DotsThree,
    Exam,
    FileText,
    GearSix,
    GlobeHemisphereWest,
    GraduationCap,
    PencilSimple,
    SealCheck,
    Sparkle,
    Trophy,
    UserCircle,
    Wallet,
    X,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { toast } from 'sonner';
import {
    Banner,
    EmptyState,
    IconTile,
    KeyValueList,
    KeyValueRow,
    Page,
    PageHeader,
    Section,
    SegmentedTabs,
} from '@/components/page/kit';
import {
    CheckboxInput,
    fieldClass,
    SettingRow,
    SwitchInput,
} from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useHydrated } from '@/hooks/use-hydrated';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard, profileOverview } from '@/routes';
import { edit as settingsProfile } from '@/routes/profile';
import type { Auth } from '@/types';

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
    /** Revision this page was rendered from; manual saves send it so a newer change is never overwritten. */
    revision: number;
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
const REQUIRED_CLARIFY_KEYS = new Set([
    'current_grade',
    'gpa_value',
    'gpa_scale',
]);

/** The sessionStorage flag that survives the page reload after a successful resume parse. */
const RESUME_PARSED_FLAG = 'profile_resume_parse_ok';

function firstErrorMessage(
    errors: Record<string, string | string[] | undefined>,
): string | null {
    for (const v of Object.values(errors)) {
        if (v == null) {
            continue;
        }

        if (Array.isArray(v)) {
            return v[0] ?? null;
        }

        if (typeof v === 'string' && v.length > 0) {
            return v;
        }
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
                    You&apos;ve reached your PDF import limit. Upgrade to Pro
                    for unlimited imports.
                </span>
                <Link
                    href="/settings/billing"
                    className="font-medium text-foreground underline underline-offset-4"
                >
                    Upgrade to Pro
                </Link>
            </div>,
            { duration: 10_000 },
        );

        return;
    }

    const msg = firstErrorMessage(
        errors as Record<string, string | string[] | undefined>,
    );

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
): string | null {
    const v =
        gpaValue != null ? Number.parseFloat(gpaValue.replace(',', '.')) : NaN;
    const s =
        gpaScale != null ? Number.parseFloat(gpaScale.replace(',', '.')) : NaN;

    if (!Number.isFinite(v) || !Number.isFinite(s) || s <= 0) {
        return null;
    }

    const onFour = Math.min(4, Math.max(0, (v / s) * 4));

    return (Math.round(onFour * 100) / 100).toFixed(2);
}

function scoreBarWidth(score: number | null): string {
    if (score === null || Number.isNaN(score)) {
        return '0%';
    }

    return `${Math.min(100, Math.max(0, score))}%`;
}

/** "test_optional" → "Test optional". Stored values are short machine words. */
function humanize(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const text = value.replace(/[_-]+/g, ' ').trim();

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function listOrNull(items: string[]): string | null {
    const clean = items.map((i) => i.trim()).filter(Boolean);

    return clean.length > 0 ? clean.join(', ') : null;
}

const CAMPUS_SIZE: Record<string, string> = {
    small: 'Small (under 5k)',
    medium: 'Medium (5–15k)',
    large: 'Large (over 15k)',
};

const MODE_LABELS: Record<string, string> = {
    early_student: 'Early student',
    international: 'International applicant',
    high_need_aid: 'High financial need',
    no_test_score: 'No test scores',
    low_completeness: 'Low completeness',
    spike_detected: 'Spike detected',
};

const textareaClass =
    'w-full resize-y rounded-xl border border-input bg-card px-3 py-2.5 text-[14px] leading-relaxed text-foreground shadow-card outline-none placeholder:text-muted-foreground/70 focus-visible:border-foreground/30 focus-visible:ring-3 focus-visible:ring-ring/25 dark:bg-input/30';

const linkClass =
    'font-medium text-foreground underline decoration-foreground/25 underline-offset-4 hover:decoration-foreground';

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

function readResumeParsedFlag(): boolean {
    try {
        return (
            typeof window !== 'undefined' &&
            window.sessionStorage.getItem(RESUME_PARSED_FLAG) === '1'
        );
    } catch {
        return false;
    }
}

/** A labelled field inside the wizard. */
function WizardField({
    label,
    htmlFor,
    children,
    error,
}: {
    label: ReactNode;
    htmlFor?: string;
    children: ReactNode;
    error?: string | null;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor} className="text-[13.5px]">
                {label}
            </Label>
            {children}
            {error ? (
                <p className="text-[13px] text-destructive">{error}</p>
            ) : null}
        </div>
    );
}

function StepWizard({
    completion,
    clarification_fields,
    mentor_preferences,
    resume_pdf_import,
    profile,
    initialStep,
}: {
    completion: PageProps['completion'];
    clarification_fields: ClarificationField[];
    mentor_preferences: MentorPreferences;
    resume_pdf_import: ResumePdfImportProps;
    profile: ProfileOverviewPayload;
    initialStep?: number;
}) {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const [active, setActive] = useState(
        initialStep ?? completion.current_step,
    );
    const [finishProfileOpen, setFinishProfileOpen] = useState(false);
    const [step1DumpEmptyError, setStep1DumpEmptyError] = useState<
        string | null
    >(null);
    const [clarifyBlurErrors, setClarifyBlurErrors] = useState<
        Record<string, string>
    >({});
    // The wizard only mounts in the browser (inside the dialog), so the reload flag can be read up front.
    const [pdfParseUi, setPdfParseUi] = useState<
        'idle' | 'loading' | 'success' | 'error'
    >(() =>
        errors?.resume ? 'error' : readResumeParsedFlag() ? 'success' : 'idle',
    );
    // A new resume error from the server shows the error state (it was an effect before).
    const [seenResumeError, setSeenResumeError] = useState(errors?.resume);

    if (errors?.resume !== seenResumeError) {
        setSeenResumeError(errors?.resume);

        if (errors?.resume) {
            setPdfParseUi('error');
        }
    }

    const resumeFileInputRef = useRef<HTMLInputElement>(null);
    const profileDumpTextareaRef = useRef<HTMLTextAreaElement>(null);
    const stepsRef = useRef<HTMLDivElement>(null);

    // On a phone the step row scrolls sideways: keep the current step in view.
    useEffect(() => {
        const box = stepsRef.current;
        const tab = box?.querySelector<HTMLElement>('[aria-selected="true"]');

        if (!box || !tab || box.scrollWidth <= box.clientWidth) {
            return;
        }

        const offset =
            tab.getBoundingClientRect().left - box.getBoundingClientRect().left;
        box.scrollLeft += offset - (box.clientWidth - tab.offsetWidth) / 2;
    }, [active]);

    const clarifyDefaults = useMemo(
        () => buildClarifyDefaults(profile, clarification_fields),
        [profile, clarification_fields],
    );
    const dumpForm = useForm({
        profile_dump_text: profile.profile_dump_text ?? '',
    });
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
        financial_aid_need_percent:
            profile.targets.financial_aid_need_percent ?? 50,
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
        () =>
            TONE_PREVIEW[mentorForm.data.mentor_tone] ?? TONE_PREVIEW.balanced,
        [mentorForm.data.mentor_tone],
    );

    const topCompletenessGap = profile.completeness_callouts[0] ?? null;

    // The success flag only needs to survive one reload.
    useEffect(() => {
        try {
            window.sessionStorage.removeItem(RESUME_PARSED_FLAG);
        } catch {
            // Storage can be unavailable (private mode); nothing to clean up then.
        }
    }, []);

    const submitDump = (e: FormEvent) => {
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

    const withRevision = <T extends object>(data: T) => ({
        ...data,
        expected_revision: profile.revision,
    });

    const submitClarify = (e: FormEvent) => {
        e.preventDefault();
        clarifyForm.transform(withRevision);
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

    const submitGoals = (e: FormEvent) => {
        e.preventDefault();
        goalsForm.transform(withRevision);
        goalsForm.post('/profile-completion/goals', {
            preserveScroll: true,
            onSuccess: () => toastSaved(),
            onError: (errs) =>
                toastServerErrors(errs as Record<string, unknown>),
        });
    };

    const submitReview = (e: FormEvent) => {
        e.preventDefault();
        reviewForm.transform(withRevision);
        reviewForm.post('/profile-completion/review', {
            preserveScroll: true,
            onSuccess: () => toastSaved(),
            onError: (errs) =>
                toastServerErrors(errs as Record<string, unknown>),
        });
    };

    const submitMentor = (e: FormEvent) => {
        e.preventDefault();
        mentorForm.post('/profile-completion/mentor', {
            preserveScroll: true,
            onSuccess: () => toastSaved(),
            onError: (errs) =>
                toastServerErrors(errs as Record<string, unknown>),
        });
    };

    const submitResume = (e: FormEvent<HTMLFormElement>) => {
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
                window.sessionStorage.setItem(RESUME_PARSED_FLAG, '1');
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
            onError: (errs) =>
                toastServerErrors(errs as Record<string, unknown>),
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
            if (!(key in prev)) {
                return prev;
            }

            const next = { ...prev };
            delete next[key];

            return next;
        });
    };

    const importsLeft = resume_pdf_import.remaining;
    const pdfLocked = !resume_pdf_import.unlimited && importsLeft === 0;

    return (
        <>
            <div
                ref={stepsRef}
                className="-mx-1 scrollbar-none overflow-x-auto px-1 pb-1"
            >
                <SegmentedTabs
                    label="Wizard steps"
                    value={String(active)}
                    onChange={(v) => setActive(Number(v))}
                    items={STEPS.map((s) => ({
                        value: String(s.n),
                        label: `${s.n}. ${s.label}`,
                    }))}
                />
            </div>

            <div className="pt-5">
                {active === 1 && (
                    <div className="space-y-5">
                        <p className="text-[14px] leading-relaxed text-muted-foreground">
                            Paste anything or upload a resume PDF — AI fills in
                            academics, goals and activities so you type less.
                        </p>
                        <form className="space-y-3" onSubmit={submitDump}>
                            <Label
                                htmlFor="profile_dump_text"
                                className="text-[13.5px]"
                            >
                                Brain dump
                            </Label>
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
                                rows={8}
                                placeholder="Paste anything — your GPA, test scores, activities, awards, goals, background, anything. The more you give, the better. Even rough notes work."
                                className={textareaClass}
                            />
                            {(dumpForm.errors.profile_dump_text ||
                                step1DumpEmptyError) && (
                                <p className="text-[13px] text-destructive">
                                    {dumpForm.errors.profile_dump_text ??
                                        step1DumpEmptyError}
                                </p>
                            )}
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    className="rounded-full px-4"
                                    disabled={dumpForm.processing}
                                >
                                    {dumpForm.processing
                                        ? 'Saving…'
                                        : 'Save & extract'}
                                </Button>
                            </div>
                        </form>

                        <form
                            className="rounded-2xl border border-dashed border-border p-4"
                            onSubmit={submitResume}
                            encType="multipart/form-data"
                        >
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid min-w-0 flex-1 basis-56 gap-1.5">
                                    <Label
                                        htmlFor="resume"
                                        className="text-[13.5px]"
                                    >
                                        Upload resume (PDF)
                                    </Label>
                                    <p className="text-[12.5px] text-muted-foreground">
                                        {resume_pdf_import.unlimited
                                            ? 'Pro — unlimited PDF imports.'
                                            : importsLeft === 0
                                              ? 'No PDF imports left on your pass.'
                                              : `${importsLeft} PDF import${importsLeft === 1 ? '' : 's'} left (Pro: unlimited).`}
                                    </p>
                                    <Input
                                        ref={resumeFileInputRef}
                                        id="resume"
                                        name="resume"
                                        type="file"
                                        accept="application/pdf"
                                        disabled={pdfLocked}
                                        className="mt-1 h-10 max-w-xs rounded-xl"
                                        onChange={() => {
                                            setPdfParseUi('idle');
                                        }}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="rounded-full px-4"
                                    disabled={
                                        pdfLocked || pdfParseUi === 'loading'
                                    }
                                >
                                    Parse PDF
                                </Button>
                            </div>
                            {pdfParseUi === 'loading' && (
                                <div className="mt-3 flex items-center gap-2 text-[13px] text-muted-foreground">
                                    <Spinner className="size-4" />
                                    Parsing resume…
                                </div>
                            )}
                            {pdfParseUi === 'success' && (
                                <p className="mt-3 text-[13px] font-medium text-emerald-700 dark:text-emerald-400">
                                    Resume parsed — review below
                                </p>
                            )}
                            {pdfParseUi === 'error' && (
                                <p className="mt-3 text-[13px] text-destructive">
                                    Couldn&apos;t parse this PDF. Try pasting
                                    your info instead.{' '}
                                    <button
                                        type="button"
                                        className={linkClass}
                                        onClick={() =>
                                            profileDumpTextareaRef.current?.focus()
                                        }
                                    >
                                        Focus brain dump
                                    </button>
                                </p>
                            )}
                        </form>
                        {errors?.resume ? (
                            <p className="text-[13px] text-destructive">
                                {errors.resume}
                            </p>
                        ) : null}
                        {pdfLocked ? (
                            <p className="text-[13px] text-muted-foreground">
                                <Link
                                    href="/settings/billing"
                                    className={linkClass}
                                >
                                    Upgrade to Pro
                                </Link>{' '}
                                for unlimited resume PDF imports, or paste text
                                above.
                            </p>
                        ) : null}
                        {profile.resume_pdf_unreadable ? (
                            <p className="text-[13px] text-amber-800 dark:text-amber-200">
                                Last PDF had no text layer — add details
                                manually in step 2.
                            </p>
                        ) : null}
                    </div>
                )}

                {active === 2 && (
                    <form className="space-y-4" onSubmit={submitClarify}>
                        <p className="text-[14px] text-muted-foreground">
                            Only the gaps we need — up to six fields.
                        </p>
                        {clarification_fields.length === 0 ? (
                            <p className="text-[14px] text-muted-foreground">
                                Nothing to clarify right now. Continue to goals.
                            </p>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {clarification_fields.map((f) => {
                                    const fieldErr =
                                        (
                                            clarifyForm.errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        )[f.key] ?? clarifyBlurErrors[f.key];

                                    return (
                                        <WizardField
                                            key={f.key}
                                            label={f.label}
                                            htmlFor={f.key}
                                            error={fieldErr}
                                        >
                                            {f.type === 'select' &&
                                            f.options ? (
                                                <Select
                                                    value={String(
                                                        (
                                                            clarifyForm.data as Record<
                                                                string,
                                                                unknown
                                                            >
                                                        )[f.key] ?? '',
                                                    )}
                                                    onValueChange={(v) => {
                                                        clarifyForm.setData(
                                                            f.key as keyof typeof clarifyForm.data,
                                                            v,
                                                        );
                                                        clearClarifyBlur(f.key);
                                                    }}
                                                    onOpenChange={(open) => {
                                                        if (!open) {
                                                            handleClarifyBlur(
                                                                f.key,
                                                            );
                                                        }
                                                    }}
                                                >
                                                    <SelectTrigger
                                                        id={f.key}
                                                        className={cn(
                                                            fieldClass,
                                                            'w-full',
                                                        )}
                                                    >
                                                        <SelectValue placeholder="Select…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {f.options.map((o) => (
                                                            <SelectItem
                                                                key={o}
                                                                value={o}
                                                            >
                                                                {o}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <Input
                                                    id={f.key}
                                                    name={f.key}
                                                    className={fieldClass}
                                                    value={String(
                                                        (
                                                            clarifyForm.data as Record<
                                                                string,
                                                                unknown
                                                            >
                                                        )[f.key] ?? '',
                                                    )}
                                                    onChange={(e) => {
                                                        clarifyForm.setData(
                                                            f.key as keyof typeof clarifyForm.data,
                                                            e.target.value,
                                                        );

                                                        if (
                                                            REQUIRED_CLARIFY_KEYS.has(
                                                                f.key,
                                                            ) &&
                                                            e.target.value.trim() !==
                                                                ''
                                                        ) {
                                                            clearClarifyBlur(
                                                                f.key,
                                                            );
                                                        }
                                                    }}
                                                    onBlur={() =>
                                                        handleClarifyBlur(f.key)
                                                    }
                                                />
                                            )}
                                        </WizardField>
                                    );
                                })}
                            </div>
                        )}
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                className="rounded-full px-4"
                                disabled={clarifyForm.processing}
                            >
                                Save clarification
                            </Button>
                        </div>
                    </form>
                )}

                {active === 3 && (
                    <form className="space-y-5" onSubmit={submitGoals}>
                        <WizardField label="Dream schools (up to 5)">
                            <div className="grid gap-2">
                                {goalsForm.data.dream_schools.map(
                                    (school, i) => (
                                        <Input
                                            key={`d-${i}`}
                                            className={fieldClass}
                                            aria-label={`Dream school ${i + 1}`}
                                            value={school}
                                            onChange={(e) => {
                                                const next = [
                                                    ...goalsForm.data
                                                        .dream_schools,
                                                ];
                                                next[i] = e.target.value;
                                                goalsForm.setData(
                                                    'dream_schools',
                                                    next,
                                                );
                                            }}
                                            placeholder="School name"
                                        />
                                    ),
                                )}
                            </div>
                            {goalsForm.data.dream_schools.length < 5 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="mt-1 w-fit rounded-full px-3"
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
                        </WizardField>
                        <WizardField label="Intended majors (up to 3)">
                            <div className="grid gap-2">
                                {goalsForm.data.intended_majors.map((m, i) => (
                                    <Input
                                        key={`m-${i}`}
                                        className={fieldClass}
                                        aria-label={`Intended major ${i + 1}`}
                                        value={m}
                                        onChange={(e) => {
                                            const next = [
                                                ...goalsForm.data
                                                    .intended_majors,
                                            ];
                                            next[i] = e.target.value;
                                            goalsForm.setData(
                                                'intended_majors',
                                                next,
                                            );
                                        }}
                                    />
                                ))}
                            </div>
                        </WizardField>
                        <fieldset className="grid gap-2">
                            <legend className="mb-1.5 text-[13.5px] font-medium">
                                Regions
                            </legend>
                            <div className="flex flex-wrap gap-x-5 gap-y-2.5">
                                {REGION_OPTIONS.map((r) => (
                                    <label
                                        key={r}
                                        className="flex cursor-pointer items-center gap-2 text-[14px]"
                                    >
                                        <CheckboxInput
                                            checked={goalsForm.data.region_preferences.includes(
                                                r,
                                            )}
                                            onChange={() => {
                                                const set = new Set(
                                                    goalsForm.data
                                                        .region_preferences,
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
                        </fieldset>
                        <div className="grid gap-5 sm:grid-cols-2">
                            <WizardField
                                label="Campus size"
                                htmlFor="wizard-campus-size"
                            >
                                <Select
                                    value={
                                        goalsForm.data.campus_size_pref ||
                                        'none'
                                    }
                                    onValueChange={(v) =>
                                        goalsForm.setData(
                                            'campus_size_pref',
                                            v === 'none' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="wizard-campus-size"
                                        className={cn(fieldClass, 'w-full')}
                                    >
                                        <SelectValue placeholder="No preference" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            No preference
                                        </SelectItem>
                                        <SelectItem value="small">
                                            Small (&lt;5k)
                                        </SelectItem>
                                        <SelectItem value="medium">
                                            Medium (5–15k)
                                        </SelectItem>
                                        <SelectItem value="large">
                                            Large (&gt;15k)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </WizardField>
                            <WizardField
                                label={
                                    <>
                                        Financial need:{' '}
                                        <span className="nums">
                                            {
                                                goalsForm.data
                                                    .financial_aid_need_percent
                                            }
                                            %
                                        </span>
                                    </>
                                }
                                htmlFor="wizard-aid-need"
                            >
                                <input
                                    id="wizard-aid-need"
                                    type="range"
                                    min={0}
                                    max={100}
                                    value={
                                        goalsForm.data
                                            .financial_aid_need_percent
                                    }
                                    onChange={(e) =>
                                        goalsForm.setData(
                                            'financial_aid_need_percent',
                                            Number(e.target.value),
                                        )
                                    }
                                    className="mt-3 w-full accent-foreground"
                                />
                            </WizardField>
                        </div>
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                className="rounded-full px-4"
                                disabled={goalsForm.processing}
                            >
                                Save goals
                            </Button>
                        </div>
                    </form>
                )}

                {active === 4 && (
                    <form className="space-y-4" onSubmit={submitReview}>
                        {profile.spike_summary || profile.ai.headline ? (
                            <div className="rounded-xl bg-muted px-4 py-3 text-[14px]">
                                <span className="font-medium text-foreground">
                                    Spike / narrative
                                </span>
                                <p className="mt-1 text-muted-foreground">
                                    {profile.spike_summary ??
                                        profile.ai.headline}
                                </p>
                            </div>
                        ) : (
                            <p className="text-[14px] text-muted-foreground">
                                No spike label yet — add one below or refresh
                                insights after a solid dump.
                            </p>
                        )}
                        <WizardField
                            label="Spike (short)"
                            htmlFor="spike_summary"
                        >
                            <Input
                                id="spike_summary"
                                className={fieldClass}
                                value={reviewForm.data.spike_summary}
                                onChange={(e) =>
                                    reviewForm.setData(
                                        'spike_summary',
                                        e.target.value,
                                    )
                                }
                            />
                        </WizardField>
                        <WizardField label="Summary" htmlFor="student_summary">
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
                                className={textareaClass}
                            />
                        </WizardField>
                        {profile.completeness_callouts.length > 0 && (
                            <ul className="space-y-1 text-[13.5px] text-muted-foreground">
                                {profile.completeness_callouts.map((c) => (
                                    <li key={c} className="flex gap-2">
                                        <span
                                            aria-hidden
                                            className="mt-[7px] size-1 shrink-0 rounded-full bg-muted-foreground/60"
                                        />
                                        {c}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="rounded-full px-4"
                                onClick={refreshInsights}
                                disabled={insightsForm.processing}
                            >
                                {insightsForm.processing
                                    ? 'Refreshing…'
                                    : 'Refresh admissions read'}
                            </Button>
                            <Button
                                type="submit"
                                className="rounded-full px-4"
                                disabled={reviewForm.processing}
                            >
                                Save review
                            </Button>
                        </div>
                    </form>
                )}

                {active === 5 && (
                    <div className="space-y-4">
                        <form className="space-y-4" onSubmit={submitMentor}>
                            <WizardField
                                label="Mentor tone"
                                htmlFor="wizard-mentor-tone"
                            >
                                <Select
                                    value={mentorForm.data.mentor_tone}
                                    onValueChange={(v) =>
                                        mentorForm.setData('mentor_tone', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="wizard-mentor-tone"
                                        className={cn(fieldClass, 'w-full')}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="brutally_honest">
                                            Brutally honest
                                        </SelectItem>
                                        <SelectItem value="balanced">
                                            Balanced
                                        </SelectItem>
                                        <SelectItem value="supportive">
                                            Encouraging
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="rounded-xl bg-muted px-3 py-2 text-[13px] text-muted-foreground italic">
                                    Preview: {tonePreview}
                                </p>
                            </WizardField>
                            <div className="divide-y divide-border border-y border-border">
                                <SettingRow
                                    inline
                                    htmlFor="wizard-weekly-nudges"
                                    title="Weekly focus nudges"
                                >
                                    <SwitchInput
                                        id="wizard-weekly-nudges"
                                        checked={
                                            mentorForm.data.weekly_focus_nudges
                                        }
                                        onChange={(e) =>
                                            mentorForm.setData(
                                                'weekly_focus_nudges',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                </SettingRow>
                                <SettingRow
                                    inline
                                    htmlFor="wizard-parent-access"
                                    title="Parent access (view-only)"
                                >
                                    <SwitchInput
                                        id="wizard-parent-access"
                                        checked={mentorForm.data.parent_access}
                                        onChange={(e) =>
                                            mentorForm.setData(
                                                'parent_access',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                </SettingRow>
                                <SettingRow
                                    inline
                                    htmlFor="wizard-mood-checkins"
                                    title="Mood check-ins"
                                >
                                    <SwitchInput
                                        id="wizard-mood-checkins"
                                        checked={mentorForm.data.mood_checkins}
                                        onChange={(e) =>
                                            mentorForm.setData(
                                                'mood_checkins',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                </SettingRow>
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="rounded-full px-4"
                                    disabled={mentorForm.processing}
                                >
                                    Save mentor preferences
                                </Button>
                            </div>
                        </form>
                        <div className="flex justify-end border-t border-border pt-4">
                            <Button
                                type="button"
                                className="w-full rounded-full px-4 sm:w-auto"
                                onClick={() => setFinishProfileOpen(true)}
                            >
                                Your profile is ready
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            <Dialog
                open={finishProfileOpen}
                onOpenChange={setFinishProfileOpen}
            >
                <DialogContent className="z-200 sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Profile complete</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-3">
                        <p className="nums text-4xl font-semibold tracking-tight text-foreground">
                            {profile.completeness_score}{' '}
                            <span className="text-xl font-semibold text-muted-foreground">
                                / 100
                            </span>
                        </p>
                        <p className="text-[14px] text-muted-foreground">
                            Here&apos;s what we&apos;ll focus on first:
                        </p>
                        <p className="text-[14px] font-medium text-foreground">
                            {topCompletenessGap ?? '—'}
                        </p>
                    </div>
                    <DialogFooter className="gap-2 sm:justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-full px-4"
                            onClick={() => {
                                setFinishProfileOpen(false);
                                setActive(4);
                            }}
                        >
                            Keep editing
                        </Button>
                        <Button
                            type="button"
                            className="rounded-full px-4"
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

/** A small outline pill under the name (reference: profile badges). */
function Pill({
    icon: IconComponent,
    children,
    tone = 'neutral',
}: {
    icon: Icon;
    children: ReactNode;
    tone?: 'neutral' | 'success';
}) {
    return (
        <span
            className={cn(
                'inline-flex h-7 max-w-full items-center gap-1.5 rounded-full border px-2.5 text-[12.5px] font-medium',
                tone === 'success'
                    ? 'border-emerald-500/30 bg-emerald-500/8 text-emerald-700 dark:text-emerald-300'
                    : 'border-border text-foreground/80',
            )}
        >
            <IconComponent
                className={cn(
                    'size-3.5 shrink-0',
                    tone === 'success' ? '' : 'text-muted-foreground',
                )}
                weight={tone === 'success' ? 'fill' : 'regular'}
            />
            <span className="truncate">{children}</span>
        </span>
    );
}

/** A label ←→ value row; missing values show a quiet dash. */
function Fact({
    label,
    value,
    hint,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
}) {
    const empty = value === null || value === undefined || value === '';

    return (
        <KeyValueRow
            label={label}
            hint={hint}
            value={
                empty ? (
                    <span className="font-normal text-muted-foreground/70">
                        —
                    </span>
                ) : (
                    <span className="min-w-0 break-words">{value}</span>
                )
            }
        />
    );
}

/** The quiet "Edit" link at the right of a section label. `label` names it for screen readers. */
function EditLink({
    href,
    onClick,
    label,
    children = 'Edit',
}: {
    href?: string;
    onClick?: () => void;
    label: string;
    children?: ReactNode;
}) {
    const className =
        'inline-flex items-center gap-1 rounded-md text-[13px] text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none';

    return href ? (
        <Link href={href} className={className} aria-label={label}>
            {children}
        </Link>
    ) : (
        <button
            type="button"
            onClick={onClick}
            className={className}
            aria-label={label}
        >
            {children}
        </button>
    );
}

/** Long text, clamped to a few lines until opened. */
function Expandable({ text }: { text: string }) {
    const [open, setOpen] = useState(false);
    const long = text.length > 280;

    return (
        <div>
            <p
                className={cn(
                    'text-[14px] leading-relaxed whitespace-pre-wrap text-foreground',
                    long && !open && 'line-clamp-4',
                )}
            >
                {text}
            </p>
            {long ? (
                <button
                    type="button"
                    onClick={() => setOpen((o) => !o)}
                    aria-expanded={open}
                    className="mt-1.5 text-[13px] font-medium text-muted-foreground hover:text-foreground"
                >
                    {open ? 'Show less' : 'Show more'}
                </button>
            ) : null}
        </div>
    );
}

/** A titled list (activities, awards) that shows the first few items until opened. */
function ItemGroup({ label, items }: { label: string; items: string[] }) {
    const [open, setOpen] = useState(false);
    const shown = open ? items : items.slice(0, 5);

    return (
        <div className="py-3">
            <p className="nums mb-1.5 text-[13px] text-muted-foreground">
                {label} · {items.length}
            </p>
            <ul className="space-y-1 text-[14px] text-foreground">
                {shown.map((item, i) => (
                    <li key={`${i}-${item}`} className="flex gap-2.5">
                        <span
                            aria-hidden
                            className="mt-[9px] size-1 shrink-0 rounded-full bg-muted-foreground/60"
                        />
                        <span className="min-w-0">{item}</span>
                    </li>
                ))}
            </ul>
            {items.length > 5 ? (
                <button
                    type="button"
                    onClick={() => setOpen((o) => !o)}
                    aria-expanded={open}
                    className="mt-1.5 text-[13px] font-medium text-muted-foreground hover:text-foreground"
                >
                    {open ? 'Show fewer' : `Show all ${items.length}`}
                </button>
            ) : null}
        </div>
    );
}

/** A disclosure for secondary detail. */
function Disclosure({
    summary,
    children,
}: {
    summary: ReactNode;
    children: ReactNode;
}) {
    return (
        <details className="group rounded-2xl border border-border bg-card">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-5 py-3.5 text-[14px] font-medium text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
                {summary}
                <CaretDown
                    className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                    aria-hidden
                />
            </summary>
            <div className="space-y-5 border-t border-border px-5 py-4">
                {children}
            </div>
        </details>
    );
}

/** An extracted activity or award: usually `{ label }`, but tolerate a plain string. */
function itemLabel(item: unknown): string {
    if (typeof item === 'string') {
        return item.trim();
    }

    const label =
        item && typeof item === 'object'
            ? (item as { label?: unknown }).label
            : null;

    return typeof label === 'string' ? label.trim() : '';
}

function wizardRequested(url: string): boolean {
    const search = url.includes('?') ? (url.split('?')[1] ?? '') : '';

    return new URLSearchParams(search).get('wizard') === '1';
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
    const page = usePage<{ auth: Auth }>();
    const { auth } = page.props;
    const hydrated = useHydrated();
    const getInitials = useInitials();

    // `/profile-overview?wizard=1` (from Settings → AI) opens the wizard, then drops the parameter.
    const openFromUrl = has_profile && !!profile && wizardRequested(page.url);
    const [wizard, setWizard] = useState<{ open: boolean; step?: number }>({
        open: openFromUrl,
    });
    const [seenUrl, setSeenUrl] = useState(page.url);

    if (seenUrl !== page.url) {
        setSeenUrl(page.url);

        if (openFromUrl) {
            setWizard({ open: true });
        }
    }

    useEffect(() => {
        if (!openFromUrl) {
            return;
        }

        const params = new URLSearchParams(page.url.split('?')[1] ?? '');
        params.delete('wizard');
        const base = page.url.split('?')[0] ?? page.url;
        const next = base + (params.toString() ? `?${params.toString()}` : '');
        router.visit(next, {
            replace: true,
            preserveScroll: true,
            preserveState: true,
        });
    }, [openFromUrl, page.url]);

    const [nudgeDismissed, setNudgeDismissed] = useState(false);
    const openWizard = (step?: number) => setWizard({ open: true, step });

    if (!has_profile || !profile) {
        return (
            <>
                <Head title="Profile overview" />
                <Page width="narrow">
                    <PageHeader title="Profile" />
                    <EmptyState
                        icon={
                            <IconTile
                                icon={UserCircle}
                                tone="pink"
                                variant="glyph"
                                size="lg"
                            />
                        }
                        title="No profile record"
                        description="Finish onboarding first."
                        action={
                            <Button asChild className="h-9 rounded-full px-4">
                                <Link href={dashboard()}>Home</Link>
                            </Button>
                        }
                    />
                </Page>
            </>
        );
    }

    const { demographics: who, academics: acad, targets, ai } = profile;
    const complete = isProfileWizardComplete(profile, completion);
    const location = [acad.location_region, acad.location_country]
        .filter(Boolean)
        .join(', ');
    const situation = [
        who.current_grade,
        who.graduation_year !== null
            ? `Graduating ${who.graduation_year}`
            : null,
        location || null,
    ]
        .filter(Boolean)
        .join(' · ');
    const majors = targets.intended_majors.filter((m) => m.trim() !== '');
    const gpa =
        acad.gpa_value !== null
            ? `${acad.gpa_value}${acad.gpa_scale !== null ? ` / ${acad.gpa_scale}` : ''}${acad.gpa_type ? ` (${acad.gpa_type})` : ''}`
            : null;
    const gpaOnFour = normalizedGpaReadout(acad.gpa_value, acad.gpa_scale);
    const activities = profile.activities.map(itemLabel).filter(Boolean);
    const awards = profile.awards.map(itemLabel).filter(Boolean);
    const activeModes = Object.entries(MODE_LABELS)
        .filter(([key]) => profile_modes[key])
        .map(([, label]) => label);
    const hasAnalysis =
        Boolean(ai.excerpt) ||
        ai.radar.length > 0 ||
        ai.signals.length > 0 ||
        ai.takeaways.length > 0;
    const hasStory =
        Boolean(profile.student_summary) ||
        Boolean(profile.spike_summary) ||
        profile.open_questions.length > 0;
    const shortDate = (iso: string | null) =>
        iso && hydrated
            ? new Date(iso).toLocaleDateString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  year: 'numeric',
              })
            : null;

    return (
        <>
            <Head title="Profile overview" />

            <Page width="narrow">
                <div className="relative">
                    <Banner className="h-28 sm:h-36" />
                    <span
                        aria-hidden
                        className="absolute -bottom-9 left-5 flex size-[76px] items-center justify-center overflow-hidden rounded-full bg-card text-[24px] font-semibold tracking-tight text-foreground shadow-raised ring-4 ring-background sm:left-6"
                    >
                        {auth.user.avatar ? (
                            <img
                                src={auth.user.avatar}
                                alt=""
                                className="size-full object-cover"
                            />
                        ) : (
                            getInitials(auth.user.name)
                        )}
                    </span>
                </div>

                <div className="flex min-h-12 items-start justify-end gap-2 pt-3">
                    <DropdownMenu modal={false}>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                size="icon"
                                className="rounded-full"
                                aria-label="More profile options"
                            >
                                <DotsThree className="size-5" weight="bold" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-64">
                            <DropdownMenuItem onSelect={() => openWizard(1)}>
                                <ClipboardText className="size-4" />
                                Paste notes or a resume
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => openWizard(4)}>
                                <Sparkle className="size-4" />
                                Review summary and AI read
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => openWizard(5)}>
                                <ChatCircle className="size-4" />
                                Mentor preferences
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild>
                                <Link href="/documents">
                                    <FileText className="size-4" />
                                    Upload a transcript
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <Link href={settingsProfile()}>
                                    <GearSix className="size-4" />
                                    Account settings
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button asChild className="h-9 gap-1.5 rounded-full px-4">
                        <Link href="/profile-setup">
                            <PencilSimple className="size-4" />
                            Edit profile
                        </Link>
                    </Button>
                </div>

                <header className="mt-1 min-w-0">
                    <h1 className="text-[24px] leading-tight font-semibold tracking-tight text-foreground">
                        {auth.user.name}
                    </h1>
                    {situation ? (
                        <p className="mt-1 text-[14px] text-muted-foreground">
                            {situation}
                        </p>
                    ) : null}
                    <div className="mt-3 flex flex-wrap gap-1.5">
                        {complete ? (
                            <Pill icon={SealCheck} tone="success">
                                Profile complete
                            </Pill>
                        ) : (
                            <Pill icon={ChartPieSlice}>
                                {profile.completeness_score}% complete
                            </Pill>
                        )}
                        {acad.curriculum ? (
                            <Pill icon={GraduationCap}>{acad.curriculum}</Pill>
                        ) : null}
                        {majors.length > 0 ? (
                            <Pill icon={BookOpenText}>
                                {majors.length > 1
                                    ? `${majors[0]} +${majors.length - 1}`
                                    : majors[0]}
                            </Pill>
                        ) : null}
                    </div>
                </header>

                {!complete && !nudgeDismissed ? (
                    <div className="mt-8 flex items-start gap-3.5 rounded-2xl border border-border bg-card p-4 pr-3">
                        <IconTile icon={ChartPieSlice} tone="pink" />
                        <div className="min-w-0 flex-1">
                            <p className="text-[14.5px] font-medium text-foreground">
                                Your profile is {profile.completeness_score}%
                                complete
                            </p>
                            <p className="mt-0.5 text-[13.5px] leading-snug text-muted-foreground">
                                {profile.completeness_callouts[0]
                                    ? `Next: ${profile.completeness_callouts[0]}`
                                    : 'Fuller answers make chat, essays and college research more accurate.'}
                                {profile.completeness_callouts.length > 1
                                    ? ` (+${profile.completeness_callouts.length - 1} more)`
                                    : ''}
                            </p>
                            <Button
                                asChild
                                variant="outline"
                                size="sm"
                                className="mt-3 gap-1 rounded-full pr-2.5 pl-3.5"
                            >
                                <Link href="/profile-setup">
                                    Continue setup
                                    <CaretRight className="size-3.5" />
                                </Link>
                            </Button>
                        </div>
                        <button
                            type="button"
                            onClick={() => setNudgeDismissed(true)}
                            aria-label="Dismiss"
                            className="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                ) : null}

                <div className="mt-10">
                    <Section
                        title="About you"
                        icon={UserCircle}
                        actions={
                            <EditLink
                                href="/profile-setup?section=situation"
                                label="Edit about you"
                            />
                        }
                    >
                        <KeyValueList className="border-y border-border">
                            <Fact
                                label="Grade / year"
                                value={who.current_grade}
                            />
                            <Fact
                                label="Graduation year"
                                value={who.graduation_year}
                            />
                            <Fact label="Age" value={who.age_range_label} />
                            <Fact
                                label="Citizenship"
                                value={acad.citizenship_residency}
                            />
                            <Fact label="Lives in" value={location} />
                        </KeyValueList>
                    </Section>

                    <Section
                        title="Academics"
                        icon={GraduationCap}
                        actions={
                            <EditLink
                                href="/profile-setup?section=academics"
                                label="Edit academics"
                            />
                        }
                    >
                        <KeyValueList className="border-y border-border">
                            <Fact label="Curriculum" value={acad.curriculum} />
                            <Fact label="GPA" value={gpa} />
                            {gpaOnFour ? (
                                <Fact
                                    label="On a 4.0 scale"
                                    hint="Proportional estimate for scanning, not an official recalculation."
                                    value={`≈ ${gpaOnFour}`}
                                />
                            ) : null}
                            {acad.grade_trend ? (
                                <Fact
                                    label="Grade trend"
                                    value={humanize(acad.grade_trend)}
                                />
                            ) : null}
                        </KeyValueList>
                    </Section>

                    <Section
                        title="Testing"
                        icon={Exam}
                        actions={
                            <EditLink
                                href="/profile-setup?section=academics"
                                label="Edit testing"
                            />
                        }
                    >
                        <KeyValueList className="border-y border-border">
                            <Fact label="SAT" value={acad.sat_score} />
                            <Fact label="ACT" value={acad.act_score} />
                            <Fact
                                label="Test plan"
                                value={humanize(acad.test_optional_status)}
                            />
                        </KeyValueList>
                    </Section>

                    <Section
                        title="Interests & destinations"
                        icon={GlobeHemisphereWest}
                        actions={
                            <EditLink
                                href="/profile-setup?section=interests"
                                label="Edit interests and destinations"
                            />
                        }
                    >
                        <KeyValueList className="border-y border-border">
                            <Fact
                                label="Intended majors"
                                value={listOrNull(targets.intended_majors)}
                            />
                            <Fact
                                label="Countries"
                                value={listOrNull(targets.target_countries)}
                            />
                            <Fact
                                label="Regions"
                                value={listOrNull(targets.region_preferences)}
                            />
                            <Fact
                                label="Dream schools"
                                value={listOrNull(targets.dream_schools)}
                            />
                            <Fact
                                label="Campus size"
                                value={
                                    targets.campus_size_pref
                                        ? (CAMPUS_SIZE[
                                              targets.campus_size_pref
                                          ] ??
                                          humanize(targets.campus_size_pref))
                                        : null
                                }
                            />
                        </KeyValueList>
                    </Section>

                    <Section
                        title="Budget & aid"
                        icon={Wallet}
                        actions={
                            <EditLink
                                href="/profile-setup?section=budget"
                                label="Edit budget and aid"
                            />
                        }
                    >
                        <KeyValueList className="border-y border-border">
                            <Fact
                                label="Budget"
                                value={humanize(targets.budget_band)}
                            />
                            <Fact
                                label="Financial aid need"
                                value={
                                    targets.financial_aid_need_percent !== null
                                        ? `${targets.financial_aid_need_percent}%`
                                        : null
                                }
                            />
                        </KeyValueList>
                    </Section>

                    <Section
                        title="Activities & awards"
                        icon={Trophy}
                        actions={
                            <EditLink
                                onClick={() => openWizard(1)}
                                label="Add activities and awards"
                            >
                                Add
                            </EditLink>
                        }
                    >
                        {activities.length === 0 && awards.length === 0 ? (
                            <p className="border-y border-border py-3 text-[14px] text-muted-foreground">
                                None yet. Paste notes or a resume and AI lists
                                them for you.
                            </p>
                        ) : (
                            <div className="divide-y divide-border border-y border-border">
                                {activities.length > 0 ? (
                                    <ItemGroup
                                        label="Activities"
                                        items={activities}
                                    />
                                ) : null}
                                {awards.length > 0 ? (
                                    <ItemGroup label="Awards" items={awards} />
                                ) : null}
                            </div>
                        )}
                    </Section>

                    {hasStory ? (
                        <Section
                            title="Your story"
                            icon={BookOpenText}
                            actions={
                                <EditLink
                                    onClick={() => openWizard(4)}
                                    label="Edit your story"
                                />
                            }
                        >
                            <div className="space-y-4 border-y border-border py-4">
                                {profile.spike_summary ? (
                                    <p className="text-[14px] text-foreground">
                                        <span className="text-muted-foreground">
                                            Spike ·{' '}
                                        </span>
                                        <span className="font-medium">
                                            {profile.spike_summary}
                                        </span>
                                    </p>
                                ) : null}
                                {profile.student_summary ? (
                                    <Expandable
                                        text={profile.student_summary}
                                    />
                                ) : null}
                                {profile.open_questions.length > 0 ? (
                                    <div>
                                        <p className="mb-1.5 text-[13px] text-muted-foreground">
                                            Open questions
                                        </p>
                                        <ol className="list-decimal space-y-1 pl-5 text-[14px] text-foreground marker:text-muted-foreground">
                                            {profile.open_questions.map((q) => (
                                                <li key={q}>{q}</li>
                                            ))}
                                        </ol>
                                    </div>
                                ) : null}
                            </div>
                        </Section>
                    ) : null}

                    <Section
                        title="AI readout"
                        icon={Sparkle}
                        description="Inferred by AI from what you've shared — not verified facts."
                        actions={
                            <EditLink
                                onClick={() => openWizard(4)}
                                label="Refresh the AI readout"
                            >
                                Refresh
                            </EditLink>
                        }
                    >
                        {ai.headline ? (
                            <p className="mb-3 text-[15px] leading-snug font-medium text-foreground">
                                {ai.headline}
                            </p>
                        ) : null}
                        <KeyValueList className="border-y border-border">
                            <Fact
                                label="Mentor adapts for"
                                value={
                                    activeModes.length > 0
                                        ? activeModes.join(', ')
                                        : 'Standard'
                                }
                            />
                            <Fact
                                label="Last analysed"
                                value={shortDate(ai.last_structured_at)}
                            />
                            <Fact
                                label="Knowledge base updated"
                                value={shortDate(ai.last_context_at)}
                            />
                        </KeyValueList>

                        {hasAnalysis ? (
                            <div className="mt-4">
                                <Disclosure summary="Full analysis">
                                    {ai.excerpt ? (
                                        <Expandable text={ai.excerpt} />
                                    ) : null}
                                    {ai.radar.length > 0 ? (
                                        <ul className="space-y-3">
                                            {ai.radar.map((d) => (
                                                <li key={d.key}>
                                                    <div className="flex justify-between gap-3 text-[14px]">
                                                        <span className="font-medium text-foreground">
                                                            {d.label}
                                                        </span>
                                                        {d.score !== null ? (
                                                            <span className="nums text-muted-foreground">
                                                                {Math.round(
                                                                    d.score,
                                                                )}
                                                                /100
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-foreground/70"
                                                            style={{
                                                                width: scoreBarWidth(
                                                                    d.score,
                                                                ),
                                                            }}
                                                        />
                                                    </div>
                                                    {d.verdict ? (
                                                        <p className="mt-1.5 text-[13px] leading-snug text-muted-foreground">
                                                            {d.verdict}
                                                        </p>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}
                                    {ai.signals.length > 0 ? (
                                        <div>
                                            <p className="mb-2 text-[13px] text-muted-foreground">
                                                Signals
                                            </p>
                                            <ul className="flex flex-wrap gap-1.5">
                                                {ai.signals.map((t) => (
                                                    <li
                                                        key={t}
                                                        className="rounded-full bg-muted px-2.5 py-1 text-[12.5px] text-foreground"
                                                    >
                                                        {t}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ) : null}
                                    {ai.takeaways.length > 0 ? (
                                        <div>
                                            <p className="mb-2 text-[13px] text-muted-foreground">
                                                Takeaways
                                            </p>
                                            <ul className="space-y-1.5 text-[14px] text-foreground">
                                                {ai.takeaways.map((t) => (
                                                    <li
                                                        key={t}
                                                        className="flex gap-2"
                                                    >
                                                        <span
                                                            aria-hidden
                                                            className="mt-[8px] size-1 shrink-0 rounded-full bg-muted-foreground/60"
                                                        />
                                                        {t}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ) : null}
                                </Disclosure>
                            </div>
                        ) : !ai.headline ? (
                            <p className="mt-3 text-[13px] text-muted-foreground">
                                No analysis yet. After a brain dump or resume,
                                refresh the admissions read in the wizard.
                            </p>
                        ) : null}
                    </Section>
                </div>

                <Dialog
                    open={wizard.open}
                    onOpenChange={(open) => setWizard((w) => ({ ...w, open }))}
                >
                    <DialogContent
                        showCloseButton
                        className="z-100 flex max-h-[min(92vh,900px)] w-full max-w-[calc(100%-1.5rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl"
                        onOpenAutoFocus={(event) => {
                            // Focus the dialog itself rather than the first step tab, which then looked selected.
                            event.preventDefault();
                            (event.target as HTMLElement | null)?.focus({
                                preventScroll: true,
                            });
                        }}
                    >
                        <div className="shrink-0 border-b border-border px-5 py-4 sm:px-6">
                            <DialogHeader className="gap-1 pr-8 text-left">
                                <DialogTitle className="text-[17px]">
                                    Profile wizard
                                </DialogTitle>
                                <DialogDescription className="text-[13.5px]">
                                    Five steps — paste, clarify gaps, set goals,
                                    review, then mentor tone. This feeds the AI
                                    knowledge base used in chat and tools.
                                </DialogDescription>
                            </DialogHeader>
                        </div>
                        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6">
                            <StepWizard
                                completion={completion}
                                clarification_fields={clarification_fields}
                                mentor_preferences={mentor_preferences}
                                resume_pdf_import={resume_pdf_import}
                                profile={profile}
                                initialStep={wizard.step}
                            />
                        </div>
                    </DialogContent>
                </Dialog>
            </Page>
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
