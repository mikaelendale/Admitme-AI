'use client';

import { cn } from '@/lib/utils';
import type { EssayRoastScores } from '@/types/essay';

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
        return 'bg-destructive';
    }

    if (score <= 7) {
        return 'bg-amber-500';
    }

    return 'bg-emerald-500';
}

type Props = {
    scores: EssayRoastScores;
    show_reasoning?: boolean;
    className?: string;
};

/** Five 0–10 scores as one quiet list: label, bar, score, and the model's reasoning underneath. */
export function EssayStrengthScores({
    scores,
    show_reasoning = true,
    className,
}: Props) {
    return (
        <ul
            className={cn(
                'divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card',
                className,
            )}
        >
            {ORDER.map((key) => {
                const row = scores[key];
                const s = Math.max(
                    0,
                    Math.min(10, Math.round(Number(row?.score ?? 0))),
                );

                return (
                    <li key={key} className="px-4 py-3.5 sm:px-5">
                        <div className="flex items-center gap-3 sm:gap-4">
                            <span className="w-28 shrink-0 text-[13.5px] font-medium text-foreground sm:w-36">
                                {LABELS[key]}
                            </span>
                            <span
                                aria-hidden
                                className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    className={cn(
                                        'block h-full rounded-full transition-[width] duration-500',
                                        barColor(s),
                                    )}
                                    style={{ width: `${s * 10}%` }}
                                />
                            </span>
                            <span className="nums w-11 shrink-0 text-right text-[13.5px] font-semibold text-foreground">
                                {s}
                                <span className="font-normal text-muted-foreground">
                                    /10
                                </span>
                            </span>
                        </div>
                        {show_reasoning && row?.reasoning ? (
                            <p className="mt-1.5 text-[13px] leading-relaxed text-muted-foreground">
                                {row.reasoning}
                            </p>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}
