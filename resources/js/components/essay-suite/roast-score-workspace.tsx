'use client';

import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import { Button } from '@/components/ui/button';
import { EssayStrengthScores } from '@/components/essay/EssayStrengthScores';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { buildEssayRateLimitMessage } from '@/lib/essayRateLimitMessage';
import { cn } from '@/lib/utils';
import type { EssayRecentRunRow } from '@/components/essay-suite/run-types';
import type { FeatureQuotasProps, QuotaFeatureDetail } from '@/types/feature-quotas';
import type { EssayRoastPayload, EssayScoreOnlyPayload } from '@/types/essay';

const ROAST_STAGES = [
    'Reading your essay…',
    'Checking for clichés…',
    'Scoring against admitted essays…',
] as const;

const MIN_CHARS = 100;

const MAX_CHARS = 8000;

const ROAST_TEXT_STORAGE_KEY = 'admitme:essay-roast-text';

type Props = {
    recentRuns: EssayRecentRunRow[];
};

function readRoastTextFromStorage(): string {
    if (typeof window === 'undefined') {
        return '';
    }
    try {
        return localStorage.getItem(ROAST_TEXT_STORAGE_KEY) ?? '';
    } catch {
        return '';
    }
}

export function RoastScoreWorkspace({ recentRuns }: Props) {
    const { feature_quotas } = usePage<{ feature_quotas?: FeatureQuotasProps | null }>().props;
    const roastQuota = feature_quotas?.features?.essay_roast;
    const scoreQuota = feature_quotas?.features?.cliche_detector;
    const roastBlocked = roastQuota?.remaining === 0;
    const scoreBlocked = scoreQuota?.remaining === 0;

    const [text, setText] = useState(() => readRoastTextFromStorage());
    const [phase, setPhase] = useState<'input' | 'results'>('input');
    const [roastBusy, setRoastBusy] = useState(false);
    const [scoreBusy, setScoreBusy] = useState(false);
    const [stageIdx, setStageIdx] = useState(0);
    const [roastPayload, setRoastPayload] = useState<EssayRoastPayload | null>(null);
    const [scorePayload, setScorePayload] = useState<EssayScoreOnlyPayload | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [rateLimitMessage, setRateLimitMessage] = useState<string | null>(null);
    const [quotaModalOpen, setQuotaModalOpen] = useState(false);
    const [quotaModalDetail, setQuotaModalDetail] = useState<QuotaFeatureDetail | null>(null);
    const saveTextTimerRef = useRef<number | null>(null);

    const len = text.length;
    const wordCount = useMemo(() => {
        const t = text.trim();
        if (!t) {
            return 0;
        }

        return t.split(/\s+/).filter(Boolean).length;
    }, [text]);

    useEffect(() => {
        if (!roastBusy) {
            return;
        }
        const id = window.setInterval(() => {
            setStageIdx((i) => (i + 1) % ROAST_STAGES.length);
        }, 3000);

        return () => window.clearInterval(id);
    }, [roastBusy]);

    const scheduleSaveRoastText = useCallback((value: string) => {
        if (saveTextTimerRef.current !== null) {
            window.clearTimeout(saveTextTimerRef.current);
        }
        saveTextTimerRef.current = window.setTimeout(() => {
            saveTextTimerRef.current = null;
            try {
                localStorage.setItem(ROAST_TEXT_STORAGE_KEY, value);
            } catch {
                /* ignore */
            }
        }, 1000);
    }, []);

    const loadFromRun = useCallback(async (runId: string) => {
        setError(null);
        setRateLimitMessage(null);
        try {
            const res = await fetch(`/essay-suite/runs/${runId}`, { credentials: 'same-origin' });
            const data = (await res.json()) as { output_markdown?: string };
            if (!res.ok) {
                setError('Could not load that run.');

                return;
            }
            const md = data.output_markdown ?? '';
            const plain = md.replace(/#+\s*/g, '').replace(/\*\*/g, '').replace(/`/g, '');
            setText(plain.slice(0, MAX_CHARS));
        } catch {
            setError('Network error loading run.');
        }
    }, []);

    const postRoast = useCallback(async () => {
        setError(null);
        setRateLimitMessage(null);
        setRoastBusy(true);
        setStageIdx(0);
        setScorePayload(null);
        try {
            const res = await fetch('/essay-suite/roast', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({ essay_text: text }),
            });
            const raw = await res.text();
            let data: {
                payload?: EssayRoastPayload;
                message?: string;
                errors?: Record<string, string[]>;
                quota?: QuotaFeatureDetail;
            } = {};
            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* ignore */
            }
            if (res.status === 429) {
                setRateLimitMessage(
                    typeof data.message === 'string' && data.message !== ''
                        ? data.message
                        : buildEssayRateLimitMessage('roast', res),
                );
                if (data.quota) {
                    setQuotaModalDetail(data.quota);
                    setQuotaModalOpen(true);
                }

                return;
            }
            if (res.status === 422) {
                const msg =
                    data.errors?.analysis?.[0] ??
                    data.errors?.essay_text?.[0] ??
                    data.message ??
                    'Validation failed.';

                setError(msg);

                return;
            }
            if (!res.ok) {
                setError(data.message ?? 'Roast failed.');

                return;
            }
            if (data.payload) {
                try {
                    localStorage.removeItem(ROAST_TEXT_STORAGE_KEY);
                } catch {
                    /* ignore */
                }
                setRoastPayload(data.payload);
                setPhase('results');
            }
        } catch {
            setError('Network error.');
        } finally {
            setRoastBusy(false);
        }
    }, [text]);

    const postScoreOnly = useCallback(async () => {
        setError(null);
        setRateLimitMessage(null);
        setScoreBusy(true);
        setRoastPayload(null);
        try {
            const res = await fetch('/essay-suite/score-only', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({ essay_text: text }),
            });
            const raw = await res.text();
            let data: {
                payload?: EssayScoreOnlyPayload;
                message?: string;
                errors?: Record<string, string[]>;
                quota?: QuotaFeatureDetail;
            } = {};
            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* ignore */
            }
            if (res.status === 429) {
                setRateLimitMessage(
                    typeof data.message === 'string' && data.message !== ''
                        ? data.message
                        : buildEssayRateLimitMessage('score', res),
                );
                if (data.quota) {
                    setQuotaModalDetail(data.quota);
                    setQuotaModalOpen(true);
                }

                return;
            }
            if (res.status === 422) {
                setError(
                    data.errors?.analysis?.[0] ??
                        data.errors?.essay_text?.[0] ??
                        data.message ??
                        'Validation failed.',
                );

                return;
            }
            if (!res.ok) {
                setError(data.message ?? 'Scoring failed.');

                return;
            }
            if (data.payload) {
                try {
                    localStorage.removeItem(ROAST_TEXT_STORAGE_KEY);
                } catch {
                    /* ignore */
                }
                setScorePayload(data.payload);
                setPhase('results');
            }
        } catch {
            setError('Network error.');
        } finally {
            setScoreBusy(false);
        }
    }, [text]);

    const resetToInput = useCallback(() => {
        setPhase('input');
        setRoastPayload(null);
        setScorePayload(null);
        setError(null);
        setRateLimitMessage(null);
    }, []);

    const draftRuns = useMemo(
        () => recentRuns.filter((r) => r.run_type === 'draft' && r.has_output && r.status === 'completed'),
        [recentRuns],
    );

    if (phase === 'results' && roastPayload) {
        return (
            <div className="space-y-6">
                <blockquote className="border-l-4 border-foreground/25 pl-4 text-base leading-relaxed text-foreground">
                    {roastPayload.overall_verdict}
                </blockquote>

                <div>
                    <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Strength scores
                    </p>
                    <EssayStrengthScores scores={roastPayload.scores} />
                </div>

                <div>
                    <h3 className="text-sm font-semibold text-foreground">Clichés</h3>
                    {roastPayload.cliches.length === 0 ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            No common clichés detected — check structural issues below.
                        </p>
                    ) : (
                        <ul className="mt-2 space-y-3">
                            {roastPayload.cliches.map((c, i) => (
                                <li
                                    key={i}
                                    className="rounded-lg border border-border/60 bg-card/40 p-3 text-sm"
                                >
                                    <p className="font-medium text-amber-900 dark:text-amber-100">
                                        &ldquo;{c.phrase}&rdquo;
                                    </p>
                                    <p className="mt-1 text-muted-foreground">{c.reason}</p>
                                    <p className="mt-2 text-emerald-900 dark:text-emerald-100">{c.suggestion}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="border-l-4 border-destructive/50 pl-3">
                    <h3 className="text-sm font-semibold text-destructive">Structural issues</h3>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {roastPayload.structural_issues.map((s, i) => (
                            <li key={i}>{s}</li>
                        ))}
                    </ul>
                </div>

                <div className="border-l-4 border-amber-500/60 pl-3">
                    <h3 className="text-sm font-semibold text-amber-900 dark:text-amber-100">Voice issues</h3>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {roastPayload.voice_issues.map((s, i) => (
                            <li key={i}>{s}</li>
                        ))}
                    </ul>
                </div>

                <div className="border-l-4 border-emerald-600/50 pl-3">
                    <h3 className="text-sm font-semibold text-emerald-900 dark:text-emerald-100">Strengths</h3>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        {roastPayload.strengths.map((s, i) => (
                            <li key={i}>{s}</li>
                        ))}
                    </ul>
                </div>

                <div className="w-full rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-950 dark:text-amber-50">
                    <p className="text-xs font-semibold uppercase tracking-wider text-amber-900/90 dark:text-amber-100">
                        Your single most impactful fix
                    </p>
                    <p className="mt-2 font-medium text-foreground">{roastPayload.top_priority_fix}</p>
                </div>

                <Button type="button" variant="secondary" onClick={resetToInput}>
                    Roast again
                </Button>
            </div>
        );
    }

    if (phase === 'results' && scorePayload && !roastPayload) {
        return (
            <div className="space-y-6">
                <p className="text-sm text-muted-foreground">Quick score (no full roast)</p>
                <EssayStrengthScores scores={scorePayload.scores} />
                <div className="w-full rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                    <p className="text-xs font-semibold uppercase tracking-wider">Top priority fix</p>
                    <p className="mt-2 font-medium text-foreground">{scorePayload.top_priority_fix}</p>
                </div>
                <Button type="button" variant="secondary" onClick={resetToInput}>
                    Back
                </Button>
            </div>
        );
    }

    return (
        <>
        <div className="space-y-4">
            {draftRuns.length > 0 ? (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs text-muted-foreground">Load text from completed draft:</span>
                    <select
                        className="rounded-md border border-border/60 bg-background px-2 py-1 text-sm"
                        defaultValue=""
                        onChange={(e) => {
                            const v = e.target.value;
                            if (v) {
                                void loadFromRun(v);
                            }
                        }}
                    >
                        <option value="">—</option>
                        {draftRuns.map((r) => (
                            <option key={r.id} value={r.id}>
                                {r.school_name} · {r.title_for_card ?? r.id.slice(0, 8)}
                            </option>
                        ))}
                    </select>
                </div>
            ) : null}

            <div>
                <label className="text-sm font-medium text-foreground" htmlFor="roast-essay-text">
                    Essay text
                </label>
                <textarea
                    id="roast-essay-text"
                    value={text}
                    onChange={(e) => {
                        const v = e.target.value.slice(0, MAX_CHARS);
                        setText(v);
                        scheduleSaveRoastText(v);
                    }}
                    rows={14}
                    className={cn(
                        'mt-1 w-full resize-y rounded-lg border border-border/60 bg-background p-3 text-sm',
                        len < MIN_CHARS && len > 0 && 'border-amber-500/40',
                    )}
                    placeholder="Paste your essay (minimum 100 characters)…"
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    {len} / {MAX_CHARS} characters · {wordCount} words
                    {len < MIN_CHARS ? ` · need ${MIN_CHARS - len} more` : ''}
                </p>
                {text.trim() ? (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Your text is saved locally as you type
                    </p>
                ) : null}
            </div>

            {roastBusy ? (
                <div
                    className="rounded-xl border border-primary/30 bg-primary/5 p-4"
                    aria-live="polite"
                >
                    <p className="text-sm font-medium text-foreground">
                        Reading your essay ({wordCount} words)… then checking details.
                    </p>
                    <p className="mt-2 text-sm text-primary">{ROAST_STAGES[stageIdx]}</p>
                    <p className="mt-3 text-xs text-muted-foreground">Usually 8–12 seconds</p>
                </div>
            ) : null}

            {rateLimitMessage ? (
                <div
                    className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-100"
                    role="status"
                >
                    {rateLimitMessage}
                </div>
            ) : null}
            {error ? <p className="text-sm text-destructive">{error}</p> : null}

            {roastQuota && roastQuota.limit != null && scoreQuota && scoreQuota.limit != null ? (
                <p
                    className={cn(
                        'text-xs',
                        roastQuota.warning || scoreQuota.warning
                            ? 'text-amber-700 dark:text-amber-300'
                            : 'text-muted-foreground',
                    )}
                >
                    Essay roast: {roastQuota.used} of {roastQuota.limit} used · Cliché detector: {scoreQuota.used}{' '}
                    of {scoreQuota.limit} used
                    {feature_quotas?.window_days ? ` per ${feature_quotas.window_days} days` : ''}.
                </p>
            ) : null}

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    disabled={len < MIN_CHARS || roastBusy || scoreBusy || roastBlocked}
                    onClick={() => void postRoast()}
                >
                    Roast it
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={len < MIN_CHARS || roastBusy || scoreBusy || scoreBlocked}
                    onClick={() => void postScoreOnly()}
                >
                    Score only (fast)
                </Button>
            </div>
        </div>
        <QuotaExhaustedModal
            open={quotaModalOpen}
            onOpenChange={setQuotaModalOpen}
            quota={quotaModalDetail}
            plan={feature_quotas?.plan}
        />
        </>
    );
}
