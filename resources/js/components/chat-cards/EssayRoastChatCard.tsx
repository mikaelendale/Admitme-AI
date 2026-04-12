import { Link } from '@inertiajs/react';
import { Fire } from '@phosphor-icons/react';
import { cn } from '@/lib/utils';

interface ScoreEntry {
    score: number;
    reasoning: string;
}

interface EssayRoastChatCardProps {
    overallVerdict: string;
    scores: Record<string, ScoreEntry>;
    clicheCount: number;
    topPriorityFix: string;
    actionUrl: string;
}

const SCORE_DISPLAY: { key: string; label: string }[] = [
    { key: 'authenticity', label: 'Auth' },
    { key: 'specificity', label: 'Spec' },
    { key: 'emotional_impact', label: 'Impact' },
    { key: 'structure', label: 'Struct' },
    { key: 'differentiation', label: 'Diff' },
];

function scoreColor(score: number): string {
    if (score <= 4) return 'border-destructive/40 bg-destructive/10 text-destructive';
    if (score <= 7) return 'border-amber-500/40 bg-amber-500/10 text-amber-600 dark:text-amber-400';
    return 'border-chart-2/40 bg-chart-2/10 text-chart-2';
}

export function EssayRoastChatCard({
    overallVerdict,
    scores,
    clicheCount,
    topPriorityFix,
    actionUrl,
}: EssayRoastChatCardProps) {
    const scoreValues = SCORE_DISPLAY.map((s) => scores[s.key]?.score ?? 0);
    const avg = scoreValues.length > 0 ? (scoreValues.reduce((a, b) => a + b, 0) / scoreValues.length).toFixed(1) : '—';

    const verdictPreview = overallVerdict.length > 120 ? overallVerdict.slice(0, 120) + '…' : overallVerdict;

    return (
        <div className="mb-3 rounded-lg border border-border bg-card/60 p-3 text-xs shadow-sm">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-md border border-border/70 bg-muted/50">
                    <Fire className="size-5 text-destructive" weight="duotone" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-foreground">Essay Roast</span>
                        <span className="rounded border border-border/60 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            {avg}/10
                        </span>
                    </div>

                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {SCORE_DISPLAY.map((s) => {
                            const entry = scores[s.key];
                            const val = entry?.score ?? 0;
                            return (
                                <span
                                    key={s.key}
                                    className={cn(
                                        'rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                        scoreColor(val),
                                    )}
                                >
                                    {s.label} {val}
                                </span>
                            );
                        })}
                    </div>

                    <p className="mt-2 text-pretty text-muted-foreground">{verdictPreview}</p>

                    <div className="mt-2 rounded-md border-l-2 border-amber-500/50 bg-amber-500/5 py-1.5 pl-2.5 pr-2 dark:bg-amber-500/10">
                        <span className="text-[10px] font-semibold uppercase tracking-wide text-foreground">
                            Top fix
                        </span>
                        <p className="mt-0.5 text-pretty text-muted-foreground">{topPriorityFix}</p>
                    </div>

                    {clicheCount > 0 && (
                        <div className="mt-2">
                            <span className="rounded-full border border-destructive/30 bg-destructive/10 px-2 py-0.5 text-[11px] font-medium text-destructive">
                                {clicheCount} cliché{clicheCount > 1 ? 's' : ''} detected
                            </span>
                        </div>
                    )}

                    <div className="mt-2">
                        <Link
                            href={actionUrl}
                            className="inline-flex h-8 w-full items-center justify-center rounded-md border border-primary/30 bg-primary/10 px-3 text-xs font-medium text-primary transition-colors hover:bg-primary/20"
                        >
                            Full Roast in Essay Suite →
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
