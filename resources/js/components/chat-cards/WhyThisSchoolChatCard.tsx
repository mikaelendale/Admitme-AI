import { Link } from '@inertiajs/react';
import { PencilSimple } from '@phosphor-icons/react';

interface WhyThisSchoolChatCardProps {
    schoolName: string;
    draftPreview: string;
    hooksUsed: string[];
    actionUrl: string;
}

export function WhyThisSchoolChatCard({
    schoolName,
    draftPreview,
    hooksUsed,
    actionUrl,
}: WhyThisSchoolChatCardProps) {
    const visibleHooks = hooksUsed.slice(0, 4);
    const extraHooks = hooksUsed.length - visibleHooks.length;

    return (
        <div className="mb-3 rounded-lg border border-border bg-card/60 p-3 text-xs shadow-sm">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-md border border-border/70 bg-muted/50">
                    <PencilSimple className="size-5 text-primary" weight="duotone" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-foreground">
                            Why {schoolName} — First Draft Ready
                        </span>
                    </div>

                    <div className="mt-2 rounded-md border border-border/50 bg-muted/20 px-2.5 py-2">
                        <p className="text-pretty italic leading-relaxed text-muted-foreground">
                            {draftPreview}
                        </p>
                    </div>

                    {visibleHooks.length > 0 && (
                        <div className="mt-2">
                            <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                Specific details referenced:
                            </p>
                            <div className="flex flex-wrap gap-1.5">
                                {visibleHooks.map((hook) => (
                                    <span
                                        key={hook}
                                        className="rounded-full border border-border/60 bg-muted/40 px-2 py-0.5 text-[11px] font-medium text-foreground"
                                    >
                                        {hook}
                                    </span>
                                ))}
                                {extraHooks > 0 && (
                                    <span className="rounded-full border border-border/60 bg-muted/40 px-2 py-0.5 text-[11px] text-muted-foreground">
                                        +{extraHooks} more
                                    </span>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="mt-2">
                        <Link
                            href={actionUrl}
                            className="inline-flex h-8 w-full items-center justify-center rounded-md border border-primary/30 bg-primary/10 px-3 text-xs font-medium text-primary transition-colors hover:bg-primary/20"
                        >
                            Open Draft in Essay Suite →
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
