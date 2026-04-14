import { Link } from '@inertiajs/react';
import { ShieldWarning } from '@phosphor-icons/react';

interface GapFixChatCardProps {
    schoolNames: string[];
    schoolCount: number;
    actionUrl: string;
    analysisId: string;
}

export function GapFixChatCard({ schoolNames, schoolCount, actionUrl }: GapFixChatCardProps) {
    const visibleSchools = schoolNames.slice(0, 4);
    const extraCount = schoolNames.length - visibleSchools.length;

    return (
        <div className="mb-3 rounded-lg border border-border bg-card/60 p-3 text-xs shadow-sm">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-md border border-amber-500/30 bg-amber-500/10">
                    <ShieldWarning className="size-5 text-amber-500" weight="duotone" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-foreground">Gap Analysis Ready</span>
                        <span className="rounded border border-chart-2/40 bg-chart-2/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-chart-2">
                            {schoolCount} {schoolCount === 1 ? 'school' : 'schools'}
                        </span>
                    </div>

                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {visibleSchools.map((name) => (
                            <span
                                key={name}
                                className="rounded-full border border-border/60 bg-muted/40 px-2 py-0.5 text-[11px] font-medium text-foreground"
                            >
                                {name}
                            </span>
                        ))}
                        {extraCount > 0 && (
                            <span className="rounded-full border border-border/60 bg-muted/40 px-2 py-0.5 text-[11px] text-muted-foreground">
                                +{extraCount} more
                            </span>
                        )}
                    </div>

                    <p className="mt-2 text-pretty text-muted-foreground">
                        Your rejection risks and action plan are ready. See what's holding you back and how to fix it.
                    </p>

                    <div className="mt-2">
                        <Link
                            href={actionUrl}
                            className="inline-flex h-8 w-full items-center justify-center rounded-md border border-primary/30 bg-primary/10 px-3 text-xs font-medium text-primary transition-colors hover:bg-primary/20"
                        >
                            View Gap Analysis →
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
