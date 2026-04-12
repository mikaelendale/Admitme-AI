'use client';

import type { EssayRoastScores } from '@/types/essay';
import { cn } from '@/lib/utils';

const ORDER: (keyof EssayRoastScores)[] = [
    'authenticity',
    'specificity',
    'emotional_impact',
    'structure',
    'differentiation',
];

const LABELS: Record<keyof EssayRoastScores, string> = {
    authenticity: 'Authenticity',
    specificity: 'Specificity',
    emotional_impact: 'Emotional impact',
    structure: 'Structure',
    differentiation: 'Differentiation',
};

function barColor(score: number): string {
    if (score <= 4) {
        return 'bg-destructive/85';
    }
    if (score <= 7) {
        return 'bg-amber-500/90';
    }

    return 'bg-emerald-600/90';
}

type Props = {
    scores: EssayRoastScores;
    show_reasoning?: boolean;
};

export function EssayStrengthScores({ scores, show_reasoning = true }: Props) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {ORDER.map((key) => {
                const row = scores[key];
                const s = Math.max(0, Math.min(10, Math.round(Number(row?.score ?? 0))));

                return (
                    <div
                        key={key}
                        className="rounded-lg border border-border/60 bg-card/40 p-3 shadow-xs"
                    >
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {LABELS[key]}
                            </span>
                            <span className="tabular-nums text-sm font-semibold text-foreground">
                                {s}
                                <span className="text-muted-foreground">/10</span>
                            </span>
                        </div>
                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn('h-full rounded-full transition-all', barColor(s))}
                                style={{ width: `${s * 10}%` }}
                            />
                        </div>
                        {show_reasoning && row?.reasoning ? (
                            <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                                {row.reasoning}
                            </p>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}
