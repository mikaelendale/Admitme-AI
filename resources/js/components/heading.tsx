export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-4 space-y-1'}>
            <h2
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-sm font-semibold text-foreground'
                        : 'text-xl font-semibold tracking-tight text-foreground'
                }
            >
                {title}
            </h2>
            {description && (
                <p className="text-sm leading-snug text-muted-foreground">
                    {description}
                </p>
            )}
        </header>
    );
}
