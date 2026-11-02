'use client';

import { Link, router } from '@inertiajs/react';
import {
    ClockCounterClockwise,
    FileText,
    NotePencil,
    TextT,
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

export type { EssayRecentRunRow, EssaySchoolOption };

const DRAFT_SEED_STORAGE_KEY = 'admitme:draft-seed';

function draftProgressMessage(elapsedMs: number): string {
    if (elapsedMs < 5000) {
        return 'Starting your draft…';
    }

    if (elapsedMs < 15000) {
        return 'Writing based on your prompt and profile…';
    }

    if (elapsedMs < 30000) {
        return 'Polishing the draft…';
    }

    return 'Still working — complex prompts take a moment…';
}

type DraftWorkspaceProps = {
    schools: EssaySchoolOption[];
    recentRuns: EssayRecentRunRow[];
    defaultMentorTone: string;
};

export function DraftWorkspace({
    schools,
    recentRuns,
    defaultMentorTone,
}: DraftWorkspaceProps) {
    const draftRuns = recentRuns.filter((r) => r.run_type === 'draft');

    const [schoolId, setSchoolId] = useState<string>(schools[0]?.id ?? '');
    const [essayType, setEssayType] = useState<EssayTypeValue>('supplemental');
    const [essayPrompt, setEssayPrompt] = useState('');
    // The seed survives a reload (saved on this device); it is read after hydration, never during server render.
    const storedSeed = useStoredText(DRAFT_SEED_STORAGE_KEY);
    const [typedSeed, setSeed] = useState<string | null>(null);
    const seed = typedSeed ?? storedSeed;
    const [wordLimit, setWordLimit] = useState('');
    const [tone, setTone] = useState<MentorToneValue>(() =>
        coerceMentorTone(defaultMentorTone),
    );
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [activeRunId, setActiveRunId] = useState<string | null>(null);
    const [poll, setPoll] = useState<EssayRunPollJson | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [canvas, setCanvas] = useState<string | null>(null);
    const [canvasTitle, setCanvasTitle] = useState<string | null>(null);
    const [pollingElapsedMs, setPollingElapsedMs] = useState(0);

    const seedSaveTimerRef = useRef<number | null>(null);
    const runStartedAtRef = useRef<number>(0);
    const outputRef = useRef<HTMLDivElement>(null);
    const scrollToOutputRef = useRef(false);

    const scheduleSaveDraftSeed = useCallback((value: string) => {
        if (seedSaveTimerRef.current !== null) {
            window.clearTimeout(seedSaveTimerRef.current);
        }

        seedSaveTimerRef.current = window.setTimeout(() => {
            seedSaveTimerRef.current = null;

            try {
                localStorage.setItem(DRAFT_SEED_STORAGE_KEY, value);
            } catch {
                /* ignore */
            }
        }, 1000);
    }, []);

    // Elapsed time drives the progress copy while a job is active (reset to 0 when a job starts).
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

    // A saved draft opened from the list scrolls into view once it loads.
    useEffect(() => {
        if (canvas !== null && scrollToOutputRef.current) {
            scrollToOutputRef.current = false;
            outputRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        }
    }, [canvas]);

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
                    localStorage.removeItem(DRAFT_SEED_STORAGE_KEY);
                } catch {
                    /* ignore */
                }

                setCanvas(j.output_markdown);
                setCanvasTitle(j.title_for_card ?? j.school_name ?? 'Draft');
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

    const loadIntoCanvas = useCallback(async (runId: string) => {
        setSelectedId(runId);
        setCanvas(null);
        const j = await fetchEssayRunJson(runId);

        if (j?.output_markdown) {
            setCanvas(j.output_markdown);
            setCanvasTitle(j.title_for_card ?? j.school_name ?? 'Draft');
        } else {
            setCanvas(
                j?.status === 'failed'
                    ? (j.error_message ?? 'Run failed.')
                    : 'Still processing — wait a moment and tap again.',
            );
            setCanvasTitle('Draft');
        }
    }, []);

    const startCraft = useCallback(async () => {
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
                'Paste the official essay question (at least 10 characters).',
            );

            return;
        }

        const msg = seed.trim();

        if (msg.length < 20) {
            setError(
                'Paste a seed of at least 20 characters (outline, bullets, or a rough paragraph).',
            );

            return;
        }

        const wl = wordLimit.trim();
        let wordLimitNum: number | undefined;

        if (wl === '') {
            wordLimitNum = undefined;
        } else {
            const n = Number.parseInt(wl, 10);

            if (Number.isNaN(n) || !Number.isFinite(n) || n < 1) {
                setError(
                    'Word limit must be a positive number, or leave it blank.',
                );

                return;
            }

            wordLimitNum = n;
        }

        setBusy(true);
        setPollingElapsedMs(0);
        setPoll(null);
        setCanvas(null);

        try {
            const body: Record<string, unknown> = {
                college_list_school_id: schoolId,
                run_type: 'draft',
                essay_type: essayType,
                essay_prompt: prompt,
                message: msg,
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
                        'You have reached your essay draft limit for your plan.',
                );
                setBusy(false);

                return;
            }

            if (!res.ok) {
                const first =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : undefined;
                setError(first ?? data.message ?? 'Could not start draft job.');
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
            setSelectedId(rid);
        } catch {
            setError('Network error — try again.');
            setBusy(false);
        }
    }, [
        essayPrompt,
        essayType,
        schoolId,
        schools.length,
        seed,
        tone,
        wordLimit,
    ]);

    if (schools.length === 0) {
        return (
            <EmptyState
                icon={
                    <IconTile
                        icon={TextT}
                        tone="blue"
                        variant="glyph"
                        size="lg"
                    />
                }
                title="Drafts are crafted per school on your list"
                description="Save schools first, then generate drafts while the job runs in the background."
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

    const failed = poll?.status === 'failed';
    const running = busy || activeRunId !== null;

    return (
        <div className="space-y-9">
            <div className={toolCardClass}>
                <div className="@container">
                    <div className="grid gap-4 @xl:grid-cols-2">
                        <Field label="School" htmlFor="draft-school">
                            <NativeSelect
                                id="draft-school"
                                value={schoolId}
                                onChange={(e) => setSchoolId(e.target.value)}
                            >
                                {schools.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Essay type" htmlFor="draft-type">
                            <NativeSelect
                                id="draft-type"
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
                            htmlFor="draft-prompt"
                            className="@xl:col-span-2"
                        >
                            <textarea
                                id="draft-prompt"
                                aria-required
                                value={essayPrompt}
                                onChange={(e) => setEssayPrompt(e.target.value)}
                                rows={3}
                                className={textareaClass}
                                placeholder="Paste the exact supplement or Common App wording."
                            />
                        </Field>
                        <Field
                            label="Seed — an outline or rough paragraph"
                            htmlFor="draft-seed"
                            className="@xl:col-span-2"
                            hint={
                                seed.trim()
                                    ? 'Your text is saved locally as you type.'
                                    : 'Required — at least about 20 characters.'
                            }
                        >
                            <textarea
                                id="draft-seed"
                                aria-required
                                value={seed}
                                onChange={(e) => {
                                    const v = e.target.value;
                                    setSeed(v);
                                    scheduleSaveDraftSeed(v);
                                }}
                                rows={5}
                                className={textareaClass}
                                placeholder="Paste bullets, a rough intro, or a partial draft."
                            />
                        </Field>
                        <Field
                            label={
                                <>
                                    Word limit
                                    <Optional />
                                </>
                            }
                            htmlFor="draft-limit"
                        >
                            <input
                                id="draft-limit"
                                type="number"
                                min={1}
                                inputMode="numeric"
                                value={wordLimit}
                                onChange={(e) => setWordLimit(e.target.value)}
                                placeholder="e.g. 250"
                                className={fieldClass}
                            />
                        </Field>
                        <Field label="Tone" htmlFor="draft-tone">
                            <NativeSelect
                                id="draft-tone"
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
                        onClick={() => void startCraft()}
                        className="h-10 gap-2 rounded-xl px-4"
                    >
                        <NotePencil className="size-4" aria-hidden />
                        {busy ? 'Queueing…' : 'Craft draft'}
                    </Button>
                    <span className="text-[12.5px] leading-snug text-muted-foreground">
                        Runs in the background, using your saved profile, this
                        school, the prompt and your seed.
                    </span>
                </div>
            </div>

            {failed ? (
                <Notice tone="danger" role="alert">
                    {poll?.error_message ?? 'Run failed.'}
                </Notice>
            ) : running ? (
                <Working
                    title="Draft in progress"
                    detail={draftProgressMessage(pollingElapsedMs)}
                    hint="The draft appears below when the job finishes."
                />
            ) : null}

            {canvas !== null ? (
                <Section title="Draft" icon={FileText}>
                    <div
                        ref={outputRef}
                        className="scroll-mt-6 rounded-2xl border border-border bg-card px-5 py-6 sm:px-8 sm:py-7"
                    >
                        {canvasTitle ? (
                            <p className="mb-5 text-[17px] font-semibold tracking-tight text-foreground">
                                {canvasTitle}
                            </p>
                        ) : null}
                        <ProseMarkdown
                            content={demoteHeadings(canvas)}
                            className={proseDocClass}
                        />
                    </div>
                </Section>
            ) : null}

            <Section
                title="Saved drafts"
                icon={ClockCounterClockwise}
                description="Each finished job is stored on your account. Open one to read it in full."
            >
                <SavedRunList
                    runs={draftRuns}
                    selectedId={selectedId}
                    onOpen={(id) => {
                        scrollToOutputRef.current = true;
                        void loadIntoCanvas(id);
                    }}
                    icon={TextT}
                    tone="blue"
                    emptyText="No saved drafts yet — craft one above."
                />
            </Section>
        </div>
    );
}
