import { Link } from '@inertiajs/react';
import { Fire } from '@phosphor-icons/react';
import { Button } from '@/components/ui/button';
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
    { key: 'authenticity', label: 'Authenticity' },
    { key: 'specificity', label: 'Specificity' },
    { key: 'emotional_impact', label: 'Impact' },
    { key: 'structure', label: 'Structure' },
    { key: 'differentiation', label: 'Differentiation' },
];

function scoreColor(score: number): string {
    if (score <= 4) {
        return 'text-destructive';
    }

    if (score <= 7) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-emerald-600 dark:text-emerald-400';
}

/** A roast summary inside the mentor panel: average, five scores, the top fix and a link to the full roast. */
export function EssayRoastChatCard({
    overallVerdict,
    scores,
    clicheCount,
    topPriorityFix,
    actionUrl,
}: EssayRoastChatCardProps) {
    const scoreValues = SCORE_DISPLAY.map((s) => scores[s.key]?.score ?? 0);
    const avg =
        scoreValues.length > 0
            ? (
                  scoreValues.reduce((a, b) => a + b, 0) / scoreValues.length
              ).toFixed(1)
            : '—';

    const verdictPreview =
        overallVerdict.length > 120
            ? overallVerdict.slice(0, 120) + '…'
            : overallVerdict;

    return (
        <div className="mb-2 flex items-start gap-3 rounded-xl bg-muted p-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-orange-500 shadow-card">
                <Fire className="size-4" weight="duotone" aria-hidden />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-[13.5px] font-medium text-foreground">
                    Essay roast{' '}
                    <span className="nums font-normal text-muted-foreground">
                        · {avg}/10 average
                    </span>
                </p>
                <p className="mt-0.5 text-[12.5px] leading-snug text-pretty text-muted-foreground">
                    {verdictPreview}
                </p>

                <dl className="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-1 text-[12.5px]">
                    {SCORE_DISPLAY.map((s) => {
                        const val = scores[s.key]?.score ?? 0;

                        return (
                            <div
                                key={s.key}
                                className="flex min-w-0 items-baseline justify-between gap-2"
                            >
                                <dt className="truncate text-muted-foreground">
                                    {s.label}
                                </dt>
                                <dd
                                    className={cn(
                                        'nums font-semibold',
                                        scoreColor(val),
                                    )}
                                >
                                    {val}
                                </dd>
                            </div>
                        );
                    })}
                </dl>
                <p className="mt-1 text-[11.5px] text-muted-foreground/80">
                    Scores are AI estimates.
                </p>

                <p className="mt-2 text-[12.5px] leading-snug text-pretty">
                    <span className="font-medium text-foreground">
                        Top fix:{' '}
                    </span>
                    <span className="text-muted-foreground">
                        {topPriorityFix}
                    </span>
                </p>

                {clicheCount > 0 ? (
                    <p className="mt-1.5 text-[12.5px] font-medium text-destructive">
                        {clicheCount} cliché{clicheCount > 1 ? 's' : ''}{' '}
                        detected
                    </p>
                ) : null}

                <div className="mt-2.5">
                    <Button size="xs" variant="outline" asChild>
                        <Link href={actionUrl}>Full roast in Essay suite</Link>
                    </Button>
                </div>
            </div>
        </div>
    );
}
