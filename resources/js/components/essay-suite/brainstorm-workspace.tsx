'use client';

import { Link, router } from '@inertiajs/react';
import {
    ClockCounterClockwise,
    FileText,
    Lightbulb,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ProseMarkdown } from '@/components/markdown/prose-markdown';
import { EmptyState, IconTile, Section } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { fetchEssayRunJson } from '@/lib/essayRunPoll';
import type { EssayRunPollJson } from '@/lib/essayRunPoll';
import {
    ESSAY_TYPE_OPTIONS,
    MENTOR_TONE_OPTIONS,
    coerceMentorTone,
} from '@/lib/essaySuiteRequirements';
import type {
    EssayTypeValue,
    MentorToneValue,
} from '@/lib/essaySuiteRequirements';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { collegeLists } from '@/routes';
import { SavedRunList } from './run-list';
import type { EssayRecentRunRow, EssaySchoolOption } from './run-types';
import {
    Field,
    NativeSelect,
    Notice,
    Optional,
    Working,
    demoteHeadings,
    proseDocClass,
    fieldClass,
    textareaClass,
    toolCardClass,
    useStoredText,
} from './ui';

export type { EssaySchoolOption };

const BRAINSTORM_PROMPT_STORAGE_KEY = 'admitme:brainstorm-prompt';

function brainstormProgressMessage(elapsedMs: number): string {
    if (elapsedMs < 5000) {
        return 'Starting your brainstorm…';
    }

    if (elapsedMs < 15000) {
        return 'Generating angles based on your profile…';
    }

    if (elapsedMs < 30000) {
        return 'Almost done — finalizing ideas…';
    }

    return 'Taking a bit longer than usual — still working…';
}

type BrainstormWorkspaceProps = {
    schools: EssaySchoolOption[];
    recentRuns: EssayRecentRunRow[];
    defaultMentorTone: string;
};

export function BrainstormWorkspace({
    schools,
    recentRuns,
    defaultMentorTone,
}: BrainstormWorkspaceProps) {
    const [schoolId, setSchoolId] = useState<string>(schools[0]?.id ?? '');
    const [essayType, setEssayType] = useState<EssayTypeValue>('supplemental');
    // The prompt survives a reload (saved on this device); it is read after hydration, never during server render.
    const storedPrompt = useStoredText(BRAINSTORM_PROMPT_STORAGE_KEY);
    const [typedPrompt, setEssayPrompt] = useState<string | null>(null);
    const essayPrompt = typedPrompt ?? storedPrompt;
    const [studentFocus, setStudentFocus] = useState('');
    const [wordLimit, setWordLimit] = useState('');
    const [tone, setTone] = useState<MentorToneValue>(() =>
        coerceMentorTone(defaultMentorTone),
    );
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [activeRunId, setActiveRunId] = useState<string | null>(null);
    const [poll, setPoll] = useState<EssayRunPollJson | null>(null);
    const [selectedRunId, setSelectedRunId] = useState<string | null>(null);
    const [savedCanvas, setSavedCanvas] = useState<string | null>(null);
    const [savedTitle, setSavedTitle] = useState<string | null>(null);
    const [pollingElapsedMs, setPollingElapsedMs] = useState(0);

    const promptSaveTimerRef = useRef<number | null>(null);
    const runStartedAtRef = useRef<number>(0);
    const outputRef = useRef<HTMLDivElement>(null);
    const scrollToOutputRef = useRef(false);

    const brainstormRuns = recentRuns.filter(
        (r) => r.run_type === 'brainstorm',
    );

    const scheduleSaveBrainstormPrompt = useCallback((value: string) => {
        if (promptSaveTimerRef.current !== null) {
            window.clearTimeout(promptSaveTimerRef.current);
        }

        promptSaveTimerRef.current = window.setTimeout(() => {
            promptSaveTimerRef.current = null;

            try {
                localStorage.setItem(BRAINSTORM_PROMPT_STORAGE_KEY, value);
            } catch {
                /* ignore */
            }
        }, 1000);
    }, []);

    // Elapsed time drives the progress copy while a run is active (reset to 0 when a run starts).
    useEffect(() => {
        if (activeRunId === null) {
            return;
        }

        runStartedAtRef.current = Date.now();
        const id = window.setInterval(() => {
            setPollingElapsedMs(Date.now() - runStartedAtRef.current);
        }, 1000);

        return () => window.clearInterval(id);
    }, [activeRunId]);

    // A saved run opened from the list scrolls its angles into view once they load.
    useEffect(() => {
        if (savedCanvas !== null && scrollToOutputRef.current) {
            scrollToOutputRef.current = false;
            outputRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        }
    }, [savedCanvas]);

    const reloadRuns = useCallback(() => {
        router.reload({ only: ['essay_recent_runs'] });
    }, []);

    useEffect(() => {
        if (activeRunId === null) {
            return;
        }

        let cancelled = false;

        async function tick(): Promise<void> {
            const runId = activeRunId;

            if (runId === null) {
                return;
            }

            const j = await fetchEssayRunJson(runId);

            if (cancelled || !j) {
                return;
            }

            setPoll(j);

            if (j.status === 'completed' && j.output_markdown) {
                try {
                    localStorage.removeItem(BRAINSTORM_PROMPT_STORAGE_KEY);
                } catch {
                    /* ignore */
                }

                setSavedCanvas(j.output_markdown);
                setSavedTitle(
                    j.title_for_card ?? j.school_name ?? 'Brainstorm',
                );
                setSelectedRunId(runId);
            } else if (j.status === 'failed') {
                setSavedCanvas(j.error_message ?? 'Run failed.');
                setSavedTitle('Brainstorm');
                setSelectedRunId(runId);
            }

            if (j.status === 'completed' || j.status === 'failed') {
                setActiveRunId(null);
                setBusy(false);
                reloadRuns();
            }
        }

        void tick();
        const id = window.setInterval(() => void tick(), 2000);

        return () => {
            cancelled = true;
            window.clearInterval(id);
        };
    }, [activeRunId, reloadRuns]);

    const loadSavedRun = useCallback(async (runId: string) => {
        setPoll(null);
        setSelectedRunId(runId);
        setSavedCanvas(null);
        const j = await fetchEssayRunJson(runId);

        if (j?.output_markdown) {
            setSavedCanvas(j.output_markdown);
            setSavedTitle(j.title_for_card ?? j.school_name ?? 'Brainstorm');
        } else {
            setSavedCanvas(
                j?.status === 'failed'
                    ? (j.error_message ?? 'Run failed.')
                    : 'Still processing — wait a moment and tap again.',
            );
            setSavedTitle('Brainstorm');
        }
    }, []);

    const startRun = useCallback(async () => {
        setError(null);

        if (schools.length === 0) {
            setError('Add schools to your college list first.');

            return;
        }

        if (!schoolId) {
            setError('Pick a school.');

            return;
        }

        const prompt = essayPrompt.trim();

        if (prompt.length < 10) {
            setError(
                'Paste the official essay question or prompt (at least 10 characters).',
            );

            return;
        }

        const focus = studentFocus.trim();
        const wl = wordLimit.trim();
        const wordLimitNum = wl === '' ? undefined : Number.parseInt(wl, 10);

        if (wl !== '' && (Number.isNaN(wordLimitNum) || wordLimitNum! < 1)) {
            setError(
                'Word limit must be a positive number, or leave it blank.',
            );

            return;
        }

        setBusy(true);
        setPollingElapsedMs(0);
        setPoll(null);
        setSavedCanvas(null);
        setSavedTitle(null);
        setSelectedRunId(null);

        try {
            const body: Record<string, unknown> = {
                college_list_school_id: schoolId,
                run_type: 'brainstorm',
                essay_type: essayType,
                essay_prompt: prompt,
                message: focus,
                tone,
            };

            if (wordLimitNum !== undefined) {
                body.word_limit = wordLimitNum;
            }

            const res = await fetch('/essay-suite/runs', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify(body),
            });
            const raw = await res.text();
            let data: {
                run_id?: string;
                message?: string;
                errors?: Record<string, string[]>;
            } = {};

            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* ignore */
            }

            if (res.status === 409) {
                setError(data.message ?? 'Another essay job is still running.');
                setBusy(false);

                return;
            }

            if (res.status === 429) {
                setError(
                    data.message ??
                        'You have reached your essay brainstorm limit for your plan.',
                );
                setBusy(false);

                return;
            }

            if (!res.ok) {
                const first =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : undefined;
                setError(
                    first ?? data.message ?? 'Could not start brainstorm.',
                );
                setBusy(false);

                return;
            }

            const rid = data.run_id;

            if (!rid) {
                setError('Invalid server response.');
                setBusy(false);

                return;
            }

            setActiveRunId(rid);
        } catch {
            setError('Network error — try again.');
            setBusy(false);
        }
    }, [
        essayPrompt,
        essayType,
        schoolId,
        schools.length,
        studentFocus,
        tone,
        wordLimit,
    ]);

    if (schools.length === 0) {
        return (
            <EmptyState
                icon={
                    <IconTile
                        icon={Lightbulb}
                        tone="amber"
                        variant="glyph"
                        size="lg"
                    />
                }
                title="Brainstorms run per school on your list"
                description="Add at least one school, then come back to generate angles tailored to each campus."
                action={
                    <Button variant="outline" asChild className="rounded-full">
                        <Link href={collegeLists.url()}>
                            Open college lists
                        </Link>
                    </Button>
                }
            />
        );
    }

    const showRunStatusPanel =
        busy ||
        activeRunId !== null ||
        (poll != null && poll.status !== 'completed');
    const typeHint = ESSAY_TYPE_OPTIONS.find(
        (o) => o.value === essayType,
    )?.hint;

    return (
        <div className="space-y-9">
            <div className={toolCardClass}>
                <div className="@container">
                    <div className="grid gap-4 @xl:grid-cols-2">
                        <Field
                            label="School from your list"
                            htmlFor="brainstorm-school"
                        >
                            <NativeSelect
                                id="brainstorm-school"
                                value={schoolId}
                                onChange={(e) => setSchoolId(e.target.value)}
                            >
                                {schools.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.tier})
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Essay type"
                            htmlFor="brainstorm-type"
                            hint={typeHint}
                        >
                            <NativeSelect
                                id="brainstorm-type"
                                value={essayType}
                                onChange={(e) =>
                                    setEssayType(
                                        e.target.value as EssayTypeValue,
                                    )
                                }
                            >
                                {ESSAY_TYPE_OPTIONS.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Official prompt or question"
                            htmlFor="brainstorm-prompt"
                            className="@xl:col-span-2"
                            hint={
                                essayPrompt.trim()
                                    ? 'Your text is saved locally as you type.'
                                    : 'Required — paste the exact wording.'
                            }
                        >
                            <textarea
                                id="brainstorm-prompt"
                                aria-required
                                value={essayPrompt}
                                onChange={(e) => {
                                    const v = e.target.value;
                                    setEssayPrompt(v);
                                    scheduleSaveBrainstormPrompt(v);
                                }}
                                rows={4}
                                placeholder="Paste the exact wording from the Common App or the college’s supplement."
                                className={textareaClass}
                            />
                        </Field>
                        <Field
                            label={
                                <>
                                    What should we emphasize?
                                    <Optional />
                                </>
                            }
                            htmlFor="brainstorm-focus"
                            className="@xl:col-span-2"
                        >
                            <textarea
                                id="brainstorm-focus"
                                value={studentFocus}
                                onChange={(e) =>
                                    setStudentFocus(e.target.value)
                                }
                                rows={3}
                                placeholder="e.g. Tie angles to robotics + tutoring; keep voice humble; avoid cliché sports injury essay."
                                className={textareaClass}
                            />
                        </Field>
                        <Field
                            label={
                                <>
                                    Word limit
                                    <Optional />
                                </>
                            }
                            htmlFor="brainstorm-limit"
                        >
                            <input
                                id="brainstorm-limit"
                                type="number"
                                min={1}
                                inputMode="numeric"
                                value={wordLimit}
                                onChange={(e) => setWordLimit(e.target.value)}
                                placeholder="e.g. 250"
                                className={fieldClass}
                            />
                        </Field>
                        <Field
                            label="Tone"
                            htmlFor="brainstorm-tone"
                            hint="Defaults from your mentor settings."
                        >
                            <NativeSelect
                                id="brainstorm-tone"
                                value={tone}
                                onChange={(e) =>
                                    setTone(e.target.value as MentorToneValue)
                                }
                            >
                                {MENTOR_TONE_OPTIONS.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </div>
                </div>

                {error ? (
                    <Notice tone="warning" role="alert" className="mt-5">
                        {error}
                    </Notice>
                ) : null}

                <div className="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <Button
                        type="button"
                        disabled={busy}
                        onClick={() => void startRun()}
                        className="h-10 gap-2 rounded-xl px-4"
                    >
                        <Lightbulb
                            className="size-4"
                            weight="bold"
                            aria-hidden
                        />
                        {busy ? 'Starting…' : 'Run brainstorm'}
                    </Button>
                    <span className="text-[12.5px] leading-snug text-muted-foreground">
                        Runs in the background, using your saved profile, this
                        school and the prompt.
                    </span>
                </div>
            </div>

            {showRunStatusPanel ? (
                poll?.status === 'failed' ? (
                    <Notice tone="danger" role="alert">
                        {poll.error_message ?? 'Run failed.'}
                    </Notice>
                ) : (
                    <Working
                        title="Brainstorm running"
                        detail={brainstormProgressMessage(pollingElapsedMs)}
                        hint="The angles appear below when the job finishes."
                    />
                )
            ) : null}

            {savedCanvas !== null ? (
                <Section title="Angles" icon={FileText}>
                    <div
                        ref={outputRef}
                        className="scroll-mt-6 rounded-2xl border border-border bg-card px-5 py-6 sm:px-8 sm:py-7"
                    >
                        {savedTitle ? (
                            <p className="mb-5 text-[17px] font-semibold tracking-tight text-foreground">
                                {savedTitle}
                            </p>
                        ) : null}
                        <ProseMarkdown
                            content={demoteHeadings(savedCanvas)}
                            className={proseDocClass}
                        />
                    </div>
                </Section>
            ) : null}

            <Section
                title="Saved brainstorms"
                icon={ClockCounterClockwise}
                description="Past runs stay on your account. Open one to read its angles."
            >
                <SavedRunList
                    runs={brainstormRuns}
                    selectedId={selectedRunId}
                    onOpen={(id) => {
                        scrollToOutputRef.current = true;
                        void loadSavedRun(id);
                    }}
                    icon={Lightbulb}
                    tone="amber"
                    emptyText="No saved brainstorms yet."
                />
            </Section>
        </div>
    );
}
