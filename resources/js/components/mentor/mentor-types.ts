import type { ProposalData } from '@/components/admitme/proposal-card';
import type { TaskGoal } from '@/components/admitme/task-goal-card';
import type { CouncilFeedLine } from '@/types/council';

export type ChatMode = 'ask' | 'agent';

export type ToolStatus = 'running' | 'done' | 'failed';

/** Cards a turn can carry whose live data sits on the message (council feed, essay job, gap fix, roast, Why us). */
export type CardKind = 'council' | 'essay' | 'gapFix' | 'roast' | 'whySchool';

/**
 * One piece of an assistant turn, in the order it happened: text, the tools the mentor used between
 * paragraphs, and the cards those tools produced.
 */
export type MessagePart =
    | { kind: 'text'; text: string }
    | {
          kind: 'tool';
          id: string;
          name: string;
          status: ToolStatus;
          /** Short references the tool found (document pages, source titles), shown beside it. */
          sources?: string[];
          /** What the tool looked for (a web search query) or the task a specialist was given. */
          detail?: string | null;
          /** What a specialist (sub-agent) wrote — streamed while it works. */
          output?: string;
          /** What a specialist did: its web searches and what they found. */
          activity?: SubagentActivityEntry[];
      }
    | { kind: 'proposal'; id: string; data?: ProposalData }
    | { kind: 'task'; id: string; data?: TaskGoal }
    | { kind: 'card'; card: CardKind };

/** One thing a specialist did while it worked (App\Ai\Agents\Subagents\SubagentActivity). */
export type SubagentActivityEntry = {
    kind: 'search' | 'tool';
    query?: string;
    name?: string;
    status?: 'running' | 'done';
    count?: number;
    sources?: string[];
};

/** A background mentor reply (MentorTurnsController::present). */
export type MentorTurnInfo = {
    id: string;
    status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
    conversation_id: string | null;
    mode: string;
    message: string;
    attachments: string[];
    event_count: number;
    created_at: string | null;
    /** Once the reply has ended: whether its conversation is stored (null while it runs). */
    conversation_stored?: boolean | null;
};

type ServerPart =
    | { type: 'text'; text: string }
    | {
          type: 'tool';
          id: string;
          name: string;
          status: string;
          sources?: string[];
          detail?: string | null;
          output?: string;
          activity?: SubagentActivityEntry[];
      }
    | { type: 'proposal'; id: string; proposal?: ProposalData }
    | { type: 'task'; id: string }
    | { type: 'card'; card: string };

const CARD_KINDS: CardKind[] = [
    'council',
    'essay',
    'gapFix',
    'roast',
    'whySchool',
];

/** A document from the student's uploads, attached to a message in the composer. */
export interface MentorAttachment {
    id: string;
    title: string;
    name: string;
    kind: string;
    kindLabel: string;
    mime: string | null;
    sizeBytes: number | null;
    pages: number | null;
    status: string | null;
    statusLabel: string | null;
    failureReason: string | null;
    preview: string | null;
}

export interface ChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    /** Names of files attached to a user message. */
    attachments?: string[];
    /** Set when the stream failed or timed out so the UI can offer Retry */
    streamError?: 'timeout' | 'failed' | 'quota';
    /** The assistant turn in order (text, tools, cards). `content` keeps the whole text for copying. */
    parts?: MessagePart[];
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

/** Serialized from MentorChatConversation::messagesForDisplay (snake_case keys). */
export type DashboardChatInitialMessage = {
    id: string;
    role: string;
    content: string;
    attachments?: string[];
    parts?: ServerPart[];
    council_feed?: { run_id: string; status: string };
    essay_job?: {
        run_id: string;
        run_type: 'brainstorm' | 'draft';
        status: string;
    };
    gap_fix_data?: {
        school_names: string[];
        school_count: number;
        action_url: string;
        analysis_id: string;
    };
    essay_roast_data?: {
        overall_verdict: string;
        scores: Record<string, { score: number; reasoning: string }>;
        cliche_count: number;
        top_priority_fix: string;
        action_url: string;
    };
    why_school_data?: {
        school_name: string;
        draft_preview: string;
        hooks_used: string[];
        action_url: string;
    };
    proposal_ids?: string[];
    task_goal_ids?: string[];
};

export type RecentConversation = {
    id: string;
    title: string;
    updated_at: string | null;
};

function councilStatus(
    s: string,
): NonNullable<ChatMessage['councilFeed']>['status'] {
    return s === 'awaiting_preflight' || s === 'completed' || s === 'failed'
        ? s
        : 'running';
}

function essayStatus(
    s: string,
): NonNullable<ChatMessage['essayJob']>['status'] {
    return s === 'completed' || s === 'failed' ? s : 'running';
}

function mapServerPart(part: ServerPart): MessagePart | null {
    switch (part.type) {
        case 'text':
            return part.text.trim() === ''
                ? null
                : { kind: 'text', text: part.text };
        case 'tool':
            return {
                kind: 'tool',
                id: part.id,
                name: part.name,
                status: part.status === 'failed' ? 'failed' : 'done',
                sources: part.sources ?? [],
                detail: part.detail ?? null,
                ...(typeof part.output === 'string'
                    ? { output: part.output }
                    : {}),
                ...(Array.isArray(part.activity) && part.activity.length > 0
                    ? { activity: part.activity }
                    : {}),
            };
        case 'proposal':
            return { kind: 'proposal', id: part.id, data: part.proposal };
        case 'task':
            return { kind: 'task', id: part.id };
        case 'card':
            return CARD_KINDS.includes(part.card as CardKind)
                ? { kind: 'card', card: part.card as CardKind }
                : null;
        default:
            return null;
    }
}

/** A turn stored before parts existed: its text, then its cards. */
function legacyParts(m: DashboardChatInitialMessage): MessagePart[] {
    const parts: MessagePart[] =
        m.content.trim() !== '' ? [{ kind: 'text', text: m.content }] : [];
    const cards: Array<[unknown, CardKind]> = [
        [m.council_feed, 'council'],
        [m.essay_job, 'essay'],
        [m.gap_fix_data, 'gapFix'],
        [m.essay_roast_data, 'roast'],
        [m.why_school_data, 'whySchool'],
    ];

    for (const [present, card] of cards) {
        if (present) {
            parts.push({ kind: 'card', card });
        }
    }

    for (const id of m.proposal_ids ?? []) {
        parts.push({ kind: 'proposal', id });
    }

    for (const id of m.task_goal_ids ?? []) {
        parts.push({ kind: 'task', id });
    }

    return parts;
}

/** Server display rows → chat state. */
export function mapInitialMessages(
    rows: DashboardChatInitialMessage[],
): ChatMessage[] {
    return rows.map((m) => {
        const base: ChatMessage = {
            id: m.id,
            role: m.role === 'user' ? 'user' : 'assistant',
            content: m.content,
            attachments:
                m.attachments && m.attachments.length > 0
                    ? m.attachments
                    : undefined,
        };

        if (m.role !== 'assistant') {
            return base;
        }

        base.parts =
            m.parts && m.parts.length > 0
                ? m.parts
                      .map(mapServerPart)
                      .filter((p): p is MessagePart => p !== null)
                : legacyParts(m);

        if (
            !base.parts.some((p) => p.kind === 'text') &&
            m.content.trim() !== ''
        ) {
            base.parts.push({ kind: 'text', text: m.content });
        }

        if (m.council_feed) {
            base.councilFeed = {
                runId: m.council_feed.run_id,
                status: councilStatus(m.council_feed.status),
                lines: [],
            };
        }

        if (m.essay_job) {
            base.essayJob = {
                runId: m.essay_job.run_id,
                runType:
                    m.essay_job.run_type === 'draft' ? 'draft' : 'brainstorm',
                status: essayStatus(m.essay_job.status),
                title: null,
                schoolName: null,
                outputMarkdown: null,
                errorMessage: null,
            };
        }

        if (m.gap_fix_data) {
            base.gapFixData = {
                schoolNames: m.gap_fix_data.school_names,
                schoolCount: m.gap_fix_data.school_count,
                actionUrl: m.gap_fix_data.action_url,
                analysisId: m.gap_fix_data.analysis_id,
            };
        }

        if (m.essay_roast_data) {
            base.essayRoastData = {
                overallVerdict: m.essay_roast_data.overall_verdict,
                scores: m.essay_roast_data.scores,
                clicheCount: m.essay_roast_data.cliche_count,
                topPriorityFix: m.essay_roast_data.top_priority_fix,
                actionUrl: m.essay_roast_data.action_url,
            };
        }

        if (m.why_school_data) {
            base.whySchoolData = {
                schoolName: m.why_school_data.school_name,
                draftPreview: m.why_school_data.draft_preview,
                hooksUsed: m.why_school_data.hooks_used,
                actionUrl: m.why_school_data.action_url,
            };
        }

        return base;
    });
}

/** Stream a text delta into the turn: it continues the last paragraph, or starts one after a tool or card. */
export function appendTextPart(
    parts: MessagePart[],
    delta: string,
): MessagePart[] {
    const last = parts[parts.length - 1];

    if (last?.kind === 'text') {
        return [
            ...parts.slice(0, -1),
            { kind: 'text', text: last.text + delta },
        ];
    }

    // The server separates steps with a blank line; a paragraph that starts after a tool does not need it.
    const text = delta.replace(/^\s+/, '');

    return text === '' ? parts : [...parts, { kind: 'text', text }];
}

export function addToolPart(
    parts: MessagePart[],
    tool: Extract<MessagePart, { kind: 'tool' }>,
): MessagePart[] {
    return parts.some((p) => p.kind === 'tool' && p.id === tool.id)
        ? parts
        : [...parts, tool];
}

export function updateToolPart(
    parts: MessagePart[],
    id: string,
    patch: Partial<Extract<MessagePart, { kind: 'tool' }>>,
): MessagePart[] {
    return parts.map((p) =>
        p.kind === 'tool' && p.id === id ? { ...p, ...patch } : p,
    );
}

/** Add a card once; a proposal or plan that is already shown just takes the fuller data. */
export function addCardPart(
    parts: MessagePart[],
    part: Exclude<MessagePart, { kind: 'text' } | { kind: 'tool' }>,
): MessagePart[] {
    const index = parts.findIndex(
        (p) =>
            p.kind === part.kind &&
            (p.kind === 'card'
                ? p.card === (part as { card?: CardKind }).card
                : (p as { id: string }).id === (part as { id?: string }).id),
    );

    if (index === -1) {
        return [...parts, part];
    }

    if ((part.kind === 'proposal' || part.kind === 'task') && part.data) {
        return parts.map((p, i) => (i === index ? part : p));
    }

    return parts;
}

/**
 * Pull a few short references out of a tool's JSON output so the step can show what it found:
 * document pages from a document search, source titles or hosts from research.
 */
export function sourcesFromToolOutput(output: unknown): string[] {
    let data: unknown = output;

    if (typeof output === 'string') {
        try {
            data = JSON.parse(output);
        } catch {
            return [];
        }
    }

    if (!data || typeof data !== 'object') {
        return [];
    }

    const found: string[] = [];
    const add = (label: unknown) => {
        if (
            typeof label === 'string' &&
            label.trim() !== '' &&
            !found.includes(label.trim())
        ) {
            found.push(label.trim().slice(0, 64));
        }
    };
    const host = (url: unknown) => {
        if (typeof url !== 'string') {
            return null;
        }

        try {
            return new URL(url).hostname.replace(/^www\./, '');
        } catch {
            return null;
        }
    };
    const record = data as Record<string, unknown>;

    for (const hit of Array.isArray(record.results) ? record.results : []) {
        if (hit && typeof hit === 'object') {
            const h = hit as Record<string, unknown>;
            add(
                typeof h.document === 'string'
                    ? `${h.document}${typeof h.page === 'number' ? ` · p.${h.page}` : ''}`
                    : (h.title ?? host(h.url)),
            );
        }
    }

    for (const requirement of Array.isArray(record.requirements)
        ? record.requirements
        : []) {
        const source =
            requirement && typeof requirement === 'object'
                ? (requirement as Record<string, unknown>).source
                : null;

        if (source && typeof source === 'object') {
            const s = source as Record<string, unknown>;
            add(s.title ?? host(s.url));
        }
    }

    for (const source of Array.isArray(record.sources) ? record.sources : []) {
        if (source && typeof source === 'object') {
            const s = source as Record<string, unknown>;
            add(s.title ?? host(s.url));
        } else {
            add(host(source) ?? source);
        }
    }

    return found.slice(0, 3);
}

export function formatBytes(bytes: number | null | undefined): string | null {
    if (!bytes || bytes <= 0) {
        return null;
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function relativeTime(
    iso: string | number | null | undefined,
): string | null {
    if (iso === null || iso === undefined) {
        return null;
    }

    const then =
        typeof iso === 'number'
            ? iso
            : Date.parse(
                  iso.includes('T') || iso.endsWith('Z')
                      ? iso
                      : `${iso.replace(' ', 'T')}Z`,
              );

    if (Number.isNaN(then)) {
        return null;
    }

    const seconds = Math.max(0, Math.round((Date.now() - then) / 1000));

    if (seconds < 45) {
        return 'just now';
    }

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) {
        return `${minutes} min${minutes === 1 ? '' : 's'} ago`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    }

    const days = Math.round(hours / 24);

    if (days < 7) {
        return `${days} day${days === 1 ? '' : 's'} ago`;
    }

    return new Date(then).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
