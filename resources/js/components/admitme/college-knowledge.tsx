import {
    BookOpenText,
    ChatCircleText,
    Globe,
    MagnifyingGlass,
    Plus,
    SealCheck,
    Trash,
    UserCircle,
    WarningCircle,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { ErrorPill } from '@/components/admitme/profile-fields';
import { Callout } from '@/components/college/college-ui';
import { IconTile, Section } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';

type Requirement = {
    id: number;
    type: string;
    label: string;
    value: string;
    conditions: string | null;
    scope: string;
    program: string | null;
    cycle: string | null;
    cycleMatches: boolean;
    status: 'unverified' | 'verified' | 'conflict' | 'stale';
    quote: string;
    source: {
        url: string | null;
        title: string | null;
        type: string | null;
        private: boolean;
        claimedOfficial: boolean;
        checkedAt: string | null;
    };
};

type Source = {
    id: string;
    url: string;
    title: string | null;
    type: string;
    private: boolean;
    claimedOfficial: boolean;
    status: string;
    failureReason: string | null;
    checkedAt: string | null;
    stale: boolean;
};

type Evidence = {
    kind: string;
    url?: string | null;
    title?: string | null;
    quote?: string;
    cycle?: string | null;
    stale?: boolean;
    conflict?: boolean;
};

type Answer = {
    id: string;
    question: string;
    answer: string;
    claims: {
        text: string;
        citations: string[];
        status: 'supported' | 'unresolved' | 'conflicting';
        cycle_label: string | null;
    }[];
    unresolved: string[];
    evidence: Record<string, Evidence>;
    cycle: string | null;
    createdAt: string | null;
};

type Knowledge = {
    cycle: string | null;
    requirements: Requirement[];
    unresolved: string[];
    sources: Source[];
    answers: Answer[];
};

const STATUS_LABELS: Record<
    Requirement['status'],
    { label: string; tone: string }
> = {
    unverified: {
        label: 'From the source — not independently reviewed',
        tone: 'text-muted-foreground',
    },
    verified: {
        label: 'Reviewed',
        tone: 'text-emerald-700 dark:text-emerald-300',
    },
    conflict: { label: 'Sources disagree', tone: 'text-destructive' },
    stale: {
        label: 'Source may be out of date',
        tone: 'text-amber-700 dark:text-amber-300',
    },
};

const SOURCE_TYPES: Record<string, string> = {
    official: 'Official site',
    reference: 'Reference site',
};

/** Shared sources are labelled by kind; a page the student added is theirs alone and not verified. */
function sourceKind(source: {
    type: string | null;
    private: boolean;
    claimedOfficial: boolean;
}): string {
    if (source.private) {
        return source.claimedOfficial
            ? 'You marked this as the official site — not verified, only you see it'
            : 'Added by you — not verified, only you see it';
    }

    return SOURCE_TYPES[source.type ?? ''] ?? source.type ?? 'Source';
}

const CLAIM_DOTS: Record<string, string> = {
    supported: 'bg-emerald-500',
    unresolved: 'bg-amber-500',
    conflicting: 'bg-red-500',
};

// Knowledge loads in the browser after the page has hydrated, so local dates are safe here.
function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString() : 'never';
}

const fieldClass = 'h-10 rounded-xl bg-card text-[14px]';

/**
 * What AdmitMe knows about a college's requirements, where each fact came from, and what is unresolved —
 * then a place to ask about it. Renders two page sections.
 */
export function CollegeKnowledge({ schoolId }: { schoolId: string }) {
    const [data, setData] = useState<Knowledge | null>(null);
    const [url, setUrl] = useState('');
    const [official, setOfficial] = useState(false);
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState<'source' | 'ask' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [latest, setLatest] = useState<Answer | null>(null);
    // Which form the last error belongs to, so it shows next to that form.
    const [errorFor, setErrorFor] = useState<'source' | 'ask'>('source');

    const load = useCallback(async () => {
        const res = await fetch(`/college-lists/${schoolId}/knowledge`, {
            credentials: 'same-origin',
            headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
        });

        if (res.ok) {
            setData((await res.json()) as Knowledge);
        }
    }, [schoolId]);

    useEffect(() => {
        const timer = window.setTimeout(() => void load(), 0);

        return () => window.clearTimeout(timer);
    }, [load]);

    async function post(path: string, body: Record<string, unknown>) {
        const res = await fetch(path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
            body: JSON.stringify(body),
        });
        const json = (await res.json().catch(() => ({}))) as Record<
            string,
            unknown
        >;

        if (!res.ok) {
            const errors = json.errors as Record<string, string[]> | undefined;

            throw new Error(
                errors
                    ? Object.values(errors)[0]?.[0]
                    : String(json.message ?? 'Something went wrong.'),
            );
        }

        return json;
    }

    async function addSource() {
        setBusy('source');
        setError(null);

        try {
            await post(`/college-lists/${schoolId}/sources`, { url, official });
            setUrl('');
            setOfficial(false);
            await load();
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not add that source.',
            );
        } finally {
            setBusy(null);
        }
    }

    async function ask() {
        setBusy('ask');
        setError(null);

        try {
            const json = await post(`/college-lists/${schoolId}/research`, {
                question,
            });
            setLatest(json.answer as Answer);
            setQuestion('');
            await load();
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not answer that right now.',
            );
        } finally {
            setBusy(null);
        }
    }

    const askDisabled = busy !== null || question.trim().length < 5;
    const submitQuestion = (event: FormEvent) => {
        event.preventDefault();

        if (askDisabled) {
            return;
        }

        setErrorFor('ask');
        void ask();
    };
    const answer = latest ?? data?.answers[0] ?? null;

    return (
        <>
            <Section
                title="Requirements & sources"
                icon={BookOpenText}
                description={
                    data ? (
                        <>
                            Your cycle:{' '}
                            <span className="font-medium text-foreground">
                                {data.cycle ??
                                    'not set — add your target intake in Profile setup'}
                            </span>
                        </>
                    ) : undefined
                }
            >
                {!data ? (
                    <p className="text-[13.5px] text-muted-foreground">
                        Loading requirements…
                    </p>
                ) : (
                    <div className="space-y-5">
                        {data.unresolved.length > 0 ? (
                            <Callout
                                tone="warning"
                                icon={WarningCircle}
                                title="Unresolved — don't rely on these yet"
                            >
                                <ul className="list-disc space-y-0.5 pl-4">
                                    {data.unresolved.map((item) => (
                                        <li key={item}>{item}</li>
                                    ))}
                                </ul>
                            </Callout>
                        ) : null}

                        {data.requirements.length > 0 ? (
                            <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                {data.requirements.map((requirement) => (
                                    <li
                                        key={requirement.id}
                                        className="px-5 py-4"
                                    >
                                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                                            <p className="text-[14px] text-foreground">
                                                {requirement.label}
                                            </p>
                                            <p className="text-[14px] font-semibold text-foreground">
                                                {requirement.value}
                                            </p>
                                        </div>
                                        <p className="mt-1 text-[12.5px] leading-snug text-muted-foreground">
                                            <span
                                                className={
                                                    STATUS_LABELS[
                                                        requirement.status
                                                    ]?.tone
                                                }
                                            >
                                                {
                                                    STATUS_LABELS[
                                                        requirement.status
                                                    ]?.label
                                                }
                                            </span>
                                            {' · '}Cycle:{' '}
                                            {requirement.cycle ?? 'not stated'}
                                            {!requirement.cycleMatches
                                                ? ' (not your cycle)'
                                                : ''}
                                            {requirement.scope !== 'all'
                                                ? ` · ${requirement.scope} applicants`
                                                : ''}
                                            {requirement.program
                                                ? ` · ${requirement.program}`
                                                : ''}
                                            {requirement.conditions
                                                ? ` · ${requirement.conditions}`
                                                : ''}
                                        </p>
                                        <p className="mt-1.5 text-[12.5px] leading-snug text-muted-foreground">
                                            <span className="italic">
                                                “{requirement.quote}”
                                            </span>
                                            {requirement.source.url ? (
                                                <>
                                                    {' — '}
                                                    <a
                                                        href={
                                                            requirement.source
                                                                .url
                                                        }
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="break-all text-foreground/80 underline-offset-2 hover:text-foreground hover:underline"
                                                    >
                                                        {requirement.source
                                                            .title ??
                                                            requirement.source
                                                                .url}
                                                    </a>
                                                    {` · ${sourceKind(requirement.source)} · checked ${formatDate(requirement.source.checkedAt)}`}
                                                </>
                                            ) : null}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-[13.5px] text-muted-foreground">
                                No requirements on file yet. Add the
                                college&apos;s official admissions page below.
                            </p>
                        )}

                        <div>
                            <p className="mb-2 text-[13px] font-medium text-muted-foreground">
                                Sources ({data.sources.length})
                            </p>
                            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                                {data.sources.length > 0 ? (
                                    <ul className="divide-y divide-border border-b border-border">
                                        {data.sources.map((source) => (
                                            <li
                                                key={source.id}
                                                className="flex items-start gap-3 px-5 py-3.5"
                                            >
                                                <IconTile
                                                    icon={
                                                        source.private
                                                            ? UserCircle
                                                            : source.type ===
                                                                'official'
                                                              ? SealCheck
                                                              : Globe
                                                    }
                                                    tone={
                                                        source.private
                                                            ? 'slate'
                                                            : source.type ===
                                                                'official'
                                                              ? 'green'
                                                              : 'blue'
                                                    }
                                                    variant="glyph"
                                                    size="sm"
                                                    className="mt-0.5"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <a
                                                        href={source.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="block truncate text-[14px] font-medium text-foreground underline-offset-2 hover:underline"
                                                    >
                                                        {source.title ??
                                                            source.url}
                                                    </a>
                                                    <p className="mt-0.5 text-[12.5px] leading-snug text-muted-foreground">
                                                        {sourceKind(source)} ·
                                                        checked{' '}
                                                        {formatDate(
                                                            source.checkedAt,
                                                        )}
                                                        {source.stale ? (
                                                            <span className="text-amber-700 dark:text-amber-300">
                                                                {' '}
                                                                · may be out of
                                                                date
                                                            </span>
                                                        ) : null}
                                                        {source.status ===
                                                        'failed' ? (
                                                            <span className="text-destructive">
                                                                {' '}
                                                                ·{' '}
                                                                {
                                                                    source.failureReason
                                                                }
                                                            </span>
                                                        ) : null}
                                                    </p>
                                                </div>
                                                {source.private ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        className="-my-1 shrink-0 text-muted-foreground hover:text-destructive"
                                                        aria-label={`Delete your page ${source.title ?? source.url}`}
                                                        onClick={() =>
                                                            void fetch(
                                                                `/knowledge-sources/${source.id}`,
                                                                {
                                                                    method: 'DELETE',
                                                                    credentials:
                                                                        'same-origin',
                                                                    headers:
                                                                        buildLaravelJsonHeaders(
                                                                            readCsrfTokenFromMeta(),
                                                                        ),
                                                                },
                                                            ).then(() => load())
                                                        }
                                                    >
                                                        <Trash className="size-4" />
                                                    </Button>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                                <div className="space-y-3 bg-muted/30 px-5 py-4">
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <Input
                                            value={url}
                                            onChange={(event) =>
                                                setUrl(event.target.value)
                                            }
                                            placeholder="https://admissions.example.edu/apply"
                                            aria-label="Source link"
                                            className={fieldClass}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="h-10 gap-1.5 rounded-full px-4"
                                            disabled={
                                                busy !== null ||
                                                url.trim() === ''
                                            }
                                            onClick={() => {
                                                setErrorFor('source');
                                                void addSource();
                                            }}
                                        >
                                            <Plus
                                                className="size-4"
                                                aria-hidden
                                            />
                                            {busy === 'source'
                                                ? 'Reading page…'
                                                : 'Add source'}
                                        </Button>
                                    </div>
                                    <label className="flex items-center gap-2 text-[13px] text-foreground">
                                        <Checkbox
                                            checked={official}
                                            onCheckedChange={(checked) =>
                                                setOfficial(checked === true)
                                            }
                                            aria-label="This is the college's official website"
                                        />
                                        This is the college&apos;s official
                                        website
                                    </label>
                                    <p className="text-[12.5px] leading-snug text-muted-foreground">
                                        Pages on the college&apos;s verified
                                        site and known admissions references are
                                        shared. Other pages you add stay private
                                        to you and are marked as not verified.
                                    </p>
                                    {error && errorFor === 'source' ? (
                                        <ErrorPill message={error} />
                                    ) : null}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </Section>

            {data ? (
                <Section
                    title="Ask about this college"
                    icon={ChatCircleText}
                    description="Answers use only the sources on file and show where each point came from."
                >
                    <form
                        onSubmit={submitQuestion}
                        className="flex flex-col gap-2 sm:flex-row"
                    >
                        <Input
                            value={question}
                            onChange={(event) =>
                                setQuestion(event.target.value)
                            }
                            placeholder="e.g. When is the regular deadline and is there a fee?"
                            aria-label="Question about this college"
                            className={fieldClass}
                        />
                        <Button
                            type="submit"
                            className="h-10 gap-1.5 rounded-full px-4"
                            disabled={askDisabled}
                        >
                            <MagnifyingGlass className="size-4" aria-hidden />
                            {busy === 'ask' ? 'Researching…' : 'Ask'}
                        </Button>
                    </form>
                    {error && errorFor === 'ask' ? (
                        <ErrorPill className="mt-3" message={error} />
                    ) : null}
                    {answer ? <AnswerView answer={answer} /> : null}
                </Section>
            ) : null}
        </>
    );
}

function AnswerView({ answer }: { answer: Answer }) {
    return (
        <div className="mt-4 rounded-2xl border border-border bg-card p-5">
            <p className="text-[12.5px] text-muted-foreground">
                Q: {answer.question}
                {answer.cycle ? ` · cycle ${answer.cycle}` : ''}
            </p>
            <p className="mt-2 text-[14px] leading-relaxed whitespace-pre-line text-foreground">
                {answer.answer}
            </p>
            {answer.claims.length > 0 ? (
                <ul className="mt-4 space-y-2 border-t border-border pt-4">
                    {answer.claims.map((claim, index) => (
                        <li
                            key={index}
                            className="flex gap-2.5 text-[13px] leading-snug"
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'mt-[6px] size-1.5 shrink-0 rounded-full',
                                    CLAIM_DOTS[claim.status] ??
                                        'bg-foreground/30',
                                )}
                            />
                            <p className="min-w-0 text-foreground">
                                <span className="font-medium capitalize">
                                    {claim.status}:
                                </span>{' '}
                                {claim.text}
                                {claim.cycle_label
                                    ? ` (${claim.cycle_label})`
                                    : ''}
                                {claim.citations.map((label) => {
                                    const evidence = answer.evidence[label];

                                    return evidence?.url ? (
                                        <a
                                            key={label}
                                            href={evidence.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            title={evidence.quote}
                                            className="ml-1 text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                        >
                                            [{label}]
                                        </a>
                                    ) : (
                                        <span
                                            key={label}
                                            className="ml-1 text-muted-foreground"
                                        >
                                            [{label}]
                                        </span>
                                    );
                                })}
                            </p>
                        </li>
                    ))}
                </ul>
            ) : null}
            {answer.unresolved.length > 0 ? (
                <p className="mt-3 text-[12.5px] text-amber-700 dark:text-amber-300">
                    Not answered by the sources: {answer.unresolved.join(' · ')}
                </p>
            ) : null}
        </div>
    );
}
