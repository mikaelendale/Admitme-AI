import { Head, Link, router } from '@inertiajs/react';
import {
    CaretRight,
    ChatCircleText,
    ClockCounterClockwise,
    FloppyDisk,
    ShieldCheck,
    ShieldSlash,
    Trash,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Page, PageHeader, StatusBadge } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import { cn } from '@/lib/utils';

type Version = {
    id: string;
    number: number;
    body: string;
    words: number;
    author: string;
    createdAt: string | null;
};
type Comment = {
    quote: string;
    comment: string;
    kind: string;
    severity: 'high' | 'medium' | 'low';
    fix: string | null;
};
type Feedback = {
    id: string;
    versionNumber: number | null;
    policy: string;
    summary: string | null;
    comments: Comment[];
    droppedComments: number;
    createdAt: string | null;
};
type Piece = {
    id: string;
    title: string;
    kind: string;
    college: string | null;
    collegeId: string | null;
    prompt: string | null;
    wordLimit: number | null;
    policy: {
        value: string;
        label: string;
        description: string;
        allowsFeedback: boolean;
    };
    current: Version | null;
};

const SEVERITY: Record<
    Comment['severity'],
    { label: string; dot: string; text: string }
> = {
    high: { label: 'High', dot: 'bg-destructive', text: 'text-destructive' },
    medium: {
        label: 'Medium',
        dot: 'bg-amber-500',
        text: 'text-amber-700 dark:text-amber-300',
    },
    low: {
        label: 'Low',
        dot: 'bg-muted-foreground/50',
        text: 'text-muted-foreground',
    },
};

function countWords(text: string): number {
    const trimmed = text.trim();

    return trimmed === '' ? 0 : trimmed.split(/\s+/u).length;
}

/** The version text with the feedback's quoted excerpts marked, so every comment points at the student's own words. */
function Highlighted({ body, quotes }: { body: string; quotes: string[] }) {
    const parts = useMemo(() => {
        if (quotes.length === 0) {
            return [{ text: body, mark: false }];
        }

        const escaped = quotes
            .filter(Boolean)
            .map((q) =>
                q
                    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
                    .replace(/\s+/g, '\\s+'),
            );
        const pattern = new RegExp(`(${escaped.join('|')})`, 'giu');

        return body
            .split(pattern)
            .map((text, i) => ({ text, mark: i % 2 === 1 }));
    }, [body, quotes]);

    return (
        <p className="text-[14px] leading-[1.8] whitespace-pre-wrap text-foreground">
            {parts.map((part, i) =>
                part.mark ? (
                    <mark
                        key={i}
                        className="rounded bg-amber-300/40 px-0.5 text-foreground dark:bg-amber-400/25"
                    >
                        {part.text}
                    </mark>
                ) : (
                    <span key={i}>{part.text}</span>
                ),
            )}
        </p>
    );
}

/** A quiet sentence-case label for the side column, like the kit's Section title. */
function SideHeading({
    id,
    icon: IconComponent,
    children,
}: {
    id: string;
    icon: Icon;
    children: ReactNode;
}) {
    return (
        <h2
            id={id}
            className="flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground"
        >
            <IconComponent className="size-4" aria-hidden />
            {children}
        </h2>
    );
}

export default function WritingShow({
    piece,
    versions,
    feedback,
}: {
    piece: Piece;
    versions: Version[];
    feedback: Feedback[];
}) {
    const [body, setBody] = useState(piece.current?.body ?? '');
    const [saving, setSaving] = useState(false);
    const [asking, setAsking] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [viewing, setViewing] = useState<Version | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const hydrated = useHydrated();
    const words = countWords(body);
    const dirty = body !== (piece.current?.body ?? '');
    const over = piece.wordLimit !== null && words > piece.wordLimit;
    const latestFeedback = feedback[0] ?? null;
    const feedbackVersion = latestFeedback
        ? (versions.find((v) => v.number === latestFeedback.versionNumber) ??
          null)
        : null;
    // Whichever step comes next gets the dark button: save while there are changes, then ask for feedback.
    const feedbackIsNext =
        piece.policy.allowsFeedback && !!piece.current && !dirty;

    const save = () => {
        setSaving(true);
        setErrors({});
        router.post(
            `/writing/${piece.id}/versions`,
            { body, based_on: piece.current?.number ?? 0 },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setSaving(false),
            },
        );
    };

    const askForFeedback = () => {
        if (!piece.current) {
            return;
        }

        setAsking(true);
        setErrors({});
        router.post(
            `/writing/${piece.id}/feedback`,
            { version_id: piece.current.id },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setAsking(false),
            },
        );
    };

    const formatDate = (iso: string | null) =>
        hydrated && iso
            ? new Date(iso).toLocaleString(undefined, {
                  month: 'short',
                  day: 'numeric',
                  hour: 'numeric',
                  minute: '2-digit',
              })
            : '';

    return (
        <>
            <Head title={piece.title} />
            <Page width="wide">
                <PageHeader
                    eyebrow={
                        <span className="flex min-w-0 items-center gap-1.5">
                            <Link
                                href="/writing"
                                className="shrink-0 transition-colors hover:text-foreground"
                            >
                                Writing
                            </Link>
                            {piece.college ? (
                                <>
                                    <span aria-hidden>·</span>
                                    <span className="truncate">
                                        {piece.college}
                                    </span>
                                </>
                            ) : null}
                        </span>
                    }
                    title={piece.title}
                    description={
                        <span role="note">
                            <StatusBadge
                                tone={
                                    piece.policy.allowsFeedback
                                        ? 'neutral'
                                        : 'warning'
                                }
                                icon={
                                    piece.policy.allowsFeedback
                                        ? ShieldCheck
                                        : ShieldSlash
                                }
                                className="mr-2 align-[1px]"
                            >
                                {piece.policy.label}
                            </StatusBadge>
                            {piece.policy.description}
                        </span>
                    }
                />

                <div className="@container">
                    <div className="grid items-start gap-x-10 gap-y-10 @4xl:grid-cols-[minmax(0,1fr)_320px]">
                        <div className="min-w-0 space-y-3">
                            <div className="rounded-2xl border border-border bg-card transition-[border-color,box-shadow] has-[textarea:focus-visible]:border-foreground/25 has-[textarea:focus-visible]:ring-3 has-[textarea:focus-visible]:ring-ring/20">
                                {piece.prompt ? (
                                    <div className="rounded-t-[15px] border-b border-border bg-muted/40 px-5 py-4 sm:px-8">
                                        <p className="text-[12.5px] font-medium text-muted-foreground">
                                            Prompt
                                        </p>
                                        <p className="mt-1 text-[14px] leading-relaxed text-foreground/90">
                                            {piece.prompt}
                                        </p>
                                    </div>
                                ) : null}
                                <textarea
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    rows={18}
                                    aria-label="Your draft"
                                    className="block min-h-[22rem] w-full resize-y bg-transparent px-5 py-5 text-[16px] leading-[1.8] text-foreground outline-none placeholder:text-muted-foreground/70 sm:px-8 sm:py-7"
                                    placeholder="Write in your own words. Save whenever you like — every save is kept."
                                />
                                {/* Sticks to the bottom of the screen while the editor is taller than it, so saving is always in reach. */}
                                <div className="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded-b-[15px] border-t border-border bg-card px-3 py-2.5 sm:gap-x-3 sm:px-5 sm:py-3">
                                    <p className="nums min-w-0 basis-full text-[12.5px] text-muted-foreground sm:mr-auto sm:basis-auto sm:text-[13px]">
                                        <span
                                            className={cn(
                                                over &&
                                                    'font-semibold text-destructive',
                                            )}
                                            data-testid="word-count"
                                        >
                                            {words} word{words === 1 ? '' : 's'}
                                            {piece.wordLimit
                                                ? ` of ${piece.wordLimit}${over ? ` — ${words - piece.wordLimit} over` : ''}`
                                                : ''}
                                        </span>
                                        {dirty ? (
                                            <span> · Unsaved changes</span>
                                        ) : null}
                                    </p>
                                    <Button
                                        type="button"
                                        variant={
                                            feedbackIsNext
                                                ? 'outline'
                                                : 'default'
                                        }
                                        className="h-9 flex-auto gap-1.5 rounded-xl px-3 text-[13px] sm:flex-none sm:px-3.5 sm:text-sm"
                                        disabled={
                                            !dirty ||
                                            saving ||
                                            body.trim() === ''
                                        }
                                        onClick={save}
                                    >
                                        <FloppyDisk
                                            className="hidden size-4 sm:block"
                                            aria-hidden
                                        />
                                        {saving ? 'Saving…' : 'Save version'}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant={
                                            feedbackIsNext
                                                ? 'default'
                                                : 'outline'
                                        }
                                        className="h-9 flex-auto gap-1.5 rounded-xl px-3 text-[13px] sm:flex-none sm:px-3.5 sm:text-sm"
                                        disabled={
                                            !piece.policy.allowsFeedback ||
                                            !piece.current ||
                                            dirty ||
                                            asking
                                        }
                                        title={
                                            !piece.policy.allowsFeedback
                                                ? piece.policy.description
                                                : dirty
                                                  ? 'Save your changes first — feedback is always about a saved version.'
                                                  : undefined
                                        }
                                        onClick={askForFeedback}
                                    >
                                        <ChatCircleText
                                            className="hidden size-4 sm:block"
                                            aria-hidden
                                        />
                                        {asking
                                            ? 'Reading your draft…'
                                            : piece.current
                                              ? `Get feedback on version ${piece.current.number}`
                                              : 'Get feedback'}
                                    </Button>
                                </div>
                            </div>

                            {Object.values(errors).length > 0 ? (
                                <p role="alert" className="error-pill">
                                    <span>
                                        {Object.values(errors)[0]}{' '}
                                        {errors.expected_revision ? (
                                            <button
                                                type="button"
                                                className="font-semibold underline underline-offset-2"
                                                onClick={() => router.reload()}
                                            >
                                                Reload
                                            </button>
                                        ) : null}
                                    </span>
                                </p>
                            ) : null}
                        </div>

                        <div className="min-w-0 space-y-10">
                            {latestFeedback ? (
                                <section aria-labelledby="feedback-heading">
                                    <SideHeading
                                        id="feedback-heading"
                                        icon={ChatCircleText}
                                    >
                                        Feedback on version{' '}
                                        {latestFeedback.versionNumber}
                                    </SideHeading>
                                    <p className="mt-1 text-[12.5px] leading-snug text-muted-foreground">
                                        Comments point at your own words.
                                        AdmitMe never changes your text — revise
                                        it yourself and save a new version.
                                    </p>
                                    {latestFeedback.summary ? (
                                        <p className="mt-4 text-[14px] leading-relaxed text-foreground">
                                            {latestFeedback.summary}
                                        </p>
                                    ) : null}
                                    {latestFeedback.comments.length > 0 ? (
                                        <ol className="mt-4 space-y-2">
                                            {latestFeedback.comments.map(
                                                (comment, i) => {
                                                    const severity =
                                                        SEVERITY[
                                                            comment.severity
                                                        ] ?? SEVERITY.low;

                                                    return (
                                                        <li
                                                            key={i}
                                                            className="rounded-xl bg-muted/60 px-3.5 py-3"
                                                        >
                                                            <p className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
                                                                <span
                                                                    aria-hidden
                                                                    className={cn(
                                                                        'size-1.5 shrink-0 rounded-full',
                                                                        severity.dot,
                                                                    )}
                                                                />
                                                                <span
                                                                    className={cn(
                                                                        'font-medium',
                                                                        severity.text,
                                                                    )}
                                                                >
                                                                    {
                                                                        severity.label
                                                                    }
                                                                </span>
                                                                <span
                                                                    aria-hidden
                                                                >
                                                                    ·
                                                                </span>
                                                                <span>
                                                                    {comment.kind.replace(
                                                                        /_/g,
                                                                        ' ',
                                                                    )}
                                                                </span>
                                                            </p>
                                                            <p className="mt-2 border-l-2 border-amber-400/70 pl-2.5 text-[13px] leading-relaxed text-muted-foreground italic">
                                                                “{comment.quote}
                                                                ”
                                                            </p>
                                                            <p className="mt-2 text-[13.5px] leading-relaxed text-foreground">
                                                                {
                                                                    comment.comment
                                                                }
                                                            </p>
                                                            {comment.fix ? (
                                                                <p className="mt-1.5 text-[12.5px] leading-snug text-muted-foreground">
                                                                    Language fix
                                                                    you could
                                                                    make: “
                                                                    {
                                                                        comment.fix
                                                                    }
                                                                    ”
                                                                </p>
                                                            ) : null}
                                                        </li>
                                                    );
                                                },
                                            )}
                                        </ol>
                                    ) : null}
                                    {latestFeedback.droppedComments > 0 ? (
                                        <p className="mt-3 text-[12px] leading-snug text-muted-foreground">
                                            {latestFeedback.droppedComments}{' '}
                                            comment(s) were left out because
                                            they quoted words that are not in
                                            your draft.
                                        </p>
                                    ) : null}
                                    {feedbackVersion ? (
                                        <details className="group mt-4">
                                            <summary className="inline-flex cursor-pointer list-none items-center gap-1 rounded-md text-[13px] font-medium text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 [&::-webkit-details-marker]:hidden">
                                                <CaretRight
                                                    className="size-3.5 transition-transform group-open:rotate-90"
                                                    aria-hidden
                                                />
                                                Show the quotes in version{' '}
                                                {feedbackVersion.number}
                                            </summary>
                                            <div className="mt-2 max-h-80 overflow-auto rounded-xl border border-border bg-card p-4">
                                                <Highlighted
                                                    body={feedbackVersion.body}
                                                    quotes={latestFeedback.comments.map(
                                                        (c) => c.quote,
                                                    )}
                                                />
                                            </div>
                                        </details>
                                    ) : null}
                                </section>
                            ) : null}

                            <section aria-labelledby="versions-heading">
                                <SideHeading
                                    id="versions-heading"
                                    icon={ClockCounterClockwise}
                                >
                                    Versions
                                </SideHeading>
                                {versions.length === 0 ? (
                                    <p className="mt-2 text-[13.5px] text-muted-foreground">
                                        No saved versions yet.
                                    </p>
                                ) : (
                                    <ul className="mt-3 divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                        {versions.map((v) => {
                                            const open = viewing?.id === v.id;

                                            return (
                                                <li key={v.id}>
                                                    <button
                                                        type="button"
                                                        aria-expanded={open}
                                                        onClick={() =>
                                                            setViewing(
                                                                open ? null : v,
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex w-full items-center gap-3 px-4 py-3 text-left transition-colors outline-none hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-inset',
                                                            open &&
                                                                'bg-muted/60',
                                                        )}
                                                    >
                                                        <span className="min-w-0 flex-1">
                                                            <span className="nums block text-[14px] font-medium text-foreground">
                                                                Version{' '}
                                                                {v.number} ·{' '}
                                                                {v.words} words
                                                            </span>
                                                            <span className="nums block text-[12.5px] text-muted-foreground">
                                                                by you
                                                                {formatDate(
                                                                    v.createdAt,
                                                                )
                                                                    ? ` · ${formatDate(v.createdAt)}`
                                                                    : ''}
                                                            </span>
                                                        </span>
                                                        <CaretRight
                                                            className={cn(
                                                                'size-4 shrink-0 text-muted-foreground/60 transition-transform',
                                                                open &&
                                                                    'rotate-90',
                                                            )}
                                                            aria-hidden
                                                        />
                                                    </button>
                                                    {open ? (
                                                        <div className="border-t border-border bg-muted/30 px-4 py-3">
                                                            <p className="mb-1.5 text-[12px] text-muted-foreground">
                                                                Version{' '}
                                                                {v.number}{' '}
                                                                (read-only)
                                                            </p>
                                                            <p className="max-h-72 overflow-auto text-[13px] leading-relaxed whitespace-pre-wrap text-foreground">
                                                                {v.body}
                                                            </p>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                className="mt-3 rounded-lg"
                                                                onClick={() =>
                                                                    setBody(
                                                                        v.body,
                                                                    )
                                                                }
                                                            >
                                                                Copy into the
                                                                editor
                                                            </Button>
                                                        </div>
                                                    ) : null}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </section>

                            <div className="border-t border-border pt-5">
                                {confirmDelete ? (
                                    <div
                                        role="alert"
                                        className="rounded-xl bg-muted px-4 py-3 text-[13.5px] leading-relaxed text-foreground"
                                    >
                                        <p>
                                            Delete every version and its
                                            feedback (and exports that include
                                            it)?
                                        </p>
                                        <div className="mt-3 flex gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="destructive"
                                                className="rounded-lg"
                                                onClick={() =>
                                                    router.delete(
                                                        `/writing/${piece.id}`,
                                                    )
                                                }
                                            >
                                                Yes, delete
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="rounded-lg"
                                                onClick={() =>
                                                    setConfirmDelete(false)
                                                }
                                            >
                                                Keep it
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="-ml-2.5 gap-1.5 rounded-lg text-muted-foreground hover:text-destructive"
                                        onClick={() => setConfirmDelete(true)}
                                    >
                                        <Trash className="size-4" aria-hidden />
                                        Delete piece…
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </Page>
        </>
    );
}

WritingShow.layout = { breadcrumbs: [{ title: 'Writing', href: '/writing' }] };
