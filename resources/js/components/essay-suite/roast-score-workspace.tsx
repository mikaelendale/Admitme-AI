'use client';

import { usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ChartBar,
    Lightning,
    Quotes,
    Stack,
    ThumbsUp,
    Waveform,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { EssayStrengthScores } from '@/components/essay/EssayStrengthScores';
import type { EssayRecentRunRow } from '@/components/essay-suite/run-types';
import { Section } from '@/components/page/kit';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import { Button } from '@/components/ui/button';
import { buildEssayRateLimitMessage } from '@/lib/essayRateLimitMessage';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import type { EssayRoastPayload, EssayScoreOnlyPayload } from '@/types/essay';
import type {
    FeatureQuotasProps,
    QuotaFeatureDetail,
} from '@/types/feature-quotas';
import { NativeSelect, Notice, Working, useStoredText } from './ui';

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

const DOT = {
    danger: 'bg-destructive',
    warning: 'bg-amber-500',
    success: 'bg-emerald-500',
} as const;

/** A short bulleted list with a coloured dot per point. */
function Points({ items, tone }: { items: string[]; tone: keyof typeof DOT }) {
    return (
        <ul className="space-y-2.5">
            {items.map((s, i) => (
                <li
                    key={i}
                    className="flex gap-3 text-[14px] leading-relaxed text-foreground/90"
                >
                    <span
                        aria-hidden
                        className={cn(
                            'mt-[9px] size-1.5 shrink-0 rounded-full',
                            DOT[tone],
                        )}
                    />
                    <span className="min-w-0">{s}</span>
                </li>
            ))}
        </ul>
    );
}

/** The one fix to make first, in a soft block. */
function TopFix({ label, text }: { label: string; text: string }) {
    return (
        <div className="rounded-2xl bg-muted/70 px-5 py-4">
            <p className="flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground">
                <Lightning
                    className="size-4 text-amber-500"
                    weight="fill"
                    aria-hidden
                />
                {label}
            </p>
            <p className="mt-1.5 text-[15px] leading-relaxed font-medium text-foreground">
                {text}
            </p>
        </div>
    );
}

export function RoastScoreWorkspace({ recentRuns }: Props) {
    const { feature_quotas } = usePage<{
        feature_quotas?: FeatureQuotasProps | null;
    }>().props;
    const roastQuota = feature_quotas?.features?.essay_roast;
    const scoreQuota = feature_quotas?.features?.cliche_detector;
    const roastBlocked = roastQuota?.remaining === 0;
    const scoreBlocked = scoreQuota?.remaining === 0;

    // The essay survives a reload (saved on this device); it is read after hydration, never during server render.
    const storedText = useStoredText(ROAST_TEXT_STORAGE_KEY);
    const [typedText, setText] = useState<string | null>(null);
    const text = typedText ?? storedText;
    const [phase, setPhase] = useState<'input' | 'results'>('input');
    const [roastBusy, setRoastBusy] = useState(false);
    const [scoreBusy, setScoreBusy] = useState(false);
    const [stageIdx, setStageIdx] = useState(0);
    const [roastPayload, setRoastPayload] = useState<EssayRoastPayload | null>(
        null,
    );
    const [scorePayload, setScorePayload] =
        useState<EssayScoreOnlyPayload | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [rateLimitMessage, setRateLimitMessage] = useState<string | null>(
        null,
    );
    const [quotaModalOpen, setQuotaModalOpen] = useState(false);
    const [quotaModalDetail, setQuotaModalDetail] =
        useState<QuotaFeatureDetail | null>(null);
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
            const res = await fetch(`/essay-suite/runs/${runId}`, {
                credentials: 'same-origin',
            });
            const data = (await res.json()) as { output_markdown?: string };

            if (!res.ok) {
                setError('Could not load that run.');

                return;
            }

            const md = data.output_markdown ?? '';
            const plain = md
                .replace(/#+\s*/g, '')
                .replace(/\*\*/g, '')
                .replace(/`/g, '');
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
        () =>
            recentRuns.filter(
                (r) =>
                    r.run_type === 'draft' &&
                    r.has_output &&
                    r.status === 'completed',
            ),
        [recentRuns],
    );

    if (phase === 'results' && roastPayload) {
        return (
            <div className="space-y-9">
                <section aria-labelledby="roast-verdict">
                    <h2
                        id="roast-verdict"
                        className="text-[13px] font-medium text-muted-foreground"
                    >
                        Verdict
                    </h2>
                    <p className="mt-2 max-w-2xl text-[18px] leading-relaxed font-medium tracking-tight text-pretty text-foreground">
                        {roastPayload.overall_verdict}
                    </p>
                </section>

                <TopFix
                    label="Your single most impactful fix"
                    text={roastPayload.top_priority_fix}
                />

                <Section
                    title="Strength scores"
                    icon={ChartBar}
                    description="Scores are AI estimates."
                >
                    <EssayStrengthScores scores={roastPayload.scores} />
                </Section>

                <Section title="Clichés" icon={Quotes}>
                    {roastPayload.cliches.length === 0 ? (
                        <p className="text-[14px] text-muted-foreground">
                            No common clichés detected — check structural issues
                            below.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                            {roastPayload.cliches.map((c, i) => (
                                <li key={i} className="px-5 py-4">
                                    <p className="text-[14.5px] font-medium text-foreground">
                                        &ldquo;{c.phrase}&rdquo;
                                    </p>
                                    <p className="mt-1 text-[13.5px] leading-relaxed text-muted-foreground">
                                        {c.reason}
                                    </p>
                                    <p className="mt-2 flex items-start gap-2 text-[13.5px] leading-relaxed text-foreground">
                                        <ArrowRight
                                            className="mt-[3px] size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                            aria-hidden
                                        />
                                        <span className="min-w-0">
                                            {c.suggestion}
                                        </span>
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                {roastPayload.structural_issues.length > 0 ? (
                    <Section title="Structural issues" icon={Stack}>
                        <Points
                            items={roastPayload.structural_issues}
                            tone="danger"
                        />
                    </Section>
                ) : null}

                {roastPayload.voice_issues.length > 0 ? (
                    <Section title="Voice issues" icon={Waveform}>
                        <Points
                            items={roastPayload.voice_issues}
                            tone="warning"
                        />
                    </Section>
                ) : null}

                {roastPayload.strengths.length > 0 ? (
                    <Section title="Strengths" icon={ThumbsUp}>
                        <Points items={roastPayload.strengths} tone="success" />
                    </Section>
                ) : null}

                <Button
                    type="button"
                    variant="outline"
                    className="h-10 rounded-xl px-4"
                    onClick={resetToInput}
                >
                    Roast again
                </Button>
            </div>
        );
    }

    if (phase === 'results' && scorePayload && !roastPayload) {
        return (
            <div className="space-y-9">
                <Section
                    title="Quick score"
                    icon={ChartBar}
                    description="Scores and the top fix only — no full roast. Scores are AI estimates."
                >
                    <EssayStrengthScores scores={scorePayload.scores} />
                </Section>
                <TopFix
                    label="Top priority fix"
                    text={scorePayload.top_priority_fix}
                />
                <Button
                    type="button"
                    variant="outline"
                    className="h-10 rounded-xl px-4"
                    onClick={resetToInput}
                >
                    Back
                </Button>
            </div>
        );
    }

    return (
        <>
            <div className="space-y-4">
                <div className="overflow-hidden rounded-2xl border border-border bg-card transition-[border-color,box-shadow] has-[textarea:focus-visible]:border-foreground/25 has-[textarea:focus-visible]:ring-3 has-[textarea:focus-visible]:ring-ring/20">
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-border px-4 py-2.5 sm:px-5">
                        <label
                            htmlFor="roast-essay-text"
                            className="text-[13px] font-medium text-foreground"
                        >
                            Essay text
                        </label>
                        {draftRuns.length > 0 ? (
                            <span className="flex min-w-0 items-center gap-2">
                                <label
                                    htmlFor="roast-load-draft"
                                    className="shrink-0 text-[12.5px] text-muted-foreground"
                                >
                                    Load a completed draft
                                </label>
                                <NativeSelect
                                    id="roast-load-draft"
                                    wrapperClassName="w-40 sm:w-52"
                                    className="h-8 rounded-lg text-[13px]"
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
                                            {r.school_name} ·{' '}
                                            {r.title_for_card ??
                                                r.id.slice(0, 8)}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </span>
                        ) : null}
                    </div>
                    <textarea
                        id="roast-essay-text"
                        value={text}
                        onChange={(e) => {
                            const v = e.target.value.slice(0, MAX_CHARS);
                            setText(v);
                            scheduleSaveRoastText(v);
                        }}
                        rows={14}
                        className="block min-h-[18rem] w-full resize-y bg-transparent px-4 py-4 text-[15px] leading-[1.75] text-foreground outline-none placeholder:text-muted-foreground/70 sm:px-5"
                        placeholder="Paste your essay (minimum 100 characters)…"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-border px-4 py-3 sm:px-5">
                        <p
                            className={cn(
                                'nums text-[12.5px]',
                                len > 0 && len < MIN_CHARS
                                    ? 'text-amber-700 dark:text-amber-300'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {len} / {MAX_CHARS} characters · {wordCount} words
                            {len < MIN_CHARS
                                ? ` · need ${MIN_CHARS - len} more`
                                : ''}
                        </p>
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row-reverse">
                            <Button
                                type="button"
                                className="h-9 w-full rounded-xl px-4 sm:w-auto"
                                disabled={
                                    len < MIN_CHARS ||
                                    roastBusy ||
                                    scoreBusy ||
                                    roastBlocked
                                }
                                onClick={() => void postRoast()}
                            >
                                Roast it
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                className="h-9 w-full rounded-xl px-4 sm:w-auto"
                                disabled={
                                    len < MIN_CHARS ||
                                    roastBusy ||
                                    scoreBusy ||
                                    scoreBlocked
                                }
                                onClick={() => void postScoreOnly()}
                            >
                                {scoreBusy ? 'Scoring…' : 'Score only (fast)'}
                            </Button>
                        </div>
                    </div>
                </div>

                {text.trim() ||
                (roastQuota &&
                    roastQuota.limit != null &&
                    scoreQuota &&
                    scoreQuota.limit != null) ? (
                    <div className="space-y-1 text-[12.5px] leading-snug">
                        {text.trim() ? (
                            <p className="text-muted-foreground">
                                Your text is saved locally as you type.
                            </p>
                        ) : null}
                        {roastQuota &&
                        roastQuota.limit != null &&
                        scoreQuota &&
                        scoreQuota.limit != null ? (
                            <p
                                className={cn(
                                    'nums',
                                    roastQuota.warning || scoreQuota.warning
                                        ? 'text-amber-700 dark:text-amber-300'
                                        : 'text-muted-foreground',
                                )}
                            >
                                Essay roast: {roastQuota.used} of{' '}
                                {roastQuota.limit} used · Cliché detector:{' '}
                                {scoreQuota.used} of {scoreQuota.limit} used
                                {feature_quotas?.window_days
                                    ? ` per ${feature_quotas.window_days} days`
                                    : ''}
                                .
                            </p>
                        ) : null}
                    </div>
                ) : null}

                {roastBusy ? (
                    <Working
                        title={`Reading your essay (${wordCount} words)… then checking details.`}
                        detail={ROAST_STAGES[stageIdx]}
                        hint="Usually 8–12 seconds"
                    />
                ) : null}

                {rateLimitMessage ? (
                    <Notice tone="warning" role="status">
                        {rateLimitMessage}
                    </Notice>
                ) : null}
                {error ? (
                    <Notice tone="danger" role="alert">
                        {error}
                    </Notice>
                ) : null}
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
