import { Link } from '@inertiajs/react';
import { ShieldWarning } from '@phosphor-icons/react';
import { Button } from '@/components/ui/button';

interface GapFixChatCardProps {
    schoolNames: string[];
    schoolCount: number;
    actionUrl: string;
    analysisId: string;
}

/** A finished gap analysis inside the mentor panel, with the schools it covered and a link to the results. */
export function GapFixChatCard({
    schoolNames,
    schoolCount,
    actionUrl,
}: GapFixChatCardProps) {
    const visibleSchools = schoolNames.slice(0, 4);
    const extraCount = schoolNames.length - visibleSchools.length;

    return (
        <div className="mb-2 flex items-start gap-3 rounded-xl bg-muted p-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-amber-500 shadow-card">
                <ShieldWarning
                    className="size-4"
                    weight="duotone"
                    aria-hidden
                />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-[13.5px] font-medium text-foreground">
                    Gap analysis ready{' '}
                    <span className="nums font-normal text-muted-foreground">
                        · {schoolCount}{' '}
                        {schoolCount === 1 ? 'school' : 'schools'}
                    </span>
                </p>
                <p className="mt-0.5 text-[12.5px] leading-snug text-pretty text-muted-foreground">
                    Your rejection risks and action plan are ready. See what's
                    holding you back and how to fix it.
                </p>
                {visibleSchools.length > 0 ? (
                    <p className="mt-2 text-[12.5px] leading-snug text-foreground/85">
                        {visibleSchools.join(' · ')}
                        {extraCount > 0 ? (
                            <span className="text-muted-foreground">
                                {' '}
                                · +{extraCount} more
                            </span>
                        ) : null}
                    </p>
                ) : null}
                <div className="mt-2.5">
                    <Button size="xs" variant="outline" asChild>
                        <Link href={actionUrl}>View gap analysis</Link>
                    </Button>
                </div>
            </div>
        </div>
    );
}
