'use client';

import { Link, router } from '@inertiajs/react';
import { Lightbulb, WarningCircle } from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { fetchEssayRunJson, type EssayRunPollJson } from '@/lib/essayRunPoll';
import {
    ESSAY_TYPE_OPTIONS,
    MENTOR_TONE_OPTIONS,
    coerceMentorTone,
    type EssayTypeValue,
    type MentorToneValue,
} from '@/lib/essaySuiteRequirements';
import { ProseMarkdown } from '@/components/markdown/prose-markdown';
import { cn } from '@/lib/utils';
import { collegeLists } from '@/routes';
import type { EssayRecentRunRow, EssaySchoolOption } from './run-types';

export type { EssaySchoolOption };

const BRAINSTORM_PROMPT_STORAGE_KEY = 'admitme:brainstorm-prompt';

function readBrainstormPromptFromStorage(): string {
    if (typeof window === 'undefined') {
        return '';
    }
    try {
        return localStorage.getItem(BRAINSTORM_PROMPT_STORAGE_KEY) ?? '';
    } catch {
        return '';
    }
}

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
    const [essayPrompt, setEssayPrompt] = useState(() => readBrainstormPromptFromStorage());
    const [studentFocus, setStudentFocus] = useState('');
    const [wordLimit, setWordLimit] = useState('');
    const [tone, setTone] = useState<MentorToneValue>(() => coerceMentorTone(defaultMentorTone));
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [activeRunId, setActiveRunId] = useState<string | null>(null);
    const [poll, setPoll] = useState<EssayRunPollJson | null>(null);
    const [selectedRunId, setSelectedRunId] = useState<string | null>(null);
    const [savedCanvas, setSavedCanvas] = useState<string | null>(null);
    const [savedTitle, setSavedTitle] = useState<string | null>(null);
    const [pollingElapsedMs, setPollingElapsedMs] = useState(0);

    const promptSaveTimerRef = useRef<number | null>(null);
    const pollingIntervalRef = useRef<number | null>(null);
    const runStartedAtRef = useRef<number>(0);

    const brainstormRuns = recentRuns.filter((r) => r.run_type === 'brainstorm');

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

    useEffect(() => {
        if (activeRunId === null) {
            setPollingElapsedMs(0);
            if (pollingIntervalRef.current !== null) {
                window.clearInterval(pollingIntervalRef.current);
                pollingIntervalRef.current = null;
            }

            return;
        }

        runStartedAtRef.current = Date.now();
        setPollingElapsedMs(0);
        if (pollingIntervalRef.current !== null) {
            window.clearInterval(pollingIntervalRef.current);
        }
        pollingIntervalRef.current = window.setInterval(() => {
            setPollingElapsedMs(Date.now() - runStartedAtRef.current);
        }, 1000);

        return () => {
            if (pollingIntervalRef.current !== null) {
                window.clearInterval(pollingIntervalRef.current);
                pollingIntervalRef.current = null;
            }
        };
    }, [activeRunId]);

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
                setSavedTitle(j.title_for_card ?? j.school_name ?? 'Brainstorm');
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
                    ? j.error_message ?? 'Run failed.'
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
            setError('Paste the official essay question or prompt (at least 10 characters).');

            return;
        }

        const focus = studentFocus.trim();
        const wl = wordLimit.trim();
        const wordLimitNum = wl === '' ? undefined : Number.parseInt(wl, 10);
        if (wl !== '' && (Number.isNaN(wordLimitNum) || wordLimitNum! < 1)) {
            setError('Word limit must be a positive number, or leave it blank.');

            return;
        }

        setBusy(true);
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
            let data: { run_id?: string; message?: string; errors?: Record<string, string[]> } = {};
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
                    data.message ?? 'You have reached your essay brainstorm limit for your plan.',
                );
                setBusy(false);

                return;
            }

            if (!res.ok) {
                const first =
                    data.errors && typeof data.errors === 'object'
                        ? Object.values(data.errors).flat()[0]
                        : undefined;
                setError(first ?? data.message ?? 'Could not start brainstorm.');
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
    }, [essayPrompt, essayType, schoolId, schools.length, studentFocus, tone, wordLimit]);

    if (schools.length === 0) {
        return (
            <div className="rounded-xl border border-amber-500/35 bg-amber-500/10 px-4 py-6 text-center">
                <p className="text-sm font-medium text-foreground">
                    Brainstormer runs per school on your list.
                </p>
                <p className="mt-2 text-sm text-muted-foreground">
                    Add at least one school, then come back to generate angles tailored to each campus.
                </p>
                <Button type="button" className="mt-4" asChild>
                    <Link href={collegeLists.url()}>Open college lists</Link>
                </Button>
            </div>
        );
    }

    const showRunStatusPanel =
        busy ||
        activeRunId !== null ||
        (poll != null && poll.status !== 'completed');

    return (
        <div className="grid min-h-[min(78vh,820px)] gap-0 lg:grid-cols-2 lg:divide-x lg:divide-border/50 lg:rounded-xl lg:border lg:border-border/60 lg:overflow-hidden">
            <div className="flex min-h-0 flex-col gap-3 overflow-y-auto bg-card/30 p-3">
            <div className="shrink-0 rounded-xl border border-border/60 bg-card/40 p-3">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <label className="text-xs font-medium text-foreground">
                            School (from your list)
                        </label>
                        <select
                            value={schoolId}
                            onChange={(e) => setSchoolId(e.target.value)}
                            className="h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                        >
                            {schools.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name} ({s.tier})
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <label className="text-xs font-medium text-foreground">Essay type</label>
                        <select
                            value={essayType}
                            onChange={(e) => setEssayType(e.target.value as EssayTypeValue)}
                            className="h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                        >
                            {ESSAY_TYPE_OPTIONS.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                        <p className="text-[11px] text-muted-foreground">
                            {ESSAY_TYPE_OPTIONS.find((o) => o.value === essayType)?.hint}
                        </p>
                    </div>
                    <div className="space-y-1.5 sm:col-span-2">
                        <label className="text-xs font-medium text-foreground">
                            Official prompt / question <span className="text-destructive">*</span>
                        </label>
                        <textarea
                            value={essayPrompt}
                            onChange={(e) => {
                                const v = e.target.value;
                                setEssayPrompt(v);
                                scheduleSaveBrainstormPrompt(v);
                            }}
                            rows={4}
                            placeholder="Paste the exact wording from the Common App or the college’s supplement (required)."
                            className="w-full resize-y rounded-md border border-border/70 bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        {essayPrompt.trim() ? (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Your text is saved locally as you type
                            </p>
                        ) : null}
                    </div>
                    <div className="space-y-1.5 sm:col-span-2">
                        <label className="text-xs font-medium text-foreground">
                            What should we emphasize? <span className="text-muted-foreground">(optional)</span>
                        </label>
                        <textarea
                            value={studentFocus}
                            onChange={(e) => setStudentFocus(e.target.value)}
                            rows={3}
                            placeholder="e.g. Tie angles to robotics + tutoring; keep voice humble; avoid cliché sports injury essay."
                            className="w-full resize-y rounded-md border border-border/70 bg-background px-3 py-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <label className="text-xs font-medium text-foreground">
                            Word limit <span className="text-muted-foreground">(optional)</span>
                        </label>
                        <input
                            type="number"
                            min={1}
                            value={wordLimit}
                            onChange={(e) => setWordLimit(e.target.value)}
                            placeholder="e.g. 250"
                            className="h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <label className="text-xs font-medium text-foreground">Tone</label>
                        <select
                            value={tone}
                            onChange={(e) => setTone(e.target.value as MentorToneValue)}
                            className="h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                        >
                            {MENTOR_TONE_OPTIONS.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                        <p className="text-[11px] text-muted-foreground">Defaults from your mentor settings.</p>
                    </div>
                </div>
                {error ? (
                    <p className="mt-2 flex items-center gap-1.5 text-sm text-amber-800 dark:text-amber-200">
                        <WarningCircle className="size-4 shrink-0" />
                        {error}
                    </p>
                ) : null}
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        disabled={busy}
                        onClick={() => void startRun()}
                        className="gap-1.5"
                    >
                        <Lightbulb className="size-4" weight="bold" />
                        {busy ? 'Starting…' : 'Run brainstorm (background job)'}
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        Uses your saved profile (ai_context) plus this school and prompt.
                    </span>
                </div>
            </div>

            {showRunStatusPanel ? (
                <div
                    className={cn(
                        'shrink-0 rounded-lg border px-3 py-2 text-sm',
                        poll?.status === 'failed'
                            ? 'border-destructive/40 bg-destructive/10'
                            : 'border-primary/30 bg-primary/5',
                    )}
                >
                    <span className="font-semibold text-foreground">Progress</span>
                    <p
                        className={cn(
                            'mt-1',
                            poll?.status === 'failed' ? 'text-destructive' : 'text-muted-foreground',
                        )}
                    >
                        {poll?.status === 'failed'
                            ? poll.error_message ?? 'Run failed.'
                            : brainstormProgressMessage(pollingElapsedMs)}
                    </p>
                </div>
            ) : null}

            <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-border/60 bg-card/40">
                <p className="shrink-0 border-b border-border/50 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Saved brainstorms
                </p>
                <p className="shrink-0 border-b border-border/40 px-3 py-1.5 text-[11px] text-muted-foreground">
                    Past runs stay on your account; open one to view angles in the right panel.
                </p>
                <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-2">
                    {brainstormRuns.length === 0 ? (
                        <p className="px-2 py-4 text-center text-xs text-muted-foreground">
                            No saved brainstorms yet.
                        </p>
                    ) : (
                        brainstormRuns.map((r) => {
                            const active = selectedRunId === r.id;
                            return (
                                <button
                                    key={r.id}
                                    type="button"
                                    onClick={() => void loadSavedRun(r.id)}
                                    className={cn(
                                        'w-full rounded-lg border p-2.5 text-left text-sm transition-colors',
                                        active
                                            ? 'border-primary/40 bg-primary/5 ring-1 ring-primary/15'
                                            : 'border-border/50 bg-background/80 hover:bg-muted/30',
                                    )}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="min-w-0 font-medium text-foreground">
                                            {r.title_for_card ?? r.school_name}
                                        </p>
                                        {r.has_output ? (
                                            <span className="shrink-0 rounded border border-chart-2/40 bg-chart-2/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-chart-2">
                                                Saved
                                            </span>
                                        ) : null}
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {r.school_name} · {r.status}
                                    </p>
                                    <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                                        {r.user_prompt_excerpt}
                                    </p>
                                </button>
                            );
                        })
                    )}
                </div>
            </div>
            </div>

            <div className="flex min-h-[min(50vh,420px)] min-w-0 flex-col bg-muted/10 lg:min-h-0">
                <div className="shrink-0 border-b border-border/50 px-3 py-2">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Angles document
                    </p>
                    {savedTitle && savedCanvas ? (
                        <p className="mt-0.5 text-sm font-medium text-foreground">{savedTitle}</p>
                    ) : (
                        <p className="mt-1 text-sm text-muted-foreground">
                            Output from the current or selected run appears here as formatted markdown.
                        </p>
                    )}
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    {activeRunId && poll && poll.status !== 'completed' && poll.status !== 'failed' ? (
                        <p className="text-sm text-muted-foreground">
                            Working… status updates on the left. Markdown will appear here when the
                            job finishes.
                        </p>
                    ) : savedCanvas ? (
                        <ProseMarkdown content={savedCanvas} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Run a brainstorm or choose a saved run from the left.
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
