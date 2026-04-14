'use client';

import { Link, router } from '@inertiajs/react';
import { NotePencil, WarningCircle } from '@phosphor-icons/react';
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

export type { EssayRecentRunRow, EssaySchoolOption };

const DRAFT_SEED_STORAGE_KEY = 'admitme:draft-seed';

function readDraftSeedFromStorage(): string {
    if (typeof window === 'undefined') {
        return '';
    }
    try {
        return localStorage.getItem(DRAFT_SEED_STORAGE_KEY) ?? '';
    } catch {
        return '';
    }
}

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

export function DraftWorkspace({ schools, recentRuns, defaultMentorTone }: DraftWorkspaceProps) {
    const draftRuns = recentRuns.filter((r) => r.run_type === 'draft');

    const [schoolId, setSchoolId] = useState<string>(schools[0]?.id ?? '');
    const [essayType, setEssayType] = useState<EssayTypeValue>('supplemental');
    const [essayPrompt, setEssayPrompt] = useState('');
    const [seed, setSeed] = useState(() => readDraftSeedFromStorage());
    const [wordLimit, setWordLimit] = useState('');
    const [tone, setTone] = useState<MentorToneValue>(() => coerceMentorTone(defaultMentorTone));
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [activeRunId, setActiveRunId] = useState<string | null>(null);
    const [poll, setPoll] = useState<EssayRunPollJson | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [canvas, setCanvas] = useState<string | null>(null);
    const [canvasTitle, setCanvasTitle] = useState<string | null>(null);
    const [pollingElapsedMs, setPollingElapsedMs] = useState(0);

    const seedSaveTimerRef = useRef<number | null>(null);
    const pollingIntervalRef = useRef<number | null>(null);
    const runStartedAtRef = useRef<number>(0);

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
                    ? j.error_message ?? 'Run failed.'
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
            setError('Paste the official essay question (at least 10 characters).');

            return;
        }
        const msg = seed.trim();
        if (msg.length < 20) {
            setError('Paste a seed of at least 20 characters (outline, bullets, or a rough paragraph).');

            return;
        }

        const wl = wordLimit.trim();
        let wordLimitNum: number | undefined;
        if (wl === '') {
            wordLimitNum = undefined;
        } else {
            const n = Number.parseInt(wl, 10);
            if (Number.isNaN(n) || !Number.isFinite(n) || n < 1) {
                setError('Word limit must be a positive number, or leave it blank.');

                return;
            }
            wordLimitNum = n;
        }

        setBusy(true);
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
                setError(data.message ?? 'You have reached your essay draft limit for your plan.');
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
    }, [essayPrompt, essayType, schoolId, schools.length, seed, tone, wordLimit]);

    if (schools.length === 0) {
        return (
            <div className="rounded-xl border border-amber-500/35 bg-amber-500/10 px-4 py-6 text-center">
                <p className="text-sm font-medium text-foreground">
                    Essay crafter is per school on your list.
                </p>
                <p className="mt-2 text-sm text-muted-foreground">
                    Save schools first, then generate drafts in a split view while the job runs.
                </p>
                <Button type="button" className="mt-4" asChild>
                    <Link href={collegeLists.url()}>Open college lists</Link>
                </Button>
            </div>
        );
    }

    return (
        <div className="grid min-h-[min(78vh,820px)] gap-0 lg:grid-cols-2 lg:divide-x lg:divide-border/50 lg:rounded-xl lg:border lg:border-border/60 lg:overflow-hidden">
            <div className="flex min-h-0 flex-col gap-3 bg-card/30 p-3 lg:overflow-y-auto">
                <div className="shrink-0 rounded-xl border border-border/60 bg-card/40 p-3 lg:border-border/50">
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        New draft job
                    </p>
                    <label className="text-xs font-medium text-foreground">School</label>
                    <select
                        value={schoolId}
                        onChange={(e) => setSchoolId(e.target.value)}
                        className="mt-1 h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                    >
                        {schools.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                    <label className="mt-3 block text-xs font-medium text-foreground">Essay type</label>
                    <select
                        value={essayType}
                        onChange={(e) => setEssayType(e.target.value as EssayTypeValue)}
                        className="mt-1 h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                    >
                        {ESSAY_TYPE_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                    <label className="mt-3 block text-xs font-medium text-foreground">
                        Official prompt / question <span className="text-destructive">*</span>
                    </label>
                    <textarea
                        value={essayPrompt}
                        onChange={(e) => setEssayPrompt(e.target.value)}
                        rows={3}
                        className="mt-1 w-full resize-y rounded-md border border-border/70 bg-background px-3 py-2 text-sm outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        placeholder="Paste the exact supplement or Common App wording."
                    />
                    <label className="mt-3 block text-xs font-medium text-foreground">
                        Seed (outline / paragraph) <span className="text-destructive">*</span>
                    </label>
                    <textarea
                        value={seed}
                        onChange={(e) => {
                            const v = e.target.value;
                            setSeed(v);
                            scheduleSaveDraftSeed(v);
                        }}
                        rows={5}
                        className="mt-1 w-full resize-y rounded-md border border-border/70 bg-background px-3 py-2 text-sm outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        placeholder="Paste bullets, a rough intro, or a partial draft — at least ~20 characters."
                    />
                    {seed.trim() ? (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Your text is saved locally as you type
                        </p>
                    ) : null}
                    <div className="mt-3 grid grid-cols-2 gap-2">
                        <div>
                            <label className="text-xs font-medium text-foreground">Word limit</label>
                            <input
                                type="number"
                                min={1}
                                value={wordLimit}
                                onChange={(e) => setWordLimit(e.target.value)}
                                placeholder="Optional"
                                className="mt-1 h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                            />
                        </div>
                        <div>
                            <label className="text-xs font-medium text-foreground">Tone</label>
                            <select
                                value={tone}
                                onChange={(e) => setTone(e.target.value as MentorToneValue)}
                                className="mt-1 h-10 w-full rounded-md border border-border/70 bg-background px-2 text-sm"
                            >
                                {MENTOR_TONE_OPTIONS.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {error ? (
                        <p className="mt-2 flex items-center gap-1.5 text-xs text-amber-800 dark:text-amber-200">
                            <WarningCircle className="size-3.5 shrink-0" />
                            {error}
                        </p>
                    ) : null}
                    <Button
                        type="button"
                        className="mt-3 w-full gap-1.5"
                        disabled={busy}
                        onClick={() => void startCraft()}
                    >
                        <NotePencil className="size-4" />
                        {busy ? 'Queueing…' : 'Craft draft (background)'}
                    </Button>
                    <p className="mt-2 text-[11px] text-muted-foreground">
                        Uses your saved profile (ai_context), this school, the prompt, and your seed.
                    </p>
                </div>

                <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-border/60 bg-card/40">
                    <p className="shrink-0 border-b border-border/50 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Saved drafts
                    </p>
                    <p className="shrink-0 border-b border-border/40 px-3 py-1.5 text-[11px] text-muted-foreground">
                        Each completed job is stored on your account. Open one to read the full
                        markdown in the canvas.
                    </p>
                    <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-2">
                        {draftRuns.length === 0 ? (
                            <p className="px-2 py-4 text-center text-xs text-muted-foreground">
                                No saved drafts yet — craft one above.
                            </p>
                        ) : (
                            draftRuns.map((r) => {
                                const active = selectedId === r.id;
                                return (
                                    <button
                                        key={r.id}
                                        type="button"
                                        onClick={() => void loadIntoCanvas(r.id)}
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
                <div className="flex shrink-0 items-center justify-between border-b border-border/50 px-3 py-2">
                    <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Draft document
                    </span>
                    {(busy || activeRunId !== null || poll?.status === 'failed') ? (
                        <span
                            className={cn(
                                'max-w-[min(100%,18rem)] text-right text-xs leading-snug',
                                poll?.status === 'failed' ? 'text-destructive' : 'text-primary',
                            )}
                        >
                            {poll?.status === 'failed'
                                ? poll.error_message ?? 'Run failed.'
                                : `${draftProgressMessage(pollingElapsedMs)}`}
                        </span>
                    ) : null}
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    {canvasTitle ? (
                        <p className="mb-4 text-sm font-semibold text-foreground">
                            {canvasTitle}
                        </p>
                    ) : null}
                    {canvas ? (
                        <ProseMarkdown content={canvas} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Pick a saved draft or start a new job — formatted output appears here
                            when the background job completes.
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
