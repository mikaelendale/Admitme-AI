'use client';

import { Link, usePage } from '@inertiajs/react';
import {
    Chats,
    Check,
    CircleNotch,
    Copy,
    DotsThreeVertical,
    Download,
    FileText,
    PlusCircle,
    ShareNetwork,
    Sparkle,
    X,
} from '@phosphor-icons/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { PromptInputBox } from '@/components/ai-prompt-box';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import { GapFixChatCard } from '@/components/chat-cards/GapFixChatCard';
import { EssayRoastChatCard } from '@/components/chat-cards/EssayRoastChatCard';
import { WhyThisSchoolChatCard } from '@/components/chat-cards/WhyThisSchoolChatCard';
import { ProseMarkdown } from '@/components/markdown/prose-markdown';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSetAppLayoutHeaderTrailing } from '@/layouts/app-layout';
import {
    consumeVercelAiStream,
    streamEventToStep,
    toolCallIdFromOutput,
    type VercelAiStreamPart,
} from '@/lib/consumeVercelAiStream';
import {
    createLaravelEcho,
    type CouncilBroadcastPayload,
    type SharedReverbConfig,
} from '@/lib/laravelEcho';
import { buildLaravelJsonHeaders } from '@/lib/laravelFetch';
import type { CouncilFeedLine } from '@/types/council';
import type { FeatureQuotasProps, QuotaFeatureDetail } from '@/types/feature-quotas';
import { cn } from '@/lib/utils';
import { dashboard, essaySuite } from '@/routes';
import { show as dashboardChatShow } from '@/routes/dashboard/chat';
import { index as dashboardChatsIndex } from '@/routes/dashboard/chats';

export interface StreamActivityItem {
    id: string;
    text: string;
    icon?: string;
    status?: 'running' | 'done';
}

export interface ChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    /** Set when the stream failed or timed out so the UI can offer Retry */
    streamError?: 'timeout' | 'failed' | 'quota';
    activity?: StreamActivityItem[];
    councilFeed?: {
        runId: string;
        status: 'running' | 'awaiting_preflight' | 'completed' | 'failed';
        lines: CouncilFeedLine[];
        preflightQuestions?: string[];
        preflightTimeoutSeconds?: number;
        /** Server error message when status is failed */
        errorMessage?: string | null;
    };
    essayJob?: {
        runId: string;
        runType: 'brainstorm' | 'draft';
        status: 'running' | 'completed' | 'failed';
        title: string | null;
        schoolName: string | null;
        outputMarkdown: string | null;
        errorMessage: string | null;
    };
    gapFixData?: {
        schoolNames: string[];
        schoolCount: number;
        actionUrl: string;
        analysisId: string;
    } | null;
    essayRoastData?: {
        overallVerdict: string;
        scores: Record<string, { score: number; reasoning: string }>;
        clicheCount: number;
        topPriorityFix: string;
        actionUrl: string;
    } | null;
    whySchoolData?: {
        schoolName: string;
        draftPreview: string;
        hooksUsed: string[];
        actionUrl: string;
    } | null;
}

type CouncilWsState = 'connecting' | 'connected' | 'disconnected';

function CouncilFeedPanel({
    feed,
    csrfToken,
    onPreflightReplySent,
    wsState,
}: {
    feed: NonNullable<ChatMessage['councilFeed']>;
    csrfToken?: string;
    onPreflightReplySent?: () => void;
    /** Live WebSocket connection to Reverb (council updates). */
    wsState?: CouncilWsState;
}) {
    const sevDot = (s: string) => {
        if (s === 'discarded') return 'bg-destructive';
        if (s === 'debate') return 'bg-chart-4';
        if (s === 'match') return 'bg-chart-2';

        return 'bg-primary';
    };

    const [replyDraft, setReplyDraft] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [replyError, setReplyError] = useState<string | null>(null);

    const submitPreflightReply = async () => {
        if (!csrfToken || submitting) {
            return;
        }
        setSubmitting(true);
        setReplyError(null);
        try {
            const res = await fetch(`/college-lists/council-runs/${feed.runId}/preflight-reply`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(csrfToken),
                body: JSON.stringify({ reply: replyDraft.trim() }),
            });
            let data: { message?: string } = {};
            try {
                data = (await res.json()) as { message?: string };
            } catch {
                /* ignore */
            }
            if (res.ok) {
                onPreflightReplySent?.();
                setReplyDraft('');
            } else {
                setReplyError(
                    typeof data.message === 'string' && data.message !== ''
                        ? data.message
                        : 'Could not send reply — try again.',
                );
            }
        } catch {
            setReplyError('Network error — try again.');
        } finally {
            setSubmitting(false);
        }
    };

    const wsTitle =
        wsState === 'connected'
            ? 'Live updates connected'
            : wsState === 'connecting'
              ? 'Connecting for live updates…'
              : 'Live updates paused — polling every 5s';

    const wsDotClass =
        wsState === 'connected'
            ? 'bg-emerald-500'
            : wsState === 'connecting'
              ? 'bg-amber-500'
              : 'bg-muted-foreground/50';

    return (
        <div className="mb-3 rounded-lg border border-border bg-muted/30 p-3 text-xs">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-foreground">College list council</span>
                    {wsState !== undefined ? (
                        <span
                            className="inline-flex items-center gap-1.5 text-[10px] text-muted-foreground"
                            title={wsTitle}
                        >
                            <span
                                className={`inline-block size-2 shrink-0 rounded-full ${wsDotClass}`}
                                aria-hidden
                            />
                            <span className="sr-only">{wsTitle}</span>
                        </span>
                    ) : null}
                </div>
                <Link href={`/college-lists?run=${feed.runId}`} className="shrink-0 text-primary hover:underline">
                    Open timeline
                </Link>
            </div>
            {feed.lines.length > 0 ? (
                <ul className="max-h-44 space-y-2 overflow-y-auto pr-1">
                    {feed.lines.map((line) => (
                        <li key={line.id} className="flex gap-2">
                            <span
                                className={cn('mt-1 h-2 w-2 shrink-0 rounded-full', sevDot(line.severity ?? 'info'))}
                            />
                            <div className="min-w-0">
                                <div className="font-medium text-foreground">{line.title}</div>
                                {line.body ? (
                                    <div className="text-pretty text-muted-foreground">{line.body}</div>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground">Waiting for council updates…</p>
            )}
            {feed.status === 'awaiting_preflight' ? (
                <div className="mt-2 space-y-2 rounded-md border border-amber-500/30 bg-amber-500/5 p-2 dark:border-amber-500/25 dark:bg-amber-500/10">
                    <p className="text-[11px] font-semibold text-foreground">Optional clarifications</p>
                    {(feed.preflightQuestions?.length ?? 0) > 0 ? (
                        <ol className="list-decimal space-y-1 pl-4 text-[11px] text-muted-foreground">
                            {feed.preflightQuestions!.map((q, i) => (
                                <li key={i} className="pl-0.5">
                                    {q}
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p className="text-[11px] text-muted-foreground">Loading questions…</p>
                    )}
                    <p className="text-[10px] leading-snug text-muted-foreground">
                        The build continues automatically after about {feed.preflightTimeoutSeconds ?? 120}s if you
                        skip. Your answers are sent only to this council run (not as a chat message).
                    </p>
                    <textarea
                        value={replyDraft}
                        onChange={(e) => {
                            setReplyDraft(e.target.value);
                            if (replyError) {
                                setReplyError(null);
                            }
                        }}
                        placeholder="Type answers here (optional)…"
                        rows={3}
                        disabled={submitting || !csrfToken}
                        className="w-full resize-none rounded-md border border-border/80 bg-background px-2 py-1.5 text-[11px] text-foreground outline-none placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring"
                    />
                    {replyError ? (
                        <p className="text-[11px] text-destructive">{replyError}</p>
                    ) : null}
                    <Button
                        type="button"
                        size="sm"
                        className="h-8 text-xs"
                        disabled={submitting || !csrfToken}
                        onClick={() => void submitPreflightReply()}
                    >
                        {submitting ? 'Sending…' : 'Send answers and continue'}
                    </Button>
                </div>
            ) : null}
            {feed.status === 'running' ? (
                <p className="mt-2 text-muted-foreground">Running in the background…</p>
            ) : null}
            {feed.status === 'completed' ? (
                <p className="mt-2 text-chart-2">Council finished — your list was updated.</p>
            ) : null}
            {feed.status === 'failed' ? (
                <p className="mt-2 text-destructive">
                    {feed.errorMessage?.trim()
                        ? feed.errorMessage
                        : 'Council run failed. Try again from chat.'}
                </p>
            ) : null}
            {wsState === 'disconnected' ? (
                <p className="mt-2 text-[11px] text-muted-foreground">
                    Live updates paused — polling every 5s.
                </p>
            ) : null}
        </div>
    );
}

/** Collapse consecutive identical labels into one row with a "×N" count. */
function collapseActivityItems(items: StreamActivityItem[]): Array<StreamActivityItem & { count: number }> {
    const out: Array<StreamActivityItem & { count: number }> = [];
    for (const item of items) {
        const prev = out[out.length - 1];
        if (prev && prev.text === item.text && prev.icon === item.icon) {
            prev.count += 1;
            if (item.status === 'running') prev.status = 'running';
        } else {
            out.push({ ...item, count: 1 });
        }
    }
    return out;
}

/** Step icon shapes */
function AgentStepIcon({ icon, status }: { icon: string; status: 'running' | 'done' }) {
    if (status === 'running') {
        return <CircleNotch size={13} className="animate-spin text-primary" />;
    }
    const cls = {
        profile: 'text-blue-500',
        list: 'text-emerald-500',
        essay: 'text-violet-500',
        gap: 'text-amber-500',
        roast: 'text-red-500',
        council: 'text-indigo-500',
        search: 'text-cyan-500',
        default: 'text-primary',
    }[icon] ?? 'text-primary';
    return <Check size={13} weight="bold" className={cn(cls)} />;
}

function AgentTimeline({
    items,
    elapsedSeconds,
    isLive,
    totalElapsed,
}: {
    items: StreamActivityItem[];
    elapsedSeconds?: number;
    isLive?: boolean;
    /** Final elapsed time shown after completion */
    totalElapsed?: number;
}) {
    const [expanded, setExpanded] = useState(true);

    if (items.length === 0) return null;

    const collapsed = collapseActivityItems(items);
    const lastIdx = collapsed.length - 1;

    const headerLabel = isLive
        ? `Working · ${elapsedSeconds ?? 0}s`
        : totalElapsed !== undefined && totalElapsed > 0
          ? `Done · ${totalElapsed}s`
          : `Done`;

    return (
        <div className="mb-3">
            {/* "Thought for Xs" header row — click to expand/collapse */}
            <button
                type="button"
                onClick={() => setExpanded((v) => !v)}
                className="flex w-full items-center gap-2 rounded-md px-1 py-0.5 text-left text-xs text-muted-foreground transition-colors hover:text-foreground"
            >
                <Sparkle
                    size={13}
                    weight="fill"
                    className={cn('shrink-0', isLive ? 'animate-pulse text-primary' : 'text-muted-foreground')}
                />
                <span className={cn('font-medium', isLive && 'text-foreground')}>{headerLabel}</span>
                <span className={cn('ml-auto transition-transform duration-200', expanded ? 'rotate-0' : '-rotate-90')}>
                    ▾
                </span>
            </button>

            {/* Vertical steps list */}
            {expanded && (
                <div className="ml-1.5 mt-1 border-l border-border/50 pl-4">
                    {collapsed.map((step, i) => {
                        const isLast = i === lastIdx;
                        const status = step.status ?? (isLive && isLast ? 'running' : 'done');
                        return (
                            <div key={step.id} className="flex items-center gap-2 py-[3px]">
                                <AgentStepIcon icon={step.icon ?? 'default'} status={status} />
                                <span className={cn(
                                    'text-xs',
                                    status === 'running'
                                        ? 'font-medium text-foreground'
                                        : 'text-muted-foreground',
                                )}>
                                    {step.text}
                                    {step.count > 1 && (
                                        <span className="ml-1.5 text-[10px] text-muted-foreground/60">
                                            ×{step.count}
                                        </span>
                                    )}
                                    {isLast && isLive && elapsedSeconds !== undefined && elapsedSeconds > 0
                                        ? <span className="ml-1.5 tabular-nums text-[10px] text-muted-foreground/60">{elapsedSeconds}s</span>
                                        : null
                                    }
                                </span>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export type DashboardMentorChatEmptyHelpers = {
    send: (text: string) => void;
    isTyping: boolean;
};

/** Serialized from {@see MentorChatConversation::messagesForDisplay} (snake_case keys). */
export type DashboardChatInitialMessage = {
    id: string;
    role: string;
    content: string;
    activity?: { id: string; text: string; icon?: string; status?: string }[];
    council_feed?: { run_id: string; status: string };
    essay_job?: { run_id: string; run_type: 'brainstorm' | 'draft'; status: string };
    gap_fix_data?: { school_names: string[]; school_count: number; action_url: string; analysis_id: string };
    essay_roast_data?: { overall_verdict: string; scores: Record<string, { score: number; reasoning: string }>; cliche_count: number; top_priority_fix: string; action_url: string };
    why_school_data?: { school_name: string; draft_preview: string; hooks_used: string[]; action_url: string };
};

type Props = {
    csrfToken?: string;
    initialConversationId?: string | null;
    initialMessages?: DashboardChatInitialMessage[];
    /** When set, replaces the default empty prompt-only screen (e.g. full dashboard hub + prompt). */
    renderEmpty?: (helpers: DashboardMentorChatEmptyHelpers) => ReactNode;
};

/** Stable default — inline `[]` in default params is a new reference every render and breaks useMemo/useEffect. */
const EMPTY_INITIAL_MESSAGES: DashboardChatInitialMessage[] = [];

function normalizeCouncilFeedStatusFromServer(
    s: string,
): NonNullable<ChatMessage['councilFeed']>['status'] {
    if (s === 'awaiting_preflight' || s === 'completed' || s === 'failed') {
        return s;
    }

    return 'running';
}

function normalizeEssayJobStatusFromServer(s: string): NonNullable<ChatMessage['essayJob']>['status'] {
    if (s === 'completed' || s === 'failed') {
        return s;
    }

    return 'running';
}

function tryStartCouncilFromToolEvent(
    evt: VercelAiStreamPart,
    assistantId: string,
    onStart: (assistantMessageId: string, runId: string) => void,
): void {
    if (evt.type !== 'tool-output-available') {
        return;
    }
    const raw = evt.output;
    const str = typeof raw === 'string' ? raw : JSON.stringify(raw ?? '');
    try {
        const parsed = JSON.parse(str) as {
            ok?: boolean;
            run_id?: string;
            council_started?: boolean;
        };
        if (
            parsed.ok === true &&
            parsed.council_started === true &&
            typeof parsed.run_id === 'string' &&
            parsed.run_id.length > 0
        ) {
            onStart(assistantId, parsed.run_id);
        }
    } catch {
        /* not JSON */
    }
}

function tryStartEssayFromToolEvent(
    evt: VercelAiStreamPart,
    assistantId: string,
    onStart: (assistantMessageId: string, runId: string, runType: 'brainstorm' | 'draft') => void,
): void {
    if (evt.type !== 'tool-output-available') {
        return;
    }
    const raw = evt.output;
    const str = typeof raw === 'string' ? raw : JSON.stringify(raw ?? '');
    try {
        const parsed = JSON.parse(str) as {
            ok?: boolean;
            essay_job_started?: boolean;
            run_id?: string;
            run_type?: string;
        };
        if (
            parsed.ok === true &&
            parsed.essay_job_started === true &&
            typeof parsed.run_id === 'string' &&
            parsed.run_id.length > 0
        ) {
            const rt = parsed.run_type === 'draft' ? 'draft' : 'brainstorm';
            onStart(assistantId, parsed.run_id, rt);
        }
    } catch {
        /* not JSON */
    }
}

function tryStartGapFixFromToolEvent(
    evt: VercelAiStreamPart,
    assistantId: string,
    attachGapFix: (id: string, data: NonNullable<ChatMessage['gapFixData']>) => void,
): void {
    if (evt.type !== 'tool-output-available') return;
    const raw = evt.output;
    const str = typeof raw === 'string' ? raw : JSON.stringify(raw ?? '');
    try {
        const out = JSON.parse(str) as Record<string, unknown>;
        if (!out?.gap_fix_started) return;
        attachGapFix(assistantId, {
            schoolNames: (Array.isArray(out.school_names) ? out.school_names : []) as string[],
            schoolCount: (typeof out.school_count === 'number' ? out.school_count : 0),
            actionUrl: (typeof out.action_url === 'string' ? out.action_url : '/gap-fix'),
            analysisId: (typeof out.analysis_id === 'string' ? out.analysis_id : ''),
        });
    } catch { /* not JSON */ }
}

function tryStartEssayRoastFromToolEvent(
    evt: VercelAiStreamPart,
    assistantId: string,
    attachRoast: (id: string, data: NonNullable<ChatMessage['essayRoastData']>) => void,
): void {
    if (evt.type !== 'tool-output-available') return;
    const raw = evt.output;
    const str = typeof raw === 'string' ? raw : JSON.stringify(raw ?? '');
    try {
        const out = JSON.parse(str) as Record<string, unknown>;
        if (!out?.roast_ready) return;
        attachRoast(assistantId, {
            overallVerdict: (typeof out.overall_verdict === 'string' ? out.overall_verdict : ''),
            scores: (out.scores && typeof out.scores === 'object' ? out.scores : {}) as Record<string, { score: number; reasoning: string }>,
            clicheCount: (typeof out.cliche_count === 'number' ? out.cliche_count : 0),
            topPriorityFix: (typeof out.top_priority_fix === 'string' ? out.top_priority_fix : ''),
            actionUrl: (typeof out.action_url === 'string' ? out.action_url : '/essay-suite'),
        });
    } catch { /* not JSON */ }
}

function tryStartWhySchoolFromToolEvent(
    evt: VercelAiStreamPart,
    assistantId: string,
    attachWhySchool: (id: string, data: NonNullable<ChatMessage['whySchoolData']>) => void,
): void {
    if (evt.type !== 'tool-output-available') return;
    const raw = evt.output;
    const str = typeof raw === 'string' ? raw : JSON.stringify(raw ?? '');
    try {
        const out = JSON.parse(str) as Record<string, unknown>;
        if (!out?.why_school_ready) return;
        attachWhySchool(assistantId, {
            schoolName: (typeof out.school_name === 'string' ? out.school_name : ''),
            draftPreview: (typeof out.draft_preview === 'string' ? out.draft_preview : ''),
            hooksUsed: (Array.isArray(out.hooks_used) ? out.hooks_used : []) as string[],
            actionUrl: (typeof out.action_url === 'string' ? out.action_url : '/essay-suite'),
        });
    } catch { /* not JSON */ }
}

function EssayJobCard({
    job,
    splitOpen,
    onOpenSplit,
}: {
    job: NonNullable<ChatMessage['essayJob']>;
    splitOpen: boolean;
    onOpenSplit: () => void;
}) {
    const label = job.runType === 'draft' ? 'Essay draft' : 'Essay brainstorm';
    const statusLabel =
        job.status === 'completed' ? 'Ready' : job.status === 'failed' ? 'Failed' : 'Running…';

    return (
        <div className="mb-3 rounded-lg border border-border bg-card/60 p-3 text-xs shadow-sm">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-md border border-border/70 bg-muted/50">
                    <FileText className="size-5 text-primary" weight="duotone" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-foreground">{label}</span>
                        <span className="rounded border border-border/60 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                            {statusLabel}
                        </span>
                    </div>
                    {job.schoolName ? (
                        <p className="mt-0.5 text-[11px] text-muted-foreground">{job.schoolName}</p>
                    ) : null}
                    {job.title ? (
                        <p className="mt-1 line-clamp-2 text-sm font-medium text-foreground">{job.title}</p>
                    ) : (
                        <p className="mt-1 text-muted-foreground">Background job — live updates below.</p>
                    )}
                    {job.status === 'failed' && job.errorMessage ? (
                        <p className="mt-1 text-destructive">{job.errorMessage}</p>
                    ) : null}
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant={splitOpen ? 'default' : 'outline'}
                            className="h-8 text-xs"
                            onClick={onOpenSplit}
                        >
                            {splitOpen ? 'Split view open' : 'Open split view'}
                        </Button>
                        <Link
                            href={essaySuite.url()}
                            className="inline-flex h-8 items-center rounded-md border border-border/70 px-2 text-xs font-medium text-primary hover:bg-muted/40"
                        >
                            Essay suite
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}

function EssaySplitPanel({
    assistantId,
    messages,
    onClose,
}: {
    assistantId: string;
    messages: ChatMessage[];
    onClose: () => void;
}) {
    const job = messages.find((m) => m.id === assistantId)?.essayJob;
    const label = job?.runType === 'draft' ? 'Draft' : 'Brainstorm';

    return (
        <aside className="flex max-h-[min(88dvh,900px)] min-h-0 w-full min-w-0 shrink-0 flex-col border-border bg-muted/15 md:max-h-none md:flex-1 md:border-l">
            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border/60 px-3 py-2">
                <div className="min-w-0">
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Essay document
                    </p>
                    <p className="truncate text-sm font-medium text-foreground">
                        {label}
                        {job?.schoolName ? ` · ${job.schoolName}` : ''}
                    </p>
                </div>
                <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0" onClick={onClose} aria-label="Close split view">
                    <X className="size-4" />
                </Button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto p-3 scrollbar-none">
                {!job ? (
                    <p className="text-sm text-muted-foreground">No essay job on this message.</p>
                ) : job.status === 'running' ? (
                    <p className="text-sm text-muted-foreground">Still writing… this panel updates automatically.</p>
                ) : job.status === 'failed' ? (
                    <p className="text-sm text-destructive">{job.errorMessage ?? 'Job failed.'}</p>
                ) : job.outputMarkdown ? (
                    <ProseMarkdown content={job.outputMarkdown} className="text-sm" />
                ) : (
                    <p className="text-sm text-muted-foreground">No output yet.</p>
                )}
            </div>
            <div className="shrink-0 border-t border-border/60 px-3 py-2">
                <Link href={essaySuite.url()} className="text-xs font-medium text-primary hover:underline">
                    Open full Essay suite
                </Link>
            </div>
        </aside>
    );
}

function buildConversationMarkdown(messages: ChatMessage[]): string {
    const lines: string[] = ['# Mentor chat export', ''];
    for (const m of messages) {
        const heading = m.role === 'user' ? '## You' : '## Mentor';
        const body = m.content.trim();
        if (body) {
            lines.push(heading, '', body, '');
        }
    }
    if (lines.length <= 2) {
        lines.push('_No messages in this conversation yet._');
    }
    return lines.join('\n').trimEnd();
}

function downloadTextFile(filename: string, content: string, mime: string) {
    const blob = new Blob([content], { type: `${mime};charset=utf-8` });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

function MentorChatHeaderActions({ messages }: { messages: ChatMessage[] }) {
    const shareOrCopy = async () => {
        const url = typeof window !== 'undefined' ? window.location.href : '';
        const title = 'AdmitMe mentor chat';
        try {
            if (navigator.share) {
                await navigator.share({ title, url });
            } else {
                await navigator.clipboard.writeText(url);
            }
        } catch {
            try {
                await navigator.clipboard.writeText(url);
            } catch {
                /* ignore */
            }
        }
    };

    const downloadConversation = () => {
        const md = buildConversationMarkdown(messages);
        const stamp = new Date().toISOString().slice(0, 10);
        downloadTextFile(`mentor-chat-${stamp}.md`, md, 'text/markdown');
    };

    return (
        <>
            <div className="hidden shrink-0 items-center gap-1 md:flex md:gap-2">
                <Link
                    href={dashboardChatsIndex.url()}
                    className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="Chat history"
                    title="Chat history"
                >
                    <Chats size={18} />
                </Link>
                <Link
                    href={dashboard.url()}
                    className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
                    aria-label="New chat"
                    title="New chat"
                >
                    <PlusCircle size={18} />
                </Link>
                <button
                    type="button"
                    className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                    aria-label="Download conversation"
                    title="Download conversation as Markdown"
                    disabled={messages.length === 0}
                    onClick={downloadConversation}
                >
                    <Download size={18} />
                </button>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="rounded-full border border-border bg-accent p-2 text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground"
                            aria-label="More chat options"
                        >
                            <DotsThreeVertical size={18} />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuItem
                            className="gap-2"
                            onClick={() => void shareOrCopy()}
                        >
                            <ShareNetwork className="size-4" />
                            Share link
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            className="gap-2"
                            disabled={messages.length === 0}
                            onClick={downloadConversation}
                        >
                            <Download className="size-4" />
                            Download (.md)
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild className="gap-2">
                            <Link href={essaySuite.url()}>
                                <FileText className="size-4" />
                                Essay suite
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild className="gap-2">
                            <Link href="/settings/notifications">Email & alerts</Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="shrink-0 md:hidden"
                        aria-label="Chat actions"
                    >
                        <DotsThreeVertical className="size-5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                    <DropdownMenuItem asChild className="gap-2">
                        <Link href={dashboardChatsIndex.url()}>
                            <Chats className="size-4" />
                            Chat history
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild className="gap-2">
                        <Link href={dashboard.url()}>
                            <PlusCircle className="size-4" />
                            New chat
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        className="gap-2"
                        onClick={() => void shareOrCopy()}
                    >
                        <ShareNetwork className="size-4" />
                        Share link
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        className="gap-2"
                        disabled={messages.length === 0}
                        onClick={downloadConversation}
                    >
                        <Download className="size-4" />
                        Download
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem asChild className="gap-2">
                        <Link href={essaySuite.url()}>
                            <FileText className="size-4" />
                            Essay suite
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}

export function DashboardMentorChat({
    csrfToken,
    initialConversationId = null,
    initialMessages = EMPTY_INITIAL_MESSAGES,
    renderEmpty,
}: Props) {
    const page = usePage<{
        college_list_council?: { enabled: boolean; reverb: SharedReverbConfig } | null;
        feature_quotas?: FeatureQuotasProps | null;
    }>();

    const featureQuotas = page.props.feature_quotas;

    const chatQuotaHint = useMemo(() => {
        const chat = featureQuotas?.chat;
        if (!chat) {
            return null;
        }
        if (chat.mode === 'trial') {
            const remC = chat.conversations_remaining;
            const per = chat.messages_per_convo_limit;
            if (typeof remC === 'number' && typeof per === 'number') {
                return `Trial: ${remC} new chat${remC === 1 ? '' : 's'} left · up to ${per} messages each.`;
            }
        }
        if (chat.mode === 'paid') {
            if (!chat.warning) {
                return null;
            }
            const rem = chat.messages_remaining;
            const lim = chat.messages_limit;
            if (typeof rem === 'number' && typeof lim === 'number') {
                return `${rem} of ${lim} mentor messages left this period.`;
            }
        }

        return null;
    }, [featureQuotas?.chat]);

    const [quotaModalOpen, setQuotaModalOpen] = useState(false);
    const [quotaModalDetail, setQuotaModalDetail] = useState<QuotaFeatureDetail | null>(null);

    const mappedInitial = useMemo(
        () =>
            initialMessages.map((m) => {
                const base: ChatMessage = {
                    id: m.id,
                    role: m.role as 'user' | 'assistant',
                    content: m.content,
                    activity: (m.activity ?? []).map((a) => ({
                        id: a.id,
                        text: a.text,
                        icon: a.icon ?? 'default',
                        status: (a.status === 'running' ? 'running' : 'done') as 'running' | 'done',
                    })),
                };

                if (m.role === 'assistant' && m.council_feed) {
                    base.councilFeed = {
                        runId: m.council_feed.run_id,
                        status: normalizeCouncilFeedStatusFromServer(m.council_feed.status),
                        lines: [],
                    };
                }

                if (m.role === 'assistant' && m.essay_job) {
                    const rt = m.essay_job.run_type === 'draft' ? 'draft' : 'brainstorm';
                    base.essayJob = {
                        runId: m.essay_job.run_id,
                        runType: rt,
                        status: normalizeEssayJobStatusFromServer(m.essay_job.status),
                        title: null,
                        schoolName: null,
                        outputMarkdown: null,
                        errorMessage: null,
                    };
                }

                if (m.role === 'assistant' && m.gap_fix_data) {
                    base.gapFixData = {
                        schoolNames: m.gap_fix_data.school_names,
                        schoolCount: m.gap_fix_data.school_count,
                        actionUrl: m.gap_fix_data.action_url,
                        analysisId: m.gap_fix_data.analysis_id,
                    };
                }

                if (m.role === 'assistant' && m.essay_roast_data) {
                    base.essayRoastData = {
                        overallVerdict: m.essay_roast_data.overall_verdict,
                        scores: m.essay_roast_data.scores,
                        clicheCount: m.essay_roast_data.cliche_count,
                        topPriorityFix: m.essay_roast_data.top_priority_fix,
                        actionUrl: m.essay_roast_data.action_url,
                    };
                }

                if (m.role === 'assistant' && m.why_school_data) {
                    base.whySchoolData = {
                        schoolName: m.why_school_data.school_name,
                        draftPreview: m.why_school_data.draft_preview,
                        hooksUsed: m.why_school_data.hooks_used,
                        actionUrl: m.why_school_data.action_url,
                    };
                }

                return base;
            }),
        [initialMessages],
    );

    const [messages, setMessages] = useState<ChatMessage[]>(mappedInitial);
    const setHeaderTrailing = useSetAppLayoutHeaderTrailing();

    useLayoutEffect(() => {
        setHeaderTrailing(<MentorChatHeaderActions messages={messages} />);

        return () => setHeaderTrailing(null);
    }, [messages, setHeaderTrailing]);

    const [isTyping, setIsTyping] = useState(false);
    const [conversationId, setConversationId] = useState<string | null>(initialConversationId);
    const [activeAssistantId, setActiveAssistantId] = useState<string | null>(null);
    const [copiedId, setCopiedId] = useState<string | null>(null);
    const scrollRef = useRef<HTMLDivElement>(null);
    const [isAtBottom, setIsAtBottom] = useState(true);
    const [hasUnseenUpdates, setHasUnseenUpdates] = useState(false);
    const abortRef = useRef<AbortController | null>(null);
    const abortReasonRef = useRef<'user' | 'timeout' | null>(null);
    const echoRef = useRef<ReturnType<typeof createLaravelEcho>>(null);
    const councilCleanupsRef = useRef<Map<string, () => void>>(new Map());
    const essayCleanupsRef = useRef<Map<string, () => void>>(new Map());

    const [splitEssayAssistantId, setSplitEssayAssistantId] = useState<string | null>(null);
    const [streamElapsedSec, setStreamElapsedSec] = useState(0);
    const [councilWsState, setCouncilWsState] = useState<CouncilWsState>('connecting');
    /** Stores the final elapsed time keyed by message id so the timeline shows "Done · Xs" after stream ends. */
    const [completedElapsed, setCompletedElapsed] = useState<Record<string, number>>({});

    useEffect(() => {
        if (!isTyping || !activeAssistantId) {
            setStreamElapsedSec(0);

            return;
        }
        const t0 = Date.now();
        setStreamElapsedSec(0);
        const id = window.setInterval(() => {
            setStreamElapsedSec(Math.floor((Date.now() - t0) / 1000));
        }, 1000);

        return () => window.clearInterval(id);
    }, [isTyping, activeAssistantId]);

    const attachCouncilRun = useCallback(
        (assistantId: string, runId: string) => {
            councilCleanupsRef.current.get(assistantId)?.();
            councilCleanupsRef.current.delete(assistantId);

            setCouncilWsState('connecting');

            setMessages((prev) =>
                prev.map((m) =>
                    m.id === assistantId
                        ? { ...m, councilFeed: { runId, status: 'running', lines: [] } }
                        : m,
                ),
            );

            const applyBroadcast = (payload: CouncilBroadcastPayload) => {
                const { type, data } = payload;
                if (type === 'preflight_questions') {
                    const questions = Array.isArray(data.questions)
                        ? data.questions.filter((q): q is string => typeof q === 'string' && q.trim() !== '')
                        : [];
                    const timeoutSeconds =
                        typeof data.timeout_seconds === 'number' && data.timeout_seconds > 0
                            ? data.timeout_seconds
                            : 120;
                    setMessages((prev) =>
                        prev.map((m) => {
                            if (m.id !== assistantId || !m.councilFeed) {
                                return m;
                            }

                            return {
                                ...m,
                                councilFeed: {
                                    ...m.councilFeed,
                                    status: 'awaiting_preflight',
                                    preflightQuestions: questions,
                                    preflightTimeoutSeconds: timeoutSeconds,
                                },
                            };
                        }),
                    );

                    return;
                }
                if (type === 'insight') {
                    const title = String(data.title ?? '');
                    if (!title) {
                        return;
                    }
                    const line: CouncilFeedLine = {
                        id: crypto.randomUUID(),
                        type: 'insight',
                        title,
                        body: data.body ? String(data.body) : undefined,
                        severity: typeof data.severity === 'string' ? data.severity : 'info',
                    };
                    setMessages((prev) =>
                        prev.map((m) => {
                            if (m.id !== assistantId || !m.councilFeed) {
                                return m;
                            }

                            return { ...m, councilFeed: { ...m.councilFeed, lines: [...m.councilFeed.lines, line] } };
                        }),
                    );
                }
                if (type === 'status') {
                    const st = String(data.status ?? '');
                    const line: CouncilFeedLine = {
                        id: crypto.randomUUID(),
                        type: 'status',
                        title: `Phase: ${st}`,
                        severity: 'info',
                    };
                    setMessages((prev) =>
                        prev.map((m) => {
                            if (m.id !== assistantId || !m.councilFeed) {
                                return m;
                            }

                            let nextStatus = m.councilFeed.status;
                            if (st === 'awaiting_preflight') {
                                nextStatus = 'awaiting_preflight';
                            } else if (
                                ['queued', 'preflight', 'scouting', 'judging', 'assembling'].includes(st) &&
                                m.councilFeed.status !== 'completed' &&
                                m.councilFeed.status !== 'failed'
                            ) {
                                nextStatus = 'running';
                            }

                            return {
                                ...m,
                                councilFeed: {
                                    ...m.councilFeed,
                                    status: nextStatus,
                                    lines: [...m.councilFeed.lines, line],
                                },
                            };
                        }),
                    );
                }
                if (type === 'completed') {
                    setMessages((prev) =>
                        prev.map((m) =>
                            m.id === assistantId && m.councilFeed
                                ? { ...m, councilFeed: { ...m.councilFeed, status: 'completed' } }
                                : m,
                        ),
                    );
                }
                if (type === 'failed') {
                    const err =
                        typeof data.message === 'string' && data.message.trim() !== ''
                            ? data.message
                            : undefined;
                    setMessages((prev) =>
                        prev.map((m) =>
                            m.id === assistantId && m.councilFeed
                                ? {
                                      ...m,
                                      councilFeed: {
                                          ...m.councilFeed,
                                          status: 'failed',
                                          errorMessage: err ?? m.councilFeed.errorMessage,
                                      },
                                  }
                                : m,
                        ),
                    );
                }
            };

            const pollOnce = async (): Promise<boolean> => {
                try {
                    const res = await fetch(`/college-lists/council-runs/${runId}`, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) {
                        return true;
                    }
                    const json = (await res.json()) as {
                        status?: string;
                        error_message?: string | null;
                        events?: Array<{ id: string; phase: string; severity: string; title: string; body: string | null }>;
                        awaiting_preflight?: boolean;
                        preflight_questions?: string[];
                        preflight_timeout_seconds?: number;
                    };
                    if (Array.isArray(json.events) && json.events.length > 0) {
                        const lines: CouncilFeedLine[] = json.events.map((ev) => ({
                            id: ev.id,
                            type: 'poll',
                            title: ev.title,
                            body: ev.body ?? undefined,
                            severity: ev.severity,
                        }));
                        setMessages((prev) =>
                            prev.map((m) =>
                                m.id === assistantId && m.councilFeed ? { ...m, councilFeed: { ...m.councilFeed, lines } } : m,
                            ),
                        );
                    }
                    if (json.status === 'awaiting_preflight' || json.awaiting_preflight) {
                        setMessages((prev) =>
                            prev.map((m) => {
                                if (m.id !== assistantId || !m.councilFeed) {
                                    return m;
                                }

                                return {
                                    ...m,
                                    councilFeed: {
                                        ...m.councilFeed,
                                        status: 'awaiting_preflight',
                                        preflightQuestions:
                                            json.preflight_questions?.length ?
                                                json.preflight_questions
                                            :   m.councilFeed.preflightQuestions,
                                        preflightTimeoutSeconds:
                                            json.preflight_timeout_seconds ?? m.councilFeed.preflightTimeoutSeconds ?? 120,
                                    },
                                };
                            }),
                        );
                    } else if (
                        json.status &&
                        json.status !== 'completed' &&
                        json.status !== 'failed' &&
                        json.status !== 'awaiting_preflight'
                    ) {
                        setMessages((prev) =>
                            prev.map((m) => {
                                if (m.id !== assistantId || !m.councilFeed) {
                                    return m;
                                }
                                if (m.councilFeed.status === 'completed' || m.councilFeed.status === 'failed') {
                                    return m;
                                }

                                return {
                                    ...m,
                                    councilFeed: {
                                        ...m.councilFeed,
                                        status: 'running',
                                    },
                                };
                            }),
                        );
                    }
                    if (json.status === 'completed' || json.status === 'failed') {
                        const errMsg =
                            json.status === 'failed' && json.error_message != null && json.error_message !== ''
                                ? json.error_message
                                : undefined;
                        setMessages((prev) =>
                            prev.map((m) =>
                                m.id === assistantId && m.councilFeed
                                    ? {
                                          ...m,
                                          councilFeed: {
                                              ...m.councilFeed,
                                              status: json.status === 'completed' ? 'completed' : 'failed',
                                              errorMessage:
                                                  json.status === 'failed'
                                                      ? errMsg ?? m.councilFeed.errorMessage
                                                      : undefined,
                                          },
                                      }
                                    : m,
                            ),
                        );

                        return false;
                    }

                    return true;
                } catch {
                    return true;
                }
            };

            const councilCfg = page.props.college_list_council;
            let echoCleanup: (() => void) | undefined;
            if (councilCfg?.enabled && councilCfg.reverb?.key && csrfToken) {
                try {
                    if (!echoRef.current) {
                        echoRef.current = createLaravelEcho(csrfToken, councilCfg.reverb);
                    }
                    const echo = echoRef.current;
                    if (echo) {
                        const channel = echo.private(`college-list-runs.${runId}`);
                        channel.listen('.council.broadcast', (e: CouncilBroadcastPayload) => {
                            applyBroadcast(e);
                        });
                        type PusherConn = {
                            state: string;
                            bind: (ev: string, fn: () => void) => void;
                            unbind: (ev: string, fn: () => void) => void;
                        };
                        const conn = (
                            echo.connector as { pusher?: { connection?: PusherConn } } | undefined
                        )?.pusher?.connection;
                        if (conn) {
                            setCouncilWsState(conn.state === 'connected' ? 'connected' : 'connecting');
                            const onConnected = (): void => {
                                setCouncilWsState('connected');
                            };
                            const onDisconnected = (): void => {
                                setCouncilWsState('disconnected');
                            };
                            const onConnecting = (): void => {
                                setCouncilWsState('connecting');
                            };
                            conn.bind('connected', onConnected);
                            conn.bind('disconnected', onDisconnected);
                            conn.bind('connecting', onConnecting);
                            echoCleanup = () => {
                                channel.stopListening('.council.broadcast');
                                conn.unbind('connected', onConnected);
                                conn.unbind('disconnected', onDisconnected);
                                conn.unbind('connecting', onConnecting);
                            };
                        } else {
                            setCouncilWsState('connected');
                            echoCleanup = () => {
                                channel.stopListening('.council.broadcast');
                            };
                        }
                    } else {
                        setCouncilWsState('disconnected');
                    }
                } catch (e) {
                    console.warn('[dashboard/chat] Echo subscribe failed', e);
                    setCouncilWsState('disconnected');
                }
            } else {
                setCouncilWsState('disconnected');
            }

            void pollOnce();
            const interval = window.setInterval(() => {
                void (async () => {
                    const cont = await pollOnce();
                    if (!cont) {
                        window.clearInterval(interval);
                    }
                })();
            }, 5000);

            const cleanup = () => {
                window.clearInterval(interval);
                echoCleanup?.();
            };
            councilCleanupsRef.current.set(assistantId, cleanup);
        },
        [csrfToken, page.props.college_list_council],
    );

    const attachEssayRun = useCallback(
        (
            assistantId: string,
            runId: string,
            runType: 'brainstorm' | 'draft',
            options?: { openSplit?: boolean },
        ) => {
            essayCleanupsRef.current.get(assistantId)?.();
            essayCleanupsRef.current.delete(assistantId);

            if (options?.openSplit !== false) {
                setSplitEssayAssistantId(assistantId);
            }

            setMessages((prev) =>
                prev.map((m) => {
                    if (m.id !== assistantId) {
                        return m;
                    }

                    const prevJob = m.essayJob?.runId === runId ? m.essayJob : null;

                    return {
                        ...m,
                        essayJob: {
                            runId,
                            runType,
                            status: prevJob?.status ?? 'running',
                            title: prevJob?.title ?? null,
                            schoolName: prevJob?.schoolName ?? null,
                            outputMarkdown: prevJob?.outputMarkdown ?? null,
                            errorMessage: prevJob?.errorMessage ?? null,
                        },
                    };
                }),
            );

        let intervalId: number | undefined;

        const pollOnce = async (): Promise<boolean> => {
            try {
                const res = await fetch(`/essay-suite/runs/${runId}`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) {
                    return true;
                }
                const json = (await res.json()) as {
                    status?: string;
                    title_for_card?: string | null;
                    school_name?: string | null;
                    output_markdown?: string | null;
                    error_message?: string | null;
                };
                const st = json.status ?? '';
                const terminal = st === 'completed' || st === 'failed';
                const uiStatus: 'running' | 'completed' | 'failed' =
                    st === 'failed' ? 'failed' : st === 'completed' ? 'completed' : 'running';

                setMessages((prev) =>
                    prev.map((m) =>
                        m.id === assistantId && m.essayJob
                            ? {
                                  ...m,
                                  essayJob: {
                                      ...m.essayJob,
                                      status: uiStatus,
                                      title: json.title_for_card ?? m.essayJob.title,
                                      schoolName: json.school_name ?? m.essayJob.schoolName,
                                      outputMarkdown: json.output_markdown ?? m.essayJob.outputMarkdown,
                                      errorMessage: json.error_message ?? m.essayJob.errorMessage,
                                  },
                              }
                            : m,
                    ),
                );

                return !terminal;
            } catch {
                return true;
            }
        };

        void pollOnce();
        intervalId = window.setInterval(() => {
            void (async () => {
                const cont = await pollOnce();
                if (!cont && intervalId) {
                    window.clearInterval(intervalId);
                    intervalId = undefined;
                }
            })();
        }, 2000);

        const cleanup = () => {
            if (intervalId) {
                window.clearInterval(intervalId);
            }
        };
        essayCleanupsRef.current.set(assistantId, cleanup);
    },
    []);

    const attachGapFix = useCallback(
        (assistantId: string, data: NonNullable<ChatMessage['gapFixData']>) => {
            setMessages((prev) =>
                prev.map((m) => (m.id === assistantId ? { ...m, gapFixData: data } : m)),
            );
        },
        [],
    );

    const attachRoast = useCallback(
        (assistantId: string, data: NonNullable<ChatMessage['essayRoastData']>) => {
            setMessages((prev) =>
                prev.map((m) => (m.id === assistantId ? { ...m, essayRoastData: data } : m)),
            );
        },
        [],
    );

    const attachWhySchool = useCallback(
        (assistantId: string, data: NonNullable<ChatMessage['whySchoolData']>) => {
            setMessages((prev) =>
                prev.map((m) => (m.id === assistantId ? { ...m, whySchoolData: data } : m)),
            );
        },
        [],
    );

    useEffect(() => {
        return () => {
            councilCleanupsRef.current.forEach((fn) => fn());
            councilCleanupsRef.current.clear();
            essayCleanupsRef.current.forEach((fn) => fn());
            essayCleanupsRef.current.clear();
        };
    }, []);

    useEffect(() => {
        setMessages(mappedInitial);
        setConversationId(initialConversationId);
    }, [mappedInitial, initialConversationId]);

    useEffect(() => {
        for (const m of mappedInitial) {
            if (m.role !== 'assistant') {
                continue;
            }

            if (m.councilFeed?.runId) {
                attachCouncilRun(m.id, m.councilFeed.runId);
            }

            if (m.essayJob?.runId && (m.essayJob.runType === 'brainstorm' || m.essayJob.runType === 'draft')) {
                attachEssayRun(m.id, m.essayJob.runId, m.essayJob.runType, { openSplit: false });
            }
        }
    }, [mappedInitial, attachCouncilRun, attachEssayRun]);

    const scrollToBottom = useCallback(() => {
        requestAnimationFrame(() => {
            scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
        });
    }, []);

    useEffect(() => {
        if (isAtBottom) {
            scrollToBottom();
            setHasUnseenUpdates(false);
        } else {
            setHasUnseenUpdates(true);
        }
    }, [messages, isTyping, isAtBottom, scrollToBottom]);

    const handleScroll = useCallback(() => {
        const el = scrollRef.current;
        if (!el) return;

        const threshold = 140;
        const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
        const atBottom = distanceFromBottom <= threshold;
        setIsAtBottom(atBottom);
        if (atBottom) {
            setHasUnseenUpdates(false);
        }
    }, []);

    const handleCopy = useCallback(async (msgId: string, content: string) => {
        try {
            await navigator.clipboard.writeText(content);
            setCopiedId(msgId);
            setTimeout(() => setCopiedId((cur) => (cur === msgId ? null : cur)), 2000);
        } catch {
            // ignore
        }
    }, []);

    const handleAbortStream = useCallback(() => {
        abortReasonRef.current = 'user';
        abortRef.current?.abort();
    }, []);

    const handleSend = useCallback(
        async (text: string, options?: { replaceAssistantId?: string }) => {
            const trimmed = text.trim();
            if (!trimmed) return;

            abortRef.current?.abort();
            abortRef.current = new AbortController();
            abortReasonRef.current = null;

            const assistantId = crypto.randomUUID();
            const replacingId = options?.replaceAssistantId;

            let inactivityTimer: ReturnType<typeof setTimeout> | undefined;
            const clearInactivity = () => {
                if (inactivityTimer !== undefined) {
                    clearTimeout(inactivityTimer);
                    inactivityTimer = undefined;
                }
            };
            const resetInactivityTimer = () => {
                clearInactivity();
                inactivityTimer = setTimeout(() => {
                    abortReasonRef.current = 'timeout';
                    abortRef.current?.abort();
                }, 90000);
            };

            setActiveAssistantId(assistantId);
            if (replacingId) {
                setMessages((prev) => {
                    const next = prev.filter((m) => m.id !== replacingId);

                    return [...next, { id: assistantId, role: 'assistant', content: '', activity: [] }];
                });
            } else {
                const userMsg: ChatMessage = { id: crypto.randomUUID(), role: 'user', content: trimmed };
                setMessages((prev) => [...prev, userMsg, { id: assistantId, role: 'assistant', content: '', activity: [] }]);
            }
            setIsTyping(true);
            resetInactivityTimer();

            try {
                const res = await fetch('/dashboard/chat', {
                    method: 'POST',
                    headers: buildLaravelJsonHeaders(csrfToken),
                    credentials: 'same-origin',
                    body: JSON.stringify({ message: trimmed, conversation_id: conversationId }),
                    signal: abortRef.current.signal,
                });

                if (res.status === 403) {
                    setMessages((prev) =>
                        prev.map((m) =>
                            m.id === assistantId
                                ? {
                                      ...m,
                                      content: 'You do not have access to this conversation.',
                                      streamError: 'failed',
                                  }
                                : m,
                        ),
                    );

                    return;
                }

                if (res.status === 429) {
                    let body: { message?: string; quota?: QuotaFeatureDetail } = {};
                    try {
                        body = (await res.json()) as typeof body;
                    } catch {
                        /* ignore */
                    }
                    const msg =
                        typeof body.message === 'string' && body.message !== ''
                            ? body.message
                            : "You've reached your mentor message limit for your plan.";
                    setMessages((prev) =>
                        prev.map((m) =>
                            m.id === assistantId
                                ? {
                                      ...m,
                                      content: msg,
                                      streamError: 'quota',
                                  }
                                : m,
                        ),
                    );
                    if (body.quota) {
                        setQuotaModalDetail(body.quota);
                        setQuotaModalOpen(true);
                    }

                    return;
                }

                await consumeVercelAiStream(
                    res,
                    (delta) => {
                        resetInactivityTimer();
                        setMessages((prev) =>
                            prev.map((m) =>
                                m.id === assistantId
                                    ? { ...m, content: m.content + delta, streamError: undefined }
                                    : m,
                            ),
                        );
                    },
                    (evt) => {
                        resetInactivityTimer();
                        const step = streamEventToStep(evt);
                        if (step) {
                            setMessages((prev) =>
                                prev.map((m) =>
                                    m.id === assistantId
                                        ? {
                                              ...m,
                                              activity: [...(m.activity ?? []), {
                                                  id: step.id,
                                                  text: step.label,
                                                  icon: step.icon,
                                                  status: step.status,
                                              }],
                                          }
                                        : m,
                                ),
                            );
                        }
                        const finishedToolId = toolCallIdFromOutput(evt);
                        if (finishedToolId) {
                            setMessages((prev) =>
                                prev.map((m) =>
                                    m.id === assistantId && m.activity
                                        ? {
                                              ...m,
                                              activity: m.activity.map((a) =>
                                                  a.id === finishedToolId ? { ...a, status: 'done' as const } : a,
                                              ),
                                          }
                                        : m,
                                ),
                            );
                        }
                        tryStartCouncilFromToolEvent(evt, assistantId, attachCouncilRun);
                        tryStartEssayFromToolEvent(evt, assistantId, attachEssayRun);
                        tryStartGapFixFromToolEvent(evt, assistantId, attachGapFix);
                        tryStartEssayRoastFromToolEvent(evt, assistantId, attachRoast);
                        tryStartWhySchoolFromToolEvent(evt, assistantId, attachWhySchool);
                        if (evt.type === 'data-conversation-id' && evt.data?.conversationId) {
                            const cid = evt.data.conversationId as string;
                            setConversationId(cid);
                            window.history.replaceState(
                                window.history.state,
                                '',
                                dashboardChatShow.url(cid),
                            );
                        }
                    },
                );
            } catch (e) {
                if (e instanceof Error && e.name === 'AbortError') {
                    const reason = abortReasonRef.current;
                    abortReasonRef.current = null;
                    if (reason === 'timeout') {
                        setMessages((prev) =>
                            prev.map((m) =>
                                m.id === assistantId
                                    ? {
                                          ...m,
                                          content:
                                              m.content.trim() !== ''
                                                  ? `${m.content}\n\n**This is taking longer than usual.** Use **Retry** below or try a shorter message.`
                                                  : '**This is taking longer than usual.** Use **Retry** below or try a shorter message.',
                                          streamError: 'timeout',
                                      }
                                    : m,
                            ),
                        );
                    } else {
                        setMessages((prev) =>
                            prev.map((m) => {
                                if (m.id !== assistantId) {
                                    return m;
                                }
                                if (m.content.trim() === '') {
                                    return { ...m, content: '*Generation stopped.*' };
                                }

                                return { ...m, content: `${m.content}\n\n*Generation stopped.*` };
                            }),
                        );
                    }

                    return;
                }
                const msg = e instanceof Error ? e.message : String(e);
                console.error(`[dashboard/chat] Assistant request failed: ${msg}`, e);
                const safeHint =
                    msg.length > 0 && msg.length < 500 && !msg.includes('<!DOCTYPE') && !msg.includes('<html')
                        ? msg
                        : null;
                setMessages((prev) =>
                    prev.map((m) =>
                        m.id === assistantId
                            ? {
                                  ...m,
                                  content:
                                      m.content.trim() !== ''
                                          ? m.content
                                          : safeHint ?? 'Something went wrong. Use **Retry** below or try again in a moment.',
                                  streamError: 'failed',
                              }
                            : m,
                    ),
                );
            } finally {
                clearInactivity();
                const finalElapsed = streamElapsedSec;
                if (finalElapsed > 0) {
                    setCompletedElapsed((prev) => ({ ...prev, [assistantId]: finalElapsed }));
                }
                setIsTyping(false);
                setActiveAssistantId(null);
            }
        },
        [attachCouncilRun, attachEssayRun, attachGapFix, attachRoast, attachWhySchool, conversationId, csrfToken, streamElapsedSec],
    );

    const hasConversation = messages.length > 0;

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
            <AnimatePresence mode="wait">
                {hasConversation ? (
                    <motion.div
                        key="chat"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ duration: 0.3, ease: 'easeOut' }}
                        className="flex min-h-0 min-w-0 flex-1 flex-col md:flex-row"
                    >
                        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                        <div className="relative min-h-0 flex-1">
                        <div
                            ref={scrollRef}
                            onScroll={handleScroll}
                            className="h-full min-h-0 overflow-y-auto px-3 py-4 scrollbar-none sm:px-5 sm:py-6 md:px-6 md:py-8"
                        >
                            <div className="mx-auto w-full min-w-0 max-w-3xl space-y-6">
                                {messages.map((msg) => (
                                    <div key={msg.id}>
                                        {msg.role === 'user' ? (
                                            <div className="flex justify-end">
                                                <p className="max-w-[min(100%,85%)] rounded-lg bg-muted px-3 py-2 text-sm leading-relaxed text-foreground sm:max-w-[85%] sm:px-4 sm:py-2.5">
                                                    {msg.content}
                                                </p>
                                            </div>
                                        ) : (
                                            <div className="min-w-0">
                                                {activeAssistantId === msg.id && isTyping && (msg.activity?.length ?? 0) === 0 && msg.content.length === 0 && (
                                                    <div className="mb-3 flex items-center gap-2 px-1 text-xs text-muted-foreground">
                                                        <CircleNotch size={13} className="shrink-0 animate-spin text-primary" />
                                                        <span className="font-medium text-foreground">
                                                            Thinking
                                                            {streamElapsedSec > 0 ? (
                                                                <span className="ml-1.5 tabular-nums text-[10px] text-muted-foreground">· {streamElapsedSec}s</span>
                                                            ) : null}
                                                        </span>
                                                    </div>
                                                )}

                                                {msg.councilFeed ? (
                                                    <CouncilFeedPanel
                                                        feed={msg.councilFeed}
                                                        csrfToken={csrfToken}
                                                        wsState={councilWsState}
                                                        onPreflightReplySent={() => {
                                                            setMessages((prev) =>
                                                                prev.map((m) =>
                                                                    m.id === msg.id && m.councilFeed ?
                                                                        {
                                                                            ...m,
                                                                            councilFeed: {
                                                                                ...m.councilFeed,
                                                                                status: 'running',
                                                                                preflightQuestions: [],
                                                                            },
                                                                        }
                                                                    :   m,
                                                                ),
                                                            );
                                                        }}
                                                    />
                                                ) : null}

                                                {msg.essayJob ? (
                                                    <EssayJobCard
                                                        job={msg.essayJob}
                                                        splitOpen={splitEssayAssistantId === msg.id}
                                                        onOpenSplit={() =>
                                                            setSplitEssayAssistantId((cur) =>
                                                                cur === msg.id ? null : msg.id,
                                                            )
                                                        }
                                                    />
                                                ) : null}

                                                {msg.gapFixData && (
                                                    <GapFixChatCard {...msg.gapFixData} />
                                                )}
                                                {msg.essayRoastData && (
                                                    <EssayRoastChatCard {...msg.essayRoastData} />
                                                )}
                                                {msg.whySchoolData && (
                                                    <WhyThisSchoolChatCard {...msg.whySchoolData} />
                                                )}

                                                {msg.activity && msg.activity.length > 0 && (
                                                    <AgentTimeline
                                                        items={msg.activity}
                                                        isLive={activeAssistantId === msg.id && isTyping}
                                                        elapsedSeconds={
                                                            activeAssistantId === msg.id && isTyping
                                                                ? streamElapsedSec
                                                                : undefined
                                                        }
                                                        totalElapsed={completedElapsed[msg.id]}
                                                    />
                                                )}

                                                {msg.content.length > 0 && (
                                                    <ProseMarkdown
                                                        content={msg.content}
                                                        className="text-sm leading-relaxed text-foreground"
                                                    />
                                                )}

                                                {activeAssistantId !== msg.id && msg.content.length > 0 && (
                                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleCopy(msg.id, msg.content)}
                                                            className="inline-flex items-center gap-1.5 rounded-lg p-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                                            aria-label="Copy"
                                                        >
                                                            {copiedId === msg.id ? (
                                                                <>
                                                                    <Check size={14} className="text-green-500" />
                                                                    <span className="text-green-500">Copied</span>
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <Copy size={14} />
                                                                    <span>Copy</span>
                                                                </>
                                                            )}
                                                        </button>
                                                        {msg.streamError ? (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                className="h-8"
                                                                onClick={() => {
                                                                    const idx = messages.findIndex((x) => x.id === msg.id);
                                                                    const prev = idx > 0 ? messages[idx - 1] : null;
                                                                    if (prev?.role === 'user') {
                                                                        void handleSend(prev.content, {
                                                                            replaceAssistantId: msg.id,
                                                                        });
                                                                    }
                                                                }}
                                                            >
                                                                Retry
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                        {!isAtBottom && hasUnseenUpdates ? (
                            <div className="pointer-events-none absolute bottom-3 left-0 right-0 flex justify-center">
                                <button
                                    type="button"
                                    onClick={() => {
                                        scrollToBottom();
                                        setHasUnseenUpdates(false);
                                    }}
                                    className="pointer-events-auto inline-flex items-center gap-2 rounded-full border border-border/70 bg-background/95 px-3.5 py-2 text-xs font-medium text-foreground shadow-sm backdrop-blur hover:bg-background"
                                >
                                    Jump to latest
                                    <span className="inline-block size-2 rounded-full bg-primary" aria-hidden />
                                </button>
                            </div>
                        ) : null}
                        </div>

                        <motion.footer
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.35, delay: 0.1, ease: 'easeOut' }}
                            className="relative shrink-0 px-3 pb-4 pt-2 sm:px-4 md:px-6"
                        >
                            <div className="mx-auto w-full min-w-0 max-w-3xl">
                                {chatQuotaHint ? (
                                    <p
                                        className={`mb-2 text-center text-xs ${featureQuotas?.chat?.mode === 'paid' && featureQuotas.chat.warning ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground'}`}
                                    >
                                        {chatQuotaHint}
                                    </p>
                                ) : null}
                                <PromptInputBox
                                    onSend={(message) => handleSend(message)}
                                    onAbort={handleAbortStream}
                                    isLoading={isTyping}
                                    placeholder="Ask a follow-up…"
                                    features={{
                                        imageAttachment: true,
                                        searchMode: true,
                                        thinkMode: false,
                                        canvasMode: false,
                                        voiceInput: false,
                                        modeDividers: false,
                                    }}
                                    copy={{
                                        defaultPlaceholder: 'Ask a follow-up…',
                                        searchPlaceholder: 'Search the web…',
                                    }}
                                />
                            </div>
                        </motion.footer>
                        </div>

                        {splitEssayAssistantId ? (
                            <EssaySplitPanel
                                assistantId={splitEssayAssistantId}
                                messages={messages}
                                onClose={() => setSplitEssayAssistantId(null)}
                            />
                        ) : null}
                    </motion.div>
                ) : renderEmpty ? (
                    <motion.div
                        key="hub"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        className="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto"
                    >
                        {renderEmpty({ send: handleSend, isTyping })}
                    </motion.div>
                ) : (
                    <motion.div
                        key="empty"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        className="flex flex-1 flex-col items-center justify-center px-4 py-8"
                    >
                        <p className="mb-4 max-w-md text-center text-sm text-muted-foreground">
                            Ask anything about admissions. Your conversation saves to this URL so you can return
                            later.
                        </p>
                        <div className="w-full max-w-3xl">
                            {chatQuotaHint ? (
                                <p
                                    className={`mb-2 text-center text-xs ${featureQuotas?.chat?.mode === 'paid' && featureQuotas.chat.warning ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground'}`}
                                >
                                    {chatQuotaHint}
                                </p>
                            ) : null}
                            <PromptInputBox
                                onSend={(message) => handleSend(message)}
                                onAbort={handleAbortStream}
                                isLoading={isTyping}
                                placeholder="Ask me anything about college admissions…"
                                features={{
                                    imageAttachment: true,
                                    searchMode: true,
                                    thinkMode: false,
                                    canvasMode: false,
                                    voiceInput: true,
                                    modeDividers: false,
                                }}
                                copy={{
                                    defaultPlaceholder: 'Ask me anything about college admissions…',
                                    searchPlaceholder: 'Search the web…',
                                }}
                            />
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <QuotaExhaustedModal
                open={quotaModalOpen}
                onOpenChange={setQuotaModalOpen}
                quota={quotaModalDetail}
                plan={featureQuotas?.plan}
            />
        </div>
    );
}
