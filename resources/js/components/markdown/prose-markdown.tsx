import type { Components } from 'react-markdown';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import type { ReactNode } from 'react';

function textFromChildren(children: ReactNode): string {
    if (children === null || children === undefined || typeof children === 'boolean') {
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

const proseComponents: Components = {
    h1: ({ children }) => (
        <h1 className="mb-6 text-2xl font-bold tracking-tight text-foreground first:mt-0 sm:text-3xl">
            {children}
        </h1>
    ),
    h2: ({ children }) => {
        const slug = slugifyHeading(textFromChildren(children));

        return (
            <div className="mb-6 mt-10 first:mt-0">
                <h2
                    id={slug || undefined}
                    className="mb-3 scroll-mt-20 text-xl font-bold text-foreground sm:text-2xl"
                >
                    {children}
                </h2>
                <hr className="border-border" />
            </div>
        );
    },
    h3: ({ children }) => (
        <h3 className="mb-3 mt-8 text-lg font-semibold text-foreground first:mt-0 sm:text-xl">
            {children}
        </h3>
    ),
    h4: ({ children }) => (
        <h4 className="mb-2 mt-6 text-base font-semibold text-foreground sm:text-lg">{children}</h4>
    ),
    p: ({ children }) => <p className="mb-4 leading-relaxed text-muted-foreground">{children}</p>,
    ul: ({ children }) => (
        <ul className="mb-6 list-disc space-y-2 pl-6 marker:text-primary">{children}</ul>
    ),
    ol: ({ children }) => (
        <ol className="mb-6 list-decimal space-y-2 pl-6 marker:font-medium marker:text-muted-foreground">
            {children}
        </ol>
    ),
    li: ({ children }) => (
        <li className="leading-relaxed text-muted-foreground [&>p]:mb-0 [&>ul]:mt-2 [&>ol]:mt-2">
            {children}
        </li>
    ),
    strong: ({ children }) => <strong className="font-semibold text-foreground">{children}</strong>,
    em: ({ children }) => <em className="italic text-muted-foreground">{children}</em>,
    code: ({ className, children, ...props }) => {
        const isBlock = typeof className === 'string' && className.includes('language-');

        if (isBlock) {
            return (
                <code
                    className={`block font-mono text-sm leading-relaxed text-foreground ${className ?? ''}`}
                    {...props}
                >
                    {children}
                </code>
            );
        }

        return (
            <code
                className="rounded bg-muted px-1.5 py-0.5 font-mono text-sm text-foreground"
                {...props}
            >
                {children}
            </code>
        );
    },
    pre: ({ children }) => (
        <pre className="mb-6 overflow-x-auto rounded-lg border border-border/60 bg-muted p-4 [&>code]:bg-transparent [&>code]:p-0">
            {children}
        </pre>
    ),
    a: ({ href, children }) => (
        <a
            href={href}
            className="text-primary underline-offset-4 hover:underline"
            target="_blank"
            rel="noopener noreferrer"
        >
            {children}
        </a>
    ),
    blockquote: ({ children }) => (
        <blockquote className="mb-6 border-l-4 border-primary pl-4 italic text-muted-foreground">
            {children}
        </blockquote>
    ),
    hr: () => <hr className="mb-6 border-border" />,
    table: ({ children }) => (
        <div className="mb-6 overflow-x-auto rounded-lg border border-border/60">
            <table className="w-full min-w-[16rem] border-collapse text-sm">{children}</table>
        </div>
    ),
    thead: ({ children }) => <thead className="border-b border-border bg-muted/50">{children}</thead>,
    tbody: ({ children }) => <tbody className="divide-y divide-border/60">{children}</tbody>,
    tr: ({ children }) => <tr>{children}</tr>,
    th: ({ children }) => (
        <th className="px-3 py-2 text-left font-semibold text-foreground">{children}</th>
    ),
    td: ({ children }) => <td className="px-3 py-2 text-muted-foreground">{children}</td>,
};

type Props = {
    content: string;
    className?: string;
};

/**
 * Renders markdown with typography tuned for assistant output and legal-style docs.
 * Uses GFM (tables, strikethrough, task lists) where applicable.
 */
export function ProseMarkdown({ content, className }: Props) {
    return (
        <div className={className}>
            <ReactMarkdown remarkPlugins={[remarkGfm]} components={proseComponents}>
                {content}
            </ReactMarkdown>
        </div>
    );
}
