import type { ReactNode } from 'react';
import type { Components } from 'react-markdown';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

function textFromChildren(children: ReactNode): string {
    if (
        children === null ||
        children === undefined ||
        typeof children === 'boolean'
    ) {
        return '';
    }

    if (typeof children === 'string' || typeof children === 'number') {
        return String(children);
    }

    if (Array.isArray(children)) {
        return children.map(textFromChildren).join('');
    }

    if (typeof children === 'object' && 'props' in children) {
        const el = children as { props?: { children?: ReactNode } };

        return textFromChildren(el.props?.children ?? '');
    }

    return '';
}

function slugifyHeading(text: string): string {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/(^-|-$)/g, '');
}

function ExternalLinkIcon() {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 16 16"
            fill="currentColor"
            className="mb-0.5 ml-1 inline-block size-3 shrink-0 opacity-60"
            aria-hidden
        >
            <path d="M6.22 8.72a.75.75 0 0 0 1.06 1.06l5.22-5.22v1.69a.75.75 0 0 0 1.5 0v-3.5a.75.75 0 0 0-.75-.75h-3.5a.75.75 0 0 0 0 1.5h1.69L6.22 8.72Z" />
            <path d="M3.5 6.75c0-.69.56-1.25 1.25-1.25H7A.75.75 0 0 0 7 4H4.75A2.75 2.75 0 0 0 2 6.75v4.5A2.75 2.75 0 0 0 4.75 14h4.5A2.75 2.75 0 0 0 12 11.25V9a.75.75 0 0 0-1.5 0v2.25c0 .69-.56 1.25-1.25 1.25h-4.5c-.69 0-1.25-.56-1.25-1.25v-4.5Z" />
        </svg>
    );
}

const proseComponents: Components = {
    h1: ({ children }) => (
        <h1 className="mt-6 mb-4 text-xl font-bold tracking-tight text-foreground first:mt-0 sm:text-2xl">
            {children}
        </h1>
    ),
    h2: ({ children }) => {
        const slug = slugifyHeading(textFromChildren(children));

        return (
            <h2
                id={slug || undefined}
                className="mt-8 mb-3 scroll-mt-20 border-b border-border/40 pb-2 text-lg font-bold text-foreground first:mt-0 sm:text-xl"
            >
                {children}
            </h2>
        );
    },
    h3: ({ children }) => (
        <h3 className="mt-6 mb-2 text-base font-semibold text-foreground first:mt-0 sm:text-lg">
            {children}
        </h3>
    ),
    h4: ({ children }) => (
        <h4 className="mt-4 mb-1.5 text-sm font-semibold text-foreground sm:text-base">
            {children}
        </h4>
    ),
    p: ({ children }) => (
        <p className="mb-3 leading-relaxed text-foreground/90 last:mb-0">
            {children}
        </p>
    ),
    ul: ({ children }) => (
        <ul className="mb-4 list-disc space-y-1.5 pl-5 marker:text-primary/70">
            {children}
        </ul>
    ),
    ol: ({ children }) => (
        <ol className="mb-4 list-decimal space-y-1.5 pl-5 marker:font-medium marker:text-muted-foreground">
            {children}
        </ol>
    ),
    li: ({ children }) => (
        <li className="leading-relaxed text-foreground/90 [&>ol]:mt-1.5 [&>p]:mb-0 [&>ul]:mt-1.5">
            {children}
        </li>
    ),
    strong: ({ children }) => (
        <strong className="font-semibold text-foreground">{children}</strong>
    ),
    em: ({ children }) => (
        <em className="text-foreground/80 italic">{children}</em>
    ),
    code: ({ className, children, ...props }) => {
        const isBlock =
            typeof className === 'string' && className.includes('language-');

        if (isBlock) {
            return (
                <code
                    className={`block font-mono text-[13px] leading-relaxed text-foreground ${className ?? ''}`}
                    {...props}
                >
                    {children}
                </code>
            );
        }

        return (
            <code
                className="rounded-md bg-muted/80 px-1.5 py-0.5 font-mono text-[13px] text-foreground"
                {...props}
            >
                {children}
            </code>
        );
    },
    pre: ({ children }) => (
        <pre className="mb-4 overflow-x-auto rounded-lg border border-border/50 bg-muted/60 p-3 text-sm [&>code]:bg-transparent [&>code]:p-0">
            {children}
        </pre>
    ),
    a: ({ href, children }) => {
        const isExternal =
            href && (href.startsWith('http://') || href.startsWith('https://'));

        return (
            <a
                href={href}
                className="inline-flex items-baseline gap-0 font-medium text-primary underline decoration-primary/30 underline-offset-[3px] transition-colors hover:decoration-primary/80"
                target={isExternal ? '_blank' : undefined}
                rel={isExternal ? 'noopener noreferrer' : undefined}
            >
                {children}
                {isExternal && <ExternalLinkIcon />}
            </a>
        );
    },
    blockquote: ({ children }) => (
        <blockquote className="mb-4 rounded-r-lg border-l-3 border-primary/60 bg-primary/5 py-2 pr-3 pl-4 text-foreground/80 [&>p]:mb-1 [&>p:last-child]:mb-0">
            {children}
        </blockquote>
    ),
    hr: () => <hr className="my-5 border-border/40" />,
    table: ({ children }) => (
        <div className="mb-4 overflow-x-auto rounded-lg border border-border/50">
            <table className="w-full min-w-[16rem] border-collapse text-sm">
                {children}
            </table>
        </div>
    ),
    thead: ({ children }) => (
        <thead className="border-b border-border bg-muted/40">{children}</thead>
    ),
    tbody: ({ children }) => (
        <tbody className="divide-y divide-border/40">{children}</tbody>
    ),
    tr: ({ children }) => (
        <tr className="transition-colors hover:bg-muted/20">{children}</tr>
    ),
    th: ({ children }) => (
        <th className="px-3 py-2 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase">
            {children}
        </th>
    ),
    td: ({ children }) => (
        <td className="px-3 py-2 text-foreground/90">{children}</td>
    ),
};

type Props = {
    content: string;
    className?: string;
};

export function ProseMarkdown({ content, className }: Props) {
    return (
        <div className={className}>
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                components={proseComponents}
            >
                {content}
            </ReactMarkdown>
        </div>
    );
}
