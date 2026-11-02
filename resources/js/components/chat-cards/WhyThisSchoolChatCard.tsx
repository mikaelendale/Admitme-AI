import { Link } from '@inertiajs/react';
import { PencilSimple } from '@phosphor-icons/react';
import { Button } from '@/components/ui/button';

interface WhyThisSchoolChatCardProps {
    schoolName: string;
    draftPreview: string;
    hooksUsed: string[];
    actionUrl: string;
}

/** A Why-this-school first draft inside the mentor panel: a preview, the details it used, and a link to open it. */
export function WhyThisSchoolChatCard({
    schoolName,
    draftPreview,
    hooksUsed,
    actionUrl,
}: WhyThisSchoolChatCardProps) {
    const visibleHooks = hooksUsed.slice(0, 4);
    const extraHooks = hooksUsed.length - visibleHooks.length;

    return (
        <div className="mb-2 flex items-start gap-3 rounded-xl bg-muted p-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-violet-500 shadow-card">
                <PencilSimple className="size-4" weight="duotone" aria-hidden />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-[13.5px] font-medium text-foreground">
                    Why {schoolName}{' '}
                    <span className="font-normal text-muted-foreground">
                        · first draft ready
                    </span>
                </p>
                <p className="mt-1.5 line-clamp-5 border-l-2 border-foreground/15 pl-2.5 text-[12.5px] leading-relaxed text-pretty text-muted-foreground italic">
                    {draftPreview}
                </p>

                {visibleHooks.length > 0 ? (
                    <p className="mt-2 text-[12.5px] leading-snug">
                        <span className="text-muted-foreground">
                            Specific details referenced:{' '}
                        </span>
                        <span className="text-foreground/85">
                            {visibleHooks.join(' · ')}
                        </span>
                        {extraHooks > 0 ? (
                            <span className="text-muted-foreground">
                                {' '}
                                · +{extraHooks} more
                            </span>
                        ) : null}
                    </p>
                ) : null}

                <div className="mt-2.5">
                    <Button size="xs" variant="outline" asChild>
                        <Link href={actionUrl}>Open draft in Essay suite</Link>
                    </Button>
                </div>
            </div>
        </div>
    );
}
