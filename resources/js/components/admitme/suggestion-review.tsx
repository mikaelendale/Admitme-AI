import { Link, router } from '@inertiajs/react';
import { CheckCircle, Sparkle } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import {
    AffordabilityBadge,
    CollegeAssessmentView,
    ReasonCounts,
} from '@/components/admitme/college-assessment';
import {
    Disclosure,
    joinParts,
    placeOf,
    TierIcon,
    TIERS,
} from '@/components/college/college-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useHydrated } from '@/hooks/use-hydrated';
import type { CollegeRow } from '@/lib/college-list-display';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import collegeListsRoutes from '@/routes/college-lists';

type RunInfo = {
    id: string;
    completed_at: string | null;
    summary_text: string | null;
};

type Receipt = {
    added: string[];
    dismissed: string[];
    skipped: Record<string, string>;
};

/**
 * Research suggestions are kept with the run that produced them until the student decides: add the ones
 * they want to the shortlist, dismiss the rest. Nothing is saved to the list without that decision.
 */
export function SuggestionReview({
    suggestions,
    runs,
}: {
    suggestions: CollegeRow[];
    runs: RunInfo[];
}) {
    const [selected, setSelected] = useState<string[]>([]);
    const [busy, setBusy] = useState<'add' | 'dismiss' | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const hydrated = useHydrated();

    const groups = useMemo(() => {
        const byRun = new Map<string, CollegeRow[]>();

        for (const row of suggestions) {
            const key = row.runId ?? 'other';
            byRun.set(key, [...(byRun.get(key) ?? []), row]);
        }

        return [...byRun.entries()];
    }, [suggestions]);

    const toggle = (id: string, on: boolean) =>
        setSelected((current) =>
            on
                ? [...new Set([...current, id])]
                : current.filter((value) => value !== id),
        );

    const decide = async (action: 'add' | 'dismiss') => {
        setBusy(action);
        setError(null);
        setMessage(null);

        try {
            const response = await fetch('/college-lists/suggestions/review', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify(
                    action === 'add'
                        ? { add: selected, dismiss: [] }
                        : { add: [], dismiss: selected },
                ),
            });
            const body = (await response
                .json()
                .catch(() => ({}))) as Partial<Receipt> & { message?: string };

            if (!response.ok) {
                setError(body.message ?? 'Could not save that decision.');

                return;
            }

            const skipped = Object.keys(body.skipped ?? {}).length;
            setMessage(
                (action === 'add'
                    ? `Added ${body.added?.length ?? 0} to your shortlist.`
                    : `Dismissed ${body.dismissed?.length ?? 0}.`) +
                    (skipped > 0
                        ? ` ${skipped} had already been decided and were left as they are.`
                        : ''),
            );
            setSelected([]);
            router.reload({ only: ['colleges', 'councilRunHistory'] });
        } finally {
            setBusy(null);
        }
    };

    if (suggestions.length === 0) {
        return message ? (
            <p
                role="status"
                className="mb-8 flex items-center gap-1.5 text-[13.5px] text-emerald-700 dark:text-emerald-300"
            >
                <CheckCircle
                    aria-hidden
                    className="size-4 shrink-0"
                    weight="fill"
                />
                {message}
            </p>
        ) : null;
    }

    return (
        <section className="mb-9" aria-labelledby="suggestions-heading">
            <div className="mb-3 flex items-end justify-between gap-3">
                <div className="min-w-0">
                    <h2
                        id="suggestions-heading"
                        className="flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground"
                    >
                        <Sparkle className="size-4" aria-hidden />
                        Suggestions to review ({suggestions.length})
                    </h2>
                    <p className="mt-1 text-[13px] text-muted-foreground/80">
                        AI research estimate — not verified. They&apos;re not on
                        your list until you add them.
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-3 text-[13px]">
                    {selected.length > 0 ? (
                        <button
                            type="button"
                            className="rounded-md text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                            onClick={() => setSelected([])}
                        >
                            Clear
                        </button>
                    ) : null}
                    <button
                        type="button"
                        className="rounded-md font-medium text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                        onClick={() =>
                            setSelected(suggestions.map((s) => s.id))
                        }
                    >
                        Select all
                    </button>
                </div>
            </div>

            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                <div className="divide-y divide-border">
                    {groups.map(([runId, rows]) => {
                        const run = runs.find((r) => r.id === runId);
                        const when = run?.completed_at
                            ? hydrated
                                ? new Date(run.completed_at).toLocaleDateString(
                                      undefined,
                                      {
                                          month: 'short',
                                          day: 'numeric',
                                          year: 'numeric',
                                      },
                                  )
                                : null
                            : 'in progress';

                        return (
                            <div key={runId}>
                                <p className="border-b border-border bg-muted/40 px-5 py-2 text-[12.5px] text-muted-foreground">
                                    {run
                                        ? joinParts(['Research run', when])
                                        : 'Other suggestions'}
                                </p>
                                <ul className="divide-y divide-border">
                                    {rows.map((row) => {
                                        const checked = selected.includes(
                                            row.id,
                                        );

                                        return (
                                            <li
                                                key={row.id}
                                                className={cn(
                                                    'flex gap-3 px-4 py-4 transition-colors sm:px-5',
                                                    checked && 'bg-muted/50',
                                                )}
                                            >
                                                <Checkbox
                                                    className="mt-[3px]"
                                                    checked={checked}
                                                    onCheckedChange={(value) =>
                                                        toggle(
                                                            row.id,
                                                            value === true,
                                                        )
                                                    }
                                                    aria-label={`Select ${row.name}`}
                                                />
                                                <TierIcon
                                                    tier={row.tier}
                                                    className="hidden sm:block"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1.5">
                                                        <div className="min-w-0">
                                                            <Link
                                                                href={collegeListsRoutes.school.url(
                                                                    row.id,
                                                                )}
                                                                className="text-[15px] leading-snug font-medium text-foreground underline-offset-2 hover:underline"
                                                            >
                                                                {row.name}
                                                            </Link>
                                                            <p className="nums mt-0.5 text-[13px] text-muted-foreground">
                                                                {joinParts([
                                                                    TIERS[
                                                                        row.tier
                                                                    ].label,
                                                                    placeOf(
                                                                        row,
                                                                    ),
                                                                    `AI fit ${row.fitScore}/100`,
                                                                ])}
                                                            </p>
                                                        </div>
                                                        {row.assessment ? (
                                                            <AffordabilityBadge
                                                                affordability={
                                                                    row
                                                                        .assessment
                                                                        .affordability
                                                                }
                                                            />
                                                        ) : null}
                                                    </div>
                                                    {row.whyFit &&
                                                    row.whyFit !== '—' ? (
                                                        <p className="mt-2 text-[13.5px] leading-snug text-foreground/85">
                                                            {row.whyFit}
                                                        </p>
                                                    ) : null}
                                                    {row.assessment ? (
                                                        <Disclosure
                                                            className="mt-2.5"
                                                            summary={
                                                                <span className="inline-flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                                                    Fit check
                                                                    <ReasonCounts
                                                                        assessment={
                                                                            row.assessment
                                                                        }
                                                                        className="text-[12.5px]"
                                                                    />
                                                                </span>
                                                            }
                                                        >
                                                            <CollegeAssessmentView
                                                                assessment={
                                                                    row.assessment
                                                                }
                                                                compact
                                                                showBadge={
                                                                    false
                                                                }
                                                            />
                                                        </Disclosure>
                                                    ) : null}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-2 border-t border-border bg-muted/30 px-4 py-3 sm:px-5">
                    <Button
                        className="h-8 rounded-full px-3.5"
                        disabled={selected.length === 0 || busy !== null}
                        onClick={() => void decide('add')}
                    >
                        {busy === 'add'
                            ? 'Adding…'
                            : `Add ${selected.length || ''} to my shortlist`.replace(
                                  '  ',
                                  ' ',
                              )}
                    </Button>
                    <Button
                        variant="outline"
                        className="h-8 rounded-full px-3.5"
                        disabled={selected.length === 0 || busy !== null}
                        onClick={() => void decide('dismiss')}
                    >
                        {busy === 'dismiss'
                            ? 'Dismissing…'
                            : `Dismiss ${selected.length || ''}`.trim()}
                    </Button>
                    {message ? (
                        <span
                            role="status"
                            className="text-[13px] text-emerald-700 dark:text-emerald-300"
                        >
                            {message}
                        </span>
                    ) : selected.length === 0 ? (
                        <span className="text-[13px] text-muted-foreground">
                            Tick the ones you want.
                        </span>
                    ) : null}
                    {error ? (
                        <span
                            role="alert"
                            className="text-[13px] text-destructive"
                        >
                            {error}
                        </span>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
