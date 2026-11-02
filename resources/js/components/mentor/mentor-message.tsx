'use client';

import { Link } from '@inertiajs/react';
import {
    ArrowClockwise,
    CaretRight,
    Check,
    CircleNotch,
    Copy,
    FileText,
    MagnifyingGlass,
    WarningCircle,
    Wrench,
} from '@phosphor-icons/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ProposalCard } from '@/components/admitme/proposal-card';
import { TaskGoalCard } from '@/components/admitme/task-goal-card';
import { EssayRoastChatCard } from '@/components/chat-cards/EssayRoastChatCard';
import { GapFixChatCard } from '@/components/chat-cards/GapFixChatCard';
import { WhyThisSchoolChatCard } from '@/components/chat-cards/WhyThisSchoolChatCard';
import { ProseMarkdown } from '@/components/markdown/prose-markdown';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { buildLaravelJsonHeaders } from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import type { CouncilWsState } from './mentor-context';
import { isSubagent, subagentLook, toolCopy } from './mentor-tools';
import type {
    CardKind,
    ChatMessage,
    MessagePart,
    SubagentActivityEntry,
} from './mentor-types';

/** A file the student attached, shown as a small card above their message. */
function AttachmentCard({ name }: { name: string }) {
    const ext = name.split('.').pop()?.toUpperCase() ?? 'FILE';

    return (
        <div className="flex max-w-[85%] items-center gap-2.5 rounded-xl bg-muted px-3 py-2">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-muted-foreground shadow-card">
                <FileText className="size-4" weight="duotone" />
            </span>
            <span className="min-w-0">
                <span className="block truncate text-[13.5px] font-medium text-foreground">
                    {name}
                </span>
                <span className="block text-[12px] text-muted-foreground">
                    {ext} · from your documents
                </span>
            </span>
        </div>
    );
}

export function UserMessage({ message }: { message: ChatMessage }) {
    return (
        <div className="flex flex-col items-end gap-1.5">
            {message.attachments?.map((name) => (
                <AttachmentCard key={name} name={name} />
            ))}
            <p className="max-w-[88%] rounded-[20px] bg-foreground px-4 py-2.5 text-[14px] leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap text-background">
                {message.content}
            </p>
        </div>
    );
}

type ToolPart = Extract<MessagePart, { kind: 'tool' }>;

type Block =
    | { kind: 'text'; key: string; text: string }
    | { kind: 'tools'; key: string; items: ToolPart[] }
    | { kind: 'agent'; key: string; part: ToolPart }
    | {
          kind: 'proposal';
          key: string;
          id: string;
          data?: Extract<MessagePart, { kind: 'proposal' }>['data'];
      }
    | {
          kind: 'task';
          key: string;
          id: string;
          data?: Extract<MessagePart, { kind: 'task' }>['data'];
      }
    | { kind: 'card'; key: string; card: CardKind };

/** Consecutive tool calls read as one group of steps; everything else keeps its place. */
function toBlocks(parts: MessagePart[]): Block[] {
    const blocks: Block[] = [];

    parts.forEach((part, i) => {
        const last = blocks[blocks.length - 1];

        // A specialist gets its own card; it never folds into a run of tool rows.
        if (part.kind === 'tool' && isSubagent(part.name)) {
            blocks.push({ kind: 'agent', key: `agent-${part.id || i}`, part });

            return;
        }

        if (part.kind === 'tool') {
            if (last?.kind === 'tools') {
                last.items.push(part);
            } else {
                blocks.push({
                    kind: 'tools',
                    key: `tools-${part.id || i}`,
                    items: [part],
                });
            }

            return;
        }

        if (part.kind === 'text') {
            if (part.text.trim() !== '') {
                blocks.push({
                    kind: 'text',
                    key: `text-${i}`,
                    text: part.text.trim(),
                });
            }

            return;
        }

        if (part.kind === 'card') {
            blocks.push({
                kind: 'card',
                key: `card-${part.card}`,
                card: part.card,
            });

            return;
        }

        // Remount a card that first rendered without data once the data arrives.
        blocks.push({
            kind: part.kind,
            key: `${part.kind}-${part.id}-${part.data ? 'data' : 'id'}`,
            id: part.id,
            data: part.data,
        } as Block);
    });

    return blocks;
}

/** Repeats of the same tool in a row read as one row ("Searched the web ×3") with all their queries and finds. */
type ToolRowData = ToolPart & { count: number; details: string[] };

function mergeRepeats(items: ToolPart[]): ToolRowData[] {
    const rows: ToolRowData[] = [];

    for (const item of items) {
        const prev = rows[rows.length - 1];

        if (prev && prev.name === item.name) {
            prev.count += 1;
            prev.status =
                prev.status === 'running' || item.status === 'running'
                    ? 'running'
                    : prev.status === 'failed' || item.status === 'failed'
                      ? 'failed'
                      : 'done';
            prev.details = [
                ...new Set([
                    ...prev.details,
                    ...(item.detail ? [item.detail] : []),
                ]),
            ];
            prev.sources = [
                ...new Set([...(prev.sources ?? []), ...(item.sources ?? [])]),
            ].slice(0, 4);
        } else {
            rows.push({
                ...item,
                count: 1,
                details: item.detail ? [item.detail] : [],
                sources: item.sources ?? [],
            });
        }
    }

    return rows;
}

/** One tool the mentor used, in place between paragraphs: what it did, what it looked for and what it found. */
function ToolRow({ item }: { item: ToolRowData }) {
    const copy = toolCopy(item.name);
    const ToolIcon = copy.icon;
    const running = item.status === 'running';
    const failed = item.status === 'failed';

    return (
        <li className="flex min-h-7 items-start gap-2.5 text-[13px] leading-6 text-muted-foreground">
            <span
                className="flex h-6 w-4 shrink-0 items-center justify-center"
                aria-hidden
            >
                {running ? (
                    <CircleNotch className="size-3.5 animate-spin" />
                ) : failed ? (
                    <WarningCircle className="size-3.5 text-warning" />
                ) : (
                    <ToolIcon className="size-3.5" />
                )}
            </span>
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                <span className={cn('min-w-0', running && 'text-shimmer')}>
                    {running ? `${copy.running}…` : copy.done}
                    {item.count > 1 ? (
                        <span className="ml-1 text-[12px] text-muted-foreground/70">
                            ×{item.count}
                        </span>
                    ) : null}
                    {failed ? (
                        <span className="text-muted-foreground">
                            {' '}
                            — didn’t finish
                        </span>
                    ) : null}
                </span>
                {item.details.map((detail) => (
                    <span
                        key={detail}
                        title={detail}
                        className="max-w-full truncate text-[12.5px] text-muted-foreground/80"
                    >
                        “{detail}”
                    </span>
                ))}
                {item.sources?.map((source) => (
                    <span
                        key={source}
                        title={source}
                        className="max-w-[12rem] truncate rounded-md bg-muted px-1.5 text-[11.5px] leading-5 text-foreground/75"
                    >
                        {source}
                    </span>
                ))}
            </div>
        </li>
    );
}

/** A run of tool calls, shown where they ran. A long finished run folds into one line naming what it did. */
function ToolSteps({ items, live }: { items: ToolPart[]; live: boolean }) {
    const [open, setOpen] = useState(false);
    const rows = mergeRepeats(items);
    const long = !live && rows.length > 4;
    const failed = items.filter((item) => item.status === 'failed').length;

    if (long && !open) {
        const names = rows.map(
            (row) =>
                `${toolCopy(row.name).done}${row.count > 1 ? ` ×${row.count}` : ''}`,
        );

        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-expanded={false}
                className="group flex min-h-7 w-full items-start gap-2.5 text-left text-[13px] leading-6 text-muted-foreground transition-colors hover:text-foreground"
            >
                <span
                    className="flex h-6 w-4 shrink-0 justify-center pt-[5px]"
                    aria-hidden
                >
                    <Wrench className="size-3.5" />
                </span>
                <span className="min-w-0 flex-1">
                    Used {items.length} tools
                    {failed > 0 ? ` · ${failed} didn’t finish` : ''}
                    <span className="block truncate text-[12.5px] text-muted-foreground/80">
                        {names.join(' · ')}
                    </span>
                </span>
                <CaretRight
                    className="mt-1.5 size-3 shrink-0 transition-transform group-hover:translate-x-0.5"
                    aria-hidden
                />
            </button>
        );
    }

    return (
        <div>
            <ul aria-label="Steps" className="space-y-0.5">
                {rows.map((row) => (
                    <ToolRow key={row.id} item={row} />
                ))}
            </ul>
            {long ? (
                <button
                    type="button"
                    onClick={() => setOpen(false)}
                    aria-expanded
                    className="mt-0.5 ml-[26px] text-[12.5px] text-muted-foreground hover:text-foreground"
                >
                    Show less
                </button>
            ) : null}
        </div>
    );
}

/** The first line of a specialist's report, as plain text, for its folded card. */
function summaryLine(output: string): string | null {
    const line = output
        .split('\n')
        .map((l) =>
            l
                .trim()
                .replace(/^(#{1,6}\s+|>\s*|[-*+]\s+|\d+[.)]\s+)/, '')
                .replace(/[*_`]/g, '')
                .trim(),
        )
        .find((l) => l.length > 0 && !l.startsWith('|'));

    return line ? line.slice(0, 200) : null;
}

/**
 * A specialist the mentor handed a task to: who, what it was asked, and what it writes — live while it works,
 * folded to its first line once done (open to read the whole report).
 */
function SubagentCard({ part }: { part: ToolPart }) {
    const [open, setOpen] = useState(false);
    const look = subagentLook(part.name);
    const output = (part.output ?? '').trim();
    const running = part.status === 'running';
    const failed = part.status === 'failed';
    const AgentIcon = look?.icon ?? Wrench;
    const summary = !running && !failed ? summaryLine(output) : null;
    const activity = part.activity ?? [];
    const searches = activity.filter((entry) => entry.kind === 'search');
    const results = searches.reduce(
        (total, entry) => total + (entry.count ?? 0),
        0,
    );

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-background">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                disabled={running || output === ''}
                aria-expanded={running ? undefined : open}
                className="flex w-full items-start gap-2.5 px-3 py-2.5 text-left transition-colors enabled:hover:bg-muted/50"
            >
                <span
                    className={cn(
                        'mt-px flex size-6 shrink-0 items-center justify-center rounded-md',
                        look?.tile ?? 'bg-muted text-muted-foreground',
                    )}
                    aria-hidden
                >
                    <AgentIcon className="size-3.5" weight="bold" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-1.5 text-[13px] leading-6">
                        <span className="font-medium text-foreground">
                            {look?.label ?? part.name}
                        </span>
                        <span
                            className={cn(
                                'text-muted-foreground',
                                running && 'text-shimmer',
                            )}
                        >
                            {running
                                ? 'working…'
                                : failed
                                  ? 'didn’t finish'
                                  : 'done'}
                        </span>
                    </span>
                    {part.detail ? (
                        <span className="line-clamp-2 text-[12.5px] leading-snug text-muted-foreground">
                            {part.detail}
                        </span>
                    ) : null}
                    {summary && !open ? (
                        <span className="mt-1 line-clamp-2 block text-[12.5px] leading-snug text-foreground/80">
                            {summary}
                        </span>
                    ) : null}
                    {!running && searches.length > 0 && !open ? (
                        <span className="mt-1 block text-[12px] text-muted-foreground">
                            {searches.length}{' '}
                            {searches.length === 1 ? 'search' : 'searches'}
                            {results > 0 ? ` · ${results} results` : ''}
                        </span>
                    ) : null}
                </span>
                {running ? (
                    <CircleNotch
                        className="mt-1 size-3.5 shrink-0 animate-spin text-muted-foreground"
                        aria-hidden
                    />
                ) : output !== '' ? (
                    <CaretRight
                        className={cn(
                            'mt-1.5 size-3 shrink-0 text-muted-foreground transition-transform duration-150',
                            open && 'rotate-90',
                        )}
                        aria-hidden
                    />
                ) : null}
            </button>
            {activity.length > 0 && (running || open) ? (
                <ActivityList entries={activity} />
            ) : null}
            {running && output !== '' ? (
                // The latest lines stay in view while it writes; older ones fade out above.
                <div className="flex max-h-36 flex-col justify-end overflow-hidden border-t border-border [mask-image:linear-gradient(to_bottom,transparent,black_45%)] px-3 py-2">
                    <ProseMarkdown
                        content={output}
                        className="text-[12.5px] leading-relaxed text-muted-foreground"
                    />
                </div>
            ) : null}
            {!running && open && output !== '' ? (
                <div className="border-t border-border px-3 py-2.5">
                    <ProseMarkdown
                        content={output}
                        className="text-[13px] leading-relaxed text-foreground/90"
                    />
                </div>
            ) : null}
        </div>
    );
}

/** What a specialist did: each web search with what it found, newest last. */
function ActivityList({ entries }: { entries: SubagentActivityEntry[] }) {
    return (
        <ul
            aria-label="Specialist activity"
            className="space-y-1 border-t border-border px-3 py-2"
        >
            {entries.map((entry, index) => {
                const running = entry.status === 'running';

                return (
                    <li
                        key={index}
                        className="flex items-start gap-2 text-[12.5px] leading-5 text-muted-foreground"
                    >
                        <span
                            className="flex h-5 w-3.5 shrink-0 items-center justify-center"
                            aria-hidden
                        >
                            {running ? (
                                <CircleNotch className="size-3 animate-spin" />
                            ) : entry.kind === 'search' ? (
                                <MagnifyingGlass className="size-3" />
                            ) : (
                                <Wrench className="size-3" />
                            )}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className={cn(running && 'text-shimmer')}>
                                {entry.kind === 'search'
                                    ? entry.query
                                        ? `“${entry.query}”`
                                        : running
                                          ? 'Searching the web…'
                                          : 'Searched the web'
                                    : (entry.name ?? 'Used a tool')}
                            </span>
                            {entry.count ? (
                                <span className="text-muted-foreground/70">
                                    {' '}
                                    · {entry.count} results
                                </span>
                            ) : null}
                            {entry.sources && entry.sources.length > 0 ? (
                                <span className="mt-0.5 flex flex-wrap gap-1">
                                    {entry.sources.map((source) => (
                                        <span
                                            key={source}
                                            title={source}
                                            className="max-w-[12rem] truncate rounded-md bg-muted px-1.5 text-[11px] leading-5 text-foreground/75"
                                        >
                                            {source}
                                        </span>
                                    ))}
                                </span>
                            ) : null}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

/** Shown while the mentor works before (or between) visible output. */
function ThinkingRow({ waiting }: { waiting?: boolean }) {
    return (
        <div
            role="status"
            className="flex min-h-7 items-center gap-2.5 text-[13px]"
        >
            <span className="flex w-4 justify-center" aria-hidden>
                <span className="size-1.5 animate-pulse rounded-full bg-foreground/50" />
            </span>
            <span className="text-shimmer">
                {waiting ? 'Starting… this can take a moment' : 'Thinking…'}
            </span>
        </div>
    );
}

function IconAction({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={onClick}
                    aria-label={label}
                    className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{label}</TooltipContent>
        </Tooltip>
    );
}

export function AssistantMessage({
    message,
    live,
    waiting = false,
    onRetry,
    csrfToken,
    councilWsState,
    onCouncilReplySent,
    onOpenEssay,
}: {
    message: ChatMessage;
    live: boolean;
    /** The reply is queued and has not started yet. */
    waiting?: boolean;
    onRetry?: () => void;
    csrfToken?: string;
    councilWsState: CouncilWsState;
    onCouncilReplySent: () => void;
    onOpenEssay: () => void;
}) {
    const [copied, setCopied] = useState(false);
    const blocks = toBlocks(
        message.parts ??
            (message.content ? [{ kind: 'text', text: message.content }] : []),
    );
    const last = blocks[blocks.length - 1];
    // While streaming, say the mentor is working whenever nothing is visibly moving: before the first output,
    // and after a finished tool until the next paragraph starts.
    const working =
        live &&
        (last === undefined ||
            (last.kind === 'tools'
                ? last.items.every((item) => item.status !== 'running')
                : last.kind === 'agent'
                  ? last.part.status !== 'running'
                  : last.kind !== 'text'));

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(message.content);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        } catch {
            /* clipboard blocked */
        }
    };

    const card = (kind: CardKind): ReactNode => {
        switch (kind) {
            case 'council':
                return message.councilFeed ? (
                    <CouncilFeed
                        feed={message.councilFeed}
                        csrfToken={csrfToken}
                        wsState={councilWsState}
                        onReplySent={onCouncilReplySent}
                    />
                ) : null;
            case 'essay':
                return message.essayJob ? (
                    <EssayJobCard job={message.essayJob} onOpen={onOpenEssay} />
                ) : null;
            case 'gapFix':
                return message.gapFixData ? (
                    <GapFixChatCard {...message.gapFixData} />
                ) : null;
            case 'roast':
                return message.essayRoastData ? (
                    <EssayRoastChatCard {...message.essayRoastData} />
                ) : null;
            case 'whySchool':
                return message.whySchoolData ? (
                    <WhyThisSchoolChatCard {...message.whySchoolData} />
                ) : null;
        }
    };

    return (
        <div className="space-y-3">
            {blocks.map((block) => {
                switch (block.kind) {
                    case 'text':
                        return (
                            <ProseMarkdown
                                key={block.key}
                                content={block.text}
                                className="text-[14px] leading-relaxed text-foreground"
                            />
                        );
                    case 'agent':
                        return (
                            <SubagentCard key={block.key} part={block.part} />
                        );
                    case 'tools':
                        return (
                            <ToolSteps
                                key={block.key}
                                items={block.items}
                                live={live}
                            />
                        );
                    case 'proposal':
                        return (
                            <div key={block.key} className="animate-rise">
                                <ProposalCard
                                    proposalId={block.id}
                                    initial={block.data}
                                    variant="chat"
                                />
                            </div>
                        );
                    case 'task':
                        return (
                            <div key={block.key} className="animate-rise">
                                <TaskGoalCard
                                    goalId={block.id}
                                    initial={block.data}
                                />
                            </div>
                        );
                    case 'card':
                        return <div key={block.key}>{card(block.card)}</div>;
                }
            })}

            {working ? <ThinkingRow waiting={waiting} /> : null}

            {!live && message.content.length > 0 ? (
                <div className="-ml-1.5 flex items-center gap-0.5">
                    <IconAction
                        label={copied ? 'Copied' : 'Copy'}
                        onClick={() => void copy()}
                    >
                        {copied ? (
                            <Check className="size-4 text-success" />
                        ) : (
                            <Copy className="size-4" />
                        )}
                    </IconAction>
                    {onRetry ? (
                        <IconAction
                            label={message.streamError ? 'Retry' : 'Ask again'}
                            onClick={onRetry}
                        >
                            <ArrowClockwise className="size-4" />
                        </IconAction>
                    ) : null}
                    {message.streamError ? (
                        <span className="ml-1.5 inline-flex items-center gap-1 text-[12.5px] text-muted-foreground">
                            <WarningCircle className="size-3.5 text-warning" />
                            {message.streamError === 'quota'
                                ? 'Limit reached'
                                : message.streamError === 'timeout'
                                  ? 'Timed out'
                                  : 'Did not finish'}
                        </span>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

function EssayJobCard({
    job,
    onOpen,
}: {
    job: NonNullable<ChatMessage['essayJob']>;
    onOpen: () => void;
}) {
    const label = job.runType === 'draft' ? 'Essay draft' : 'Essay brainstorm';
    const status =
        job.status === 'completed'
            ? 'Ready'
            : job.status === 'failed'
              ? 'Failed'
              : 'Writing…';

    return (
        <div className="mb-2 flex items-start gap-3 rounded-xl bg-muted p-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-muted-foreground shadow-card">
                <FileText className="size-4" weight="duotone" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-[13.5px] font-medium text-foreground">
                    {label}{' '}
                    <span className="font-normal text-muted-foreground">
                        · {status}
                    </span>
                </p>
                <p className="mt-0.5 truncate text-[12.5px] text-muted-foreground">
                    {job.title ??
                        job.schoolName ??
                        'Runs in the background — this card updates.'}
                </p>
                {job.status === 'failed' && job.errorMessage ? (
                    <p className="mt-1 text-[12.5px] text-destructive">
                        {job.errorMessage}
                    </p>
                ) : null}
                <div className="mt-2 flex gap-1.5">
                    <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        onClick={onOpen}
                    >
                        Open
                    </Button>
                    <Button type="button" size="xs" variant="ghost" asChild>
                        <Link href="/essay-suite">Essay suite</Link>
                    </Button>
                </div>
            </div>
        </div>
    );
}

function CouncilFeed({
    feed,
    csrfToken,
    wsState,
    onReplySent,
}: {
    feed: NonNullable<ChatMessage['councilFeed']>;
    csrfToken?: string;
    wsState: CouncilWsState;
    onReplySent: () => void;
}) {
    const [reply, setReply] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async () => {
        if (!csrfToken || submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            const res = await fetch(
                `/college-lists/council-runs/${feed.runId}/preflight-reply`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: buildLaravelJsonHeaders(csrfToken),
                    body: JSON.stringify({ reply: reply.trim() }),
                },
            );
            let data: { message?: string } = {};

            try {
                data = (await res.json()) as { message?: string };
            } catch {
                /* ignore */
            }

            if (res.ok) {
                setReply('');
                onReplySent();
            } else {
                setError(data.message || 'Could not send reply — try again.');
            }
        } catch {
            setError('Network error — try again.');
        } finally {
            setSubmitting(false);
        }
    };

    const dot = (severity: string) =>
        severity === 'discarded'
            ? 'bg-destructive'
            : severity === 'debate'
              ? 'bg-warning'
              : severity === 'match'
                ? 'bg-success'
                : 'bg-foreground/40';

    return (
        <div className="mb-2 rounded-xl bg-muted p-3 text-[13px]">
            <div className="mb-2 flex items-center justify-between gap-2">
                <span className="flex items-center gap-2 font-medium text-foreground">
                    College list council
                    <span
                        className={cn(
                            'inline-block size-1.5 rounded-full',
                            wsState === 'connected'
                                ? 'bg-success'
                                : wsState === 'connecting'
                                  ? 'bg-warning'
                                  : 'bg-muted-foreground/50',
                        )}
                        title={
                            wsState === 'connected'
                                ? 'Live updates connected'
                                : wsState === 'connecting'
                                  ? 'Connecting…'
                                  : 'Checking every 5s'
                        }
                    />
                </span>
                <Link
                    href={`/college-lists?run=${feed.runId}`}
                    className="text-[12.5px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                >
                    Timeline
                </Link>
            </div>
            {feed.lines.length > 0 ? (
                <ul className="max-h-44 scrollbar-thin space-y-2 overflow-y-auto pr-1">
                    {feed.lines.map((line) => (
                        <li key={line.id} className="flex gap-2">
                            <span
                                className={cn(
                                    'mt-1.5 size-1.5 shrink-0 rounded-full',
                                    dot(line.severity ?? 'info'),
                                )}
                            />
                            <div className="min-w-0">
                                <div className="text-foreground">
                                    {line.title}
                                </div>
                                {line.body ? (
                                    <div className="text-pretty text-muted-foreground">
                                        {line.body}
                                    </div>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground">
                    Waiting for council updates…
                </p>
            )}
            {feed.status === 'awaiting_preflight' ? (
                <div className="mt-2.5 space-y-2 border-t border-border pt-2.5">
                    <p className="text-[12.5px] font-medium text-foreground">
                        Optional clarifications
                    </p>
                    {(feed.preflightQuestions?.length ?? 0) > 0 ? (
                        <ol className="list-decimal space-y-1 pl-4 text-muted-foreground">
                            {feed.preflightQuestions!.map((q, i) => (
                                <li key={i}>{q}</li>
                            ))}
                        </ol>
                    ) : (
                        <p className="text-muted-foreground">
                            Loading questions…
                        </p>
                    )}
                    <p className="text-[12px] text-muted-foreground">
                        The build continues on its own after about{' '}
                        {feed.preflightTimeoutSeconds ?? 120}s if you skip.
                        Answers go only to this run.
                    </p>
                    <textarea
                        value={reply}
                        onChange={(e) => {
                            setReply(e.target.value);
                            setError(null);
                        }}
                        aria-label="Answers for the council"
                        placeholder="Type answers here (optional)…"
                        rows={3}
                        disabled={submitting || !csrfToken}
                        className="w-full resize-none rounded-lg border border-input bg-background px-2.5 py-2 text-[13px] text-foreground outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                    />
                    {error ? <p className="error-pill">{error}</p> : null}
                    <Button
                        type="button"
                        size="xs"
                        disabled={submitting || !csrfToken}
                        onClick={() => void submit()}
                    >
                        {submitting ? 'Sending…' : 'Send answers and continue'}
                    </Button>
                </div>
            ) : null}
            {feed.status === 'running' ? (
                <p className="mt-2 text-muted-foreground">
                    Running in the background…
                </p>
            ) : null}
            {feed.status === 'completed' ? (
                <p className="mt-2 text-success">
                    Council finished — your list was updated.
                </p>
            ) : null}
            {feed.status === 'failed' ? (
                <p className="mt-2 text-destructive">
                    {feed.errorMessage?.trim() ||
                        'Council run failed. Try again from chat.'}
                </p>
            ) : null}
        </div>
    );
}
