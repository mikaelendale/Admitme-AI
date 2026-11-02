/**
 * Section heading. `small` is the quiet sentence-case label used on settings-style pages (the same look as
 * the page kit's Section title); `default` is a larger section title.
 */
export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    if (variant === 'small') {
        return (
            <header className="space-y-1">
                <h2 className="text-[13px] font-medium text-muted-foreground">
                    {title}
                </h2>
                {description ? (
                    <p className="text-[13.5px] leading-snug text-muted-foreground/90">
                        {description}
                    </p>
                ) : null}
            </header>
        );
    }

    return (
        <header className="mb-4 space-y-1">
            <h2 className="text-lg font-semibold tracking-tight text-foreground">
                {title}
            </h2>
            {description ? (
                <p className="text-sm leading-snug text-muted-foreground">
                    {description}
                </p>
            ) : null}
        </header>
    );
}
