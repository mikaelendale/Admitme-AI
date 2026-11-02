'use client';

import { usePage } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { Dispatch, ReactNode, SetStateAction } from 'react';
import type { ProposalData } from '@/components/admitme/proposal-card';
import type { TaskGoal } from '@/components/admitme/task-goal-card';
import { useHydrated } from '@/hooks/use-hydrated';
import { toolCallIdFromOutput } from '@/lib/consumeVercelAiStream';
import type { VercelAiStreamPart } from '@/lib/consumeVercelAiStream';
import { createLaravelEcho } from '@/lib/laravelEcho';
import type {
    CouncilBroadcastPayload,
    SharedReverbConfig,
} from '@/lib/laravelEcho';
import { buildLaravelJsonHeaders } from '@/lib/laravelFetch';
import type { CouncilFeedLine } from '@/types/council';
import type {
    FeatureQuotasProps,
    QuotaFeatureDetail,
} from '@/types/feature-quotas';
import { isSubagent, toolDetail } from './mentor-tools';
import {
    addCardPart,
    addToolPart,
    appendTextPart,
    mapInitialMessages,
    sourcesFromToolOutput,
    updateToolPart,
} from './mentor-types';
import type {
    CardKind,
    ChatMessage,
    ChatMode,
    DashboardChatInitialMessage,
    MentorAttachment,
    MentorTurnInfo,
    MessagePart,
    RecentConversation,
    SubagentActivityEntry,
    ToolStatus,
} from './mentor-types';

export type CouncilWsState = 'connecting' | 'connected' | 'disconnected';

/** A stored conversation as the panel shows it. */
type StoredConversation = {
    conversation_id: string;
    title: string | null;
    updated_at: string | null;
    messages: DashboardChatInitialMessage[];
};

type SendOptions = {
    replaceAssistantId?: string;
    attachments?: MentorAttachment[];
};

export type MentorContextValue = {
    open: boolean;
    setOpen: (open: boolean) => void;
    toggle: () => void;
    conversationId: string | null;
    title: string | null;
    updatedAt: number | null;
    messages: ChatMessage[];
    setMessages: Dispatch<SetStateAction<ChatMessage[]>>;
    loadingConversation: boolean;
    /** The student's latest conversations (for the sidebar and history), newest first. */
    recent: RecentConversation[];
    refreshRecent: () => Promise<void>;
    isTyping: boolean;
    activeAssistantId: string | null;
    /** The reply has been queued for a few seconds without starting (no free worker yet). */
    waitingToStart: boolean;
    mode: ChatMode;
    setMode: (mode: ChatMode) => void;
    send: (text: string, options?: SendOptions) => Promise<void>;
    abort: () => void;
    newConversation: () => void;
    loadConversation: (id: string) => Promise<void>;
    councilWsState: CouncilWsState;
    essayDocumentFor: string | null;
    setEssayDocumentFor: (assistantId: string | null) => void;
    draft: string;
    setDraft: (text: string) => void;
    attachments: MentorAttachment[];
    setAttachments: Dispatch<SetStateAction<MentorAttachment[]>>;
    /** Open the panel, optionally put text in the composer, and focus it. */
    compose: (text?: string) => void;
    registerComposer: (el: HTMLTextAreaElement | null) => void;
    quotaHint: { text: string; warning: boolean } | null;
    quotaModal: { open: boolean; detail: QuotaFeatureDetail | null };
    setQuotaModalOpen: (open: boolean) => void;
    plan: FeatureQuotasProps['plan'] | undefined;
    csrfToken?: string;
};

const MentorContext = createContext<MentorContextValue | null>(null);

const OPEN_KEY = 'admitme.mentor.open';
export const DOCKED_QUERY = '(min-width: 1280px)';
const CONVERSATION_KEY = 'admitme.mentor.conversation';
const MODE_KEY = 'admitme.mentor.mode';

const sleep = (ms: number) =>
    new Promise<void>((resolve) => window.setTimeout(resolve, ms));

function readStorage(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(key: string, value: string | null): void {
    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, value);
        }
    } catch {
        /* storage unavailable (private window, blocked): the panel still works, it just won't remember */
    }
}

function parseToolOutput(
    evt: VercelAiStreamPart,
): Record<string, unknown> | null {
    if (evt.type !== 'tool-output-available') {
        return null;
    }

    const raw = evt.output;
    const str = typeof raw === 'string' ? raw : JSON.stringify(raw ?? '');

    try {
        const parsed = JSON.parse(str) as unknown;

        return parsed && typeof parsed === 'object'
            ? (parsed as Record<string, unknown>)
            : null;
    } catch {
        return null;
    }
}

type SearchUpdate = {
    id: string;
    status: ToolStatus;
    detail?: string;
    sources?: string[];
};

/**
 * A web search the provider ran (sent as `custom` parts): OpenAI `web_search_call` items, Anthropic
 * `server_tool_use` blocks and their `web_search_tool_result`.
 */
function webSearchUpdate(evt: VercelAiStreamPart): SearchUpdate | null {
    if (evt.type !== 'custom' || typeof evt.kind !== 'string') {
        return null;
    }

    const dot = evt.kind.indexOf('.');
    const provider = evt.kind.slice(0, dot);
    const type = evt.kind.slice(dot + 1);
    const meta = (
        evt.providerMetadata as
            | Record<
                  string,
                  {
                      itemId?: string;
                      status?: string;
                      data?: Record<string, unknown>;
                  }
              >
            | undefined
    )?.[provider];
    const data = meta?.data ?? {};
    const query = (value: unknown) =>
        value &&
        typeof value === 'object' &&
        typeof (value as { query?: unknown }).query === 'string'
            ? ((value as { query: string }).query as string)
            : undefined;

    if (!meta?.itemId) {
        return null;
    }

    if (type === 'web_search_call') {
        return {
            id: meta.itemId,
            status: meta.status === 'completed' ? 'done' : 'running',
            detail: query(data.action),
        };
    }

    if (type === 'server_tool_use' && data.name === 'web_search') {
        return {
            id: meta.itemId,
            status: 'running',
            detail: query(data.input),
        };
    }

    if (type === 'web_search_tool_result') {
        return {
            id: meta.itemId,
            status: 'done',
            sources: sourcesFromToolOutput({
                sources: Array.isArray(data.content) ? data.content : [],
            }),
        };
    }

    return null;
}

export function useMentor(): MentorContextValue {
    const value = useContext(MentorContext);

    if (!value) {
        throw new Error('useMentor must be used within MentorProvider');
    }

    return value;
}

/** For components that may render outside the workspace shell. */
export function useOptionalMentor(): MentorContextValue | null {
    return useContext(MentorContext);
}

export function MentorProvider({ children }: { children: ReactNode }) {
    const page = usePage<{
        csrf_token?: string;
        college_list_council?: {
            enabled: boolean;
            reverb: SharedReverbConfig;
        } | null;
        feature_quotas?: FeatureQuotasProps | null;
        initial_conversation_id?: string | null;
        initial_chat_messages?: DashboardChatInitialMessage[];
    }>();
    const csrfToken = page.props.csrf_token;
    const featureQuotas = page.props.feature_quotas;
    const councilConfig = page.props.college_list_council;

    // Wide screens dock the panel beside the page and remember whether it was open; narrower screens show it
    // as an overlay that always starts closed. Until the student chooses, the default is read after
    // hydration, so the server HTML and the first client render match.
    const hydrated = useHydrated();
    const [choice, setChoice] = useState<boolean | null>(null);
    const open =
        choice ??
        (hydrated &&
            window.matchMedia(DOCKED_QUERY).matches &&
            readStorage(OPEN_KEY) !== '0');
    const setOpen = useCallback((value: boolean) => {
        setChoice(value);

        if (window.matchMedia(DOCKED_QUERY).matches) {
            writeStorage(OPEN_KEY, value ? '1' : '0');
        }
    }, []);
    const toggle = useCallback(() => setOpen(!open), [open, setOpen]);

    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [conversationId, setConversationId] = useState<string | null>(null);
    // The id as of now, for callbacks that outlive a render (a reply being followed).
    const conversationIdRef = useRef<string | null>(null);
    useEffect(() => {
        conversationIdRef.current = conversationId;
    }, [conversationId]);
    const [title, setTitle] = useState<string | null>(null);
    const [updatedAt, setUpdatedAt] = useState<number | null>(null);
    const [loadingConversation, setLoadingConversation] = useState(false);
    const [recent, setRecent] = useState<RecentConversation[]>([]);
    const [isTyping, setIsTyping] = useState(false);
    // Ask or Agent, as the student last chose it in this browser (read after hydration; Ask until then).
    const [modeChoice, setModeChoice] = useState<ChatMode | null>(null);
    const storedMode =
        hydrated && modeChoice === null && readStorage(MODE_KEY) === 'agent'
            ? 'agent'
            : null;
    const mode: ChatMode = modeChoice ?? storedMode ?? 'ask';
    const setMode = useCallback((next: ChatMode) => {
        setModeChoice(next);
        writeStorage(MODE_KEY, next);
    }, []);
    const [activeAssistantId, setActiveAssistantId] = useState<string | null>(
        null,
    );
    const [councilWsState, setCouncilWsState] =
        useState<CouncilWsState>('connecting');
    const [essayDocumentFor, setEssayDocumentFor] = useState<string | null>(
        null,
    );
    const [draft, setDraft] = useState('');
    const [attachments, setAttachments] = useState<MentorAttachment[]>([]);
    const [quotaModal, setQuotaModal] = useState<{
        open: boolean;
        detail: QuotaFeatureDetail | null;
    }>({ open: false, detail: null });

    // The background reply being followed (see followTurn), and how to stop following it.
    const followAbortRef = useRef<AbortController | null>(null);
    const activeTurnRef = useRef<string | null>(null);
    const [waitingToStart, setWaitingToStart] = useState(false);
    const echoRef = useRef<ReturnType<typeof createLaravelEcho>>(null);
    const councilCleanupsRef = useRef<Map<string, () => void>>(new Map());
    const essayCleanupsRef = useRef<Map<string, () => void>>(new Map());
    const composerRef = useRef<HTMLTextAreaElement | null>(null);
    const restoredRef = useRef(false);
    // Whether the check for a reply still running (after a refresh) is done; see the startup effect.
    const [startupChecked, setStartupChecked] = useState(false);
    const startupRef = useRef(false);

    const quotaHint = useMemo(() => {
        const chat = featureQuotas?.chat;

        if (!chat) {
            return null;
        }

        if (
            chat.mode === 'trial' &&
            typeof chat.conversations_remaining === 'number' &&
            typeof chat.messages_per_convo_limit === 'number'
        ) {
            const remaining = chat.conversations_remaining;

            return {
                text: `Trial: ${remaining} new chat${remaining === 1 ? '' : 's'} left · up to ${chat.messages_per_convo_limit} messages each`,
                warning: false,
            };
        }

        if (
            chat.mode === 'paid' &&
            chat.warning &&
            typeof chat.messages_remaining === 'number' &&
            typeof chat.messages_limit === 'number'
        ) {
            return {
                text: `${chat.messages_remaining} of ${chat.messages_limit} mentor messages left this period`,
                warning: true,
            };
        }

        return null;
    }, [featureQuotas?.chat]);

    const stopTracking = useCallback(() => {
        councilCleanupsRef.current.forEach((fn) => fn());
        councilCleanupsRef.current.clear();
        essayCleanupsRef.current.forEach((fn) => fn());
        essayCleanupsRef.current.clear();
    }, []);

    useEffect(() => stopTracking, [stopTracking]);

    const updateMessage = useCallback(
        (id: string, fn: (m: ChatMessage) => ChatMessage) => {
            setMessages((prev) => prev.map((m) => (m.id === id ? fn(m) : m)));
        },
        [],
    );

    const attachCouncilRun = useCallback(
        (assistantId: string, runId: string) => {
            councilCleanupsRef.current.get(assistantId)?.();
            councilCleanupsRef.current.delete(assistantId);
            setCouncilWsState('connecting');
            updateMessage(assistantId, (m) => ({
                ...m,
                councilFeed: {
                    runId,
                    status: m.councilFeed?.status ?? 'running',
                    lines: m.councilFeed?.lines ?? [],
                },
            }));

            const withFeed = (
                fn: (
                    feed: NonNullable<ChatMessage['councilFeed']>,
                ) => NonNullable<ChatMessage['councilFeed']>,
            ) =>
                updateMessage(assistantId, (m) =>
                    m.councilFeed
                        ? { ...m, councilFeed: fn(m.councilFeed) }
                        : m,
                );

            const applyBroadcast = ({
                type,
                data,
            }: CouncilBroadcastPayload) => {
                if (type === 'preflight_questions') {
                    const questions = Array.isArray(data.questions)
                        ? data.questions.filter(
                              (q): q is string =>
                                  typeof q === 'string' && q.trim() !== '',
                          )
                        : [];
                    const timeoutSeconds =
                        typeof data.timeout_seconds === 'number' &&
                        data.timeout_seconds > 0
                            ? data.timeout_seconds
                            : 120;
                    withFeed((feed) => ({
                        ...feed,
                        status: 'awaiting_preflight',
                        preflightQuestions: questions,
                        preflightTimeoutSeconds: timeoutSeconds,
                    }));
                } else if (
                    type === 'insight' &&
                    String(data.title ?? '') !== ''
                ) {
                    const line: CouncilFeedLine = {
                        id: crypto.randomUUID(),
                        type: 'insight',
                        title: String(data.title),
                        body: data.body ? String(data.body) : undefined,
                        severity:
                            typeof data.severity === 'string'
                                ? data.severity
                                : 'info',
                    };
                    withFeed((feed) => ({
                        ...feed,
                        lines: [...feed.lines, line],
                    }));
                } else if (type === 'status') {
                    const st = String(data.status ?? '');
                    const line: CouncilFeedLine = {
                        id: crypto.randomUUID(),
                        type: 'status',
                        title: `Phase: ${st}`,
                        severity: 'info',
                    };
                    withFeed((feed) => {
                        let status = feed.status;

                        if (st === 'awaiting_preflight') {
                            status = 'awaiting_preflight';
                        } else if (
                            [
                                'queued',
                                'preflight',
                                'scouting',
                                'judging',
                                'assembling',
                            ].includes(st) &&
                            feed.status !== 'completed' &&
                            feed.status !== 'failed'
                        ) {
                            status = 'running';
                        }

                        return {
                            ...feed,
                            status,
                            lines: [...feed.lines, line],
                        };
                    });
                } else if (type === 'completed') {
                    withFeed((feed) => ({ ...feed, status: 'completed' }));
                } else if (type === 'failed') {
                    const err =
                        typeof data.message === 'string' &&
                        data.message.trim() !== ''
                            ? data.message
                            : undefined;
                    withFeed((feed) => ({
                        ...feed,
                        status: 'failed',
                        errorMessage: err ?? feed.errorMessage,
                    }));
                }
            };

            const pollOnce = async (): Promise<boolean> => {
                try {
                    const res = await fetch(
                        `/college-lists/council-runs/${runId}`,
                        {
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        },
                    );

                    if (!res.ok) {
                        return true;
                    }

                    const json = (await res.json()) as {
                        status?: string;
                        error_message?: string | null;
                        events?: Array<{
                            id: string;
                            phase: string;
                            severity: string;
                            title: string;
                            body: string | null;
                        }>;
                        awaiting_preflight?: boolean;
                        preflight_questions?: string[];
                        preflight_timeout_seconds?: number;
                    };

                    if (Array.isArray(json.events) && json.events.length > 0) {
                        const lines: CouncilFeedLine[] = json.events.map(
                            (ev) => ({
                                id: ev.id,
                                type: 'poll',
                                title: ev.title,
                                body: ev.body ?? undefined,
                                severity: ev.severity,
                            }),
                        );
                        withFeed((feed) => ({ ...feed, lines }));
                    }

                    if (
                        json.status === 'awaiting_preflight' ||
                        json.awaiting_preflight
                    ) {
                        withFeed((feed) => ({
                            ...feed,
                            status: 'awaiting_preflight',
                            preflightQuestions: json.preflight_questions?.length
                                ? json.preflight_questions
                                : feed.preflightQuestions,
                            preflightTimeoutSeconds:
                                json.preflight_timeout_seconds ??
                                feed.preflightTimeoutSeconds ??
                                120,
                        }));
                    } else if (
                        json.status &&
                        json.status !== 'completed' &&
                        json.status !== 'failed'
                    ) {
                        withFeed((feed) =>
                            feed.status === 'completed' ||
                            feed.status === 'failed'
                                ? feed
                                : { ...feed, status: 'running' },
                        );
                    }

                    if (
                        json.status === 'completed' ||
                        json.status === 'failed'
                    ) {
                        const err =
                            json.status === 'failed' && json.error_message
                                ? json.error_message
                                : undefined;
                        withFeed((feed) => ({
                            ...feed,
                            status:
                                json.status === 'completed'
                                    ? 'completed'
                                    : 'failed',
                            errorMessage:
                                json.status === 'failed'
                                    ? (err ?? feed.errorMessage)
                                    : undefined,
                        }));

                        return false;
                    }

                    return true;
                } catch {
                    return true;
                }
            };

            let echoCleanup: (() => void) | undefined;

            if (
                councilConfig?.enabled &&
                councilConfig.reverb?.key &&
                csrfToken
            ) {
                try {
                    if (!echoRef.current) {
                        echoRef.current = createLaravelEcho(
                            csrfToken,
                            councilConfig.reverb,
                        );
                    }

                    const echo = echoRef.current;

                    if (echo) {
                        const channel = echo.private(
                            `college-list-runs.${runId}`,
                        );
                        channel.listen(
                            '.council.broadcast',
                            (e: CouncilBroadcastPayload) => applyBroadcast(e),
                        );
                        type PusherConn = {
                            state: string;
                            bind: (ev: string, fn: () => void) => void;
                            unbind: (ev: string, fn: () => void) => void;
                        };
                        const conn = (
                            echo.connector as
                                | { pusher?: { connection?: PusherConn } }
                                | undefined
                        )?.pusher?.connection;

                        if (conn) {
                            setCouncilWsState(
                                conn.state === 'connected'
                                    ? 'connected'
                                    : 'connecting',
                            );
                            const onConnected = () =>
                                setCouncilWsState('connected');
                            const onDisconnected = () =>
                                setCouncilWsState('disconnected');
                            const onConnecting = () =>
                                setCouncilWsState('connecting');
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
                            echoCleanup = () =>
                                channel.stopListening('.council.broadcast');
                        }
                    } else {
                        setCouncilWsState('disconnected');
                    }
                } catch (e) {
                    console.warn('[mentor] Echo subscribe failed', e);
                    setCouncilWsState('disconnected');
                }
            } else {
                setCouncilWsState('disconnected');
            }

            void pollOnce();
            const interval = window.setInterval(() => {
                void pollOnce().then((keepGoing) => {
                    if (!keepGoing) {
                        window.clearInterval(interval);
                    }
                });
            }, 5000);

            councilCleanupsRef.current.set(assistantId, () => {
                window.clearInterval(interval);
                echoCleanup?.();
            });
        },
        [councilConfig, csrfToken, updateMessage],
    );

    const attachEssayRun = useCallback(
        (
            assistantId: string,
            runId: string,
            runType: 'brainstorm' | 'draft',
            options?: { openDocument?: boolean },
        ) => {
            essayCleanupsRef.current.get(assistantId)?.();
            essayCleanupsRef.current.delete(assistantId);

            if (options?.openDocument) {
                setEssayDocumentFor(assistantId);
            }

            updateMessage(assistantId, (m) => {
                const prev = m.essayJob?.runId === runId ? m.essayJob : null;

                return {
                    ...m,
                    essayJob: {
                        runId,
                        runType,
                        status: prev?.status ?? 'running',
                        title: prev?.title ?? null,
                        schoolName: prev?.schoolName ?? null,
                        outputMarkdown: prev?.outputMarkdown ?? null,
                        errorMessage: prev?.errorMessage ?? null,
                    },
                };
            });

            let intervalId: number | undefined;
            const pollOnce = async (): Promise<boolean> => {
                try {
                    const res = await fetch(`/essay-suite/runs/${runId}`, {
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
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
                    updateMessage(assistantId, (m) =>
                        m.essayJob
                            ? {
                                  ...m,
                                  essayJob: {
                                      ...m.essayJob,
                                      status:
                                          st === 'failed'
                                              ? 'failed'
                                              : st === 'completed'
                                                ? 'completed'
                                                : 'running',
                                      title:
                                          json.title_for_card ??
                                          m.essayJob.title,
                                      schoolName:
                                          json.school_name ??
                                          m.essayJob.schoolName,
                                      outputMarkdown:
                                          json.output_markdown ??
                                          m.essayJob.outputMarkdown,
                                      errorMessage:
                                          json.error_message ??
                                          m.essayJob.errorMessage,
                                  },
                              }
                            : m,
                    );

                    return st !== 'completed' && st !== 'failed';
                } catch {
                    return true;
                }
            };

            void pollOnce();
            intervalId = window.setInterval(() => {
                void pollOnce().then((keepGoing) => {
                    if (!keepGoing && intervalId) {
                        window.clearInterval(intervalId);
                        intervalId = undefined;
                    }
                });
            }, 2000);

            essayCleanupsRef.current.set(assistantId, () => {
                if (intervalId) {
                    window.clearInterval(intervalId);
                }
            });
        },
        [updateMessage],
    );

    /** Replace the visible conversation with stored rows and resume tracking its background runs. */
    const showConversation = useCallback(
        (
            id: string | null,
            rows: DashboardChatInitialMessage[],
            meta?: { title?: string | null; updatedAt?: string | null },
        ) => {
            followAbortRef.current?.abort();
            stopTracking();
            const mapped = mapInitialMessages(rows);
            setMessages(mapped);
            setConversationId(id);
            setTitle(meta?.title ?? null);
            setUpdatedAt(
                meta?.updatedAt
                    ? Date.parse(
                          meta.updatedAt.includes('T')
                              ? meta.updatedAt
                              : `${meta.updatedAt.replace(' ', 'T')}Z`,
                      ) || null
                    : null,
            );
            setEssayDocumentFor(null);
            writeStorage(CONVERSATION_KEY, id);

            for (const m of mapped) {
                if (m.role !== 'assistant') {
                    continue;
                }

                if (m.councilFeed?.runId) {
                    attachCouncilRun(m.id, m.councilFeed.runId);
                }

                if (m.essayJob?.runId) {
                    attachEssayRun(m.id, m.essayJob.runId, m.essayJob.runType);
                }
            }
        },
        [attachCouncilRun, attachEssayRun, stopTracking],
    );

    /** A stored conversation's messages, or null when it is not (yet) stored or not the student's. */
    const fetchConversation = useCallback(
        async (id: string): Promise<StoredConversation | null> => {
            const res = await fetch(`/dashboard/chat/${id}/messages`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!res.ok) {
                if (res.status === 403 || res.status === 404) {
                    writeStorage(CONVERSATION_KEY, null);
                }

                return null;
            }

            return (await res.json()) as StoredConversation;
        },
        [],
    );

    const loadConversation = useCallback(
        async (id: string) => {
            setLoadingConversation(true);

            try {
                const json = await fetchConversation(id);

                if (!json) {
                    return;
                }

                showConversation(json.conversation_id, json.messages, {
                    title: json.title,
                    updatedAt: json.updated_at,
                });
            } catch {
                /* offline: keep what is shown */
            } finally {
                setLoadingConversation(false);
            }
        },
        [fetchConversation, showConversation],
    );

    const refreshRecent = useCallback(async () => {
        try {
            const res = await fetch('/dashboard/chats/recent', {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (res.ok) {
                setRecent(
                    (
                        (await res.json()) as {
                            conversations: RecentConversation[];
                        }
                    ).conversations,
                );
            }
        } catch {
            /* offline: keep the list we have */
        }
    }, []);

    // Load the list once the page is interactive.
    useEffect(() => {
        const timer = window.setTimeout(() => void refreshRecent(), 0);

        return () => window.clearTimeout(timer);
    }, [refreshRecent]);

    const newConversation = useCallback(() => {
        showConversation(null, []);
        setDraft('');
        setAttachments([]);
        requestAnimationFrame(() => composerRef.current?.focus());
    }, [showConversation]);

    // A deep link (/dashboard/chat/{id}) brings its conversation into the panel — once per link, so a later
    // reload of the page's props (after an approval, say) never resets a conversation that is in progress.
    const initialId = page.props.initial_conversation_id ?? null;
    const initialRows = page.props.initial_chat_messages;
    const appliedDeepLinkRef = useRef<string | null>(null);
    useEffect(() => {
        if (!initialId) {
            appliedDeepLinkRef.current = null;

            return;
        }

        if (initialRows && initialId !== appliedDeepLinkRef.current) {
            appliedDeepLinkRef.current = initialId;
            restoredRef.current = true;
            showConversation(initialId, initialRows);
            setOpen(true);
        }
    }, [initialId, initialRows, showConversation, setOpen]);

    // Otherwise, the first time the panel is open, continue the last conversation from this browser.
    useEffect(() => {
        if (!open || restoredRef.current || !startupChecked) {
            return;
        }

        restoredRef.current = true;
        const stored = readStorage(CONVERSATION_KEY);

        if (!stored || !/^[0-9a-f-]{36}$/i.test(stored)) {
            return;
        }

        const timer = window.setTimeout(() => void loadConversation(stored), 0);

        return () => window.clearTimeout(timer);
    }, [open, startupChecked, loadConversation]);

    const registerComposer = useCallback((el: HTMLTextAreaElement | null) => {
        composerRef.current = el;
    }, []);

    const compose = useCallback(
        (text?: string) => {
            setOpen(true);

            if (text !== undefined) {
                setDraft(text);
            }

            window.setTimeout(() => {
                const el = composerRef.current;

                if (el) {
                    el.focus();
                    el.setSelectionRange(el.value.length, el.value.length);
                }
            }, 60);
        },
        [setOpen],
    );

    /**
     * Applies one stream part of a reply to its message: text, tool steps, a specialist's live output and
     * activity, and the cards tools produce. Approval and plan cards wait for their data (streamed right after the
     * tool); one whose data never arrives is added by finish() and loads itself.
     */
    const createPartHandler = useCallback(
        (assistantId: string) => {
            const setParts = (fn: (parts: MessagePart[]) => MessagePart[]) =>
                updateMessage(assistantId, (m) => ({
                    ...m,
                    parts: fn(m.parts ?? []),
                }));
            const addCard = (card: CardKind) =>
                setParts((parts) => addCardPart(parts, { kind: 'card', card }));
            const awaitingCards = new Map<
                string,
                Extract<MessagePart, { kind: 'proposal' | 'task' }>
            >();

            const handle = (evt: VercelAiStreamPart) => {
                if (
                    evt.type === 'text-delta' &&
                    typeof evt.delta === 'string'
                ) {
                    const delta = evt.delta;
                    updateMessage(assistantId, (m) => ({
                        ...m,
                        content: m.content + delta,
                        parts: appendTextPart(m.parts ?? [], delta),
                        streamError: undefined,
                    }));

                    return;
                }

                if (evt.type === 'tool-input-available') {
                    const id =
                        String(evt.toolCallId ?? '') || crypto.randomUUID();
                    const name = String(evt.toolName ?? '');
                    const task = (evt.input as { task?: unknown } | undefined)
                        ?.task;
                    const detail =
                        isSubagent(name) && typeof task === 'string'
                            ? task
                            : toolDetail(name, evt.input);
                    setParts((parts) =>
                        addToolPart(parts, {
                            kind: 'tool',
                            id,
                            name,
                            status: 'running',
                            ...(detail ? { detail } : {}),
                        }),
                    );
                }

                const finishedToolId = toolCallIdFromOutput(evt);

                if (finishedToolId && evt.preliminary === true) {
                    // A specialist's output so far (the whole text each time); it is still working.
                    if (typeof evt.output === 'string') {
                        const output = evt.output;
                        setParts((parts) =>
                            updateToolPart(parts, finishedToolId, { output }),
                        );
                    }
                } else if (finishedToolId) {
                    const failed =
                        evt.type === 'tool-output-error' ||
                        evt.type === 'tool-output-denied';
                    const text =
                        typeof evt.output === 'string' ? evt.output : null;
                    setParts((parts) => {
                        const part = parts.find(
                            (p) => p.kind === 'tool' && p.id === finishedToolId,
                        );

                        if (part?.kind === 'tool' && isSubagent(part.name)) {
                            const agentFailed =
                                failed ||
                                (text ?? '').startsWith('Agent failed:');

                            return updateToolPart(parts, finishedToolId, {
                                status: agentFailed ? 'failed' : 'done',
                                ...(text !== null ? { output: text } : {}),
                            });
                        }

                        const sources = failed
                            ? []
                            : sourcesFromToolOutput(evt.output);

                        return updateToolPart(parts, finishedToolId, {
                            status: failed ? 'failed' : 'done',
                            ...(sources.length > 0 ? { sources } : {}),
                        });
                    });
                }

                const search = webSearchUpdate(evt);

                if (search) {
                    setParts((parts) => {
                        const patch = {
                            status: search.status,
                            ...(search.detail ? { detail: search.detail } : {}),
                            ...(search.sources?.length
                                ? { sources: search.sources }
                                : {}),
                        };

                        return parts.some(
                            (p) => p.kind === 'tool' && p.id === search.id,
                        )
                            ? updateToolPart(parts, search.id, patch)
                            : addToolPart(parts, {
                                  kind: 'tool',
                                  id: search.id,
                                  name: 'web_search',
                                  ...patch,
                              });
                    });
                }

                const out = parseToolOutput(evt);

                if (out) {
                    if (
                        out.ok === true &&
                        out.council_started === true &&
                        typeof out.run_id === 'string' &&
                        out.run_id !== ''
                    ) {
                        attachCouncilRun(assistantId, out.run_id);
                        addCard('council');
                    }

                    if (
                        out.ok === true &&
                        out.essay_job_started === true &&
                        typeof out.run_id === 'string' &&
                        out.run_id !== ''
                    ) {
                        attachEssayRun(
                            assistantId,
                            out.run_id,
                            out.run_type === 'draft' ? 'draft' : 'brainstorm',
                            { openDocument: true },
                        );
                        addCard('essay');
                    }

                    if (out.gap_fix_started) {
                        updateMessage(assistantId, (m) => ({
                            ...m,
                            gapFixData: {
                                schoolNames: (Array.isArray(out.school_names)
                                    ? out.school_names
                                    : []) as string[],
                                schoolCount:
                                    typeof out.school_count === 'number'
                                        ? out.school_count
                                        : 0,
                                actionUrl:
                                    typeof out.action_url === 'string'
                                        ? out.action_url
                                        : '/gap-fix',
                                analysisId:
                                    typeof out.analysis_id === 'string'
                                        ? out.analysis_id
                                        : '',
                            },
                        }));
                        addCard('gapFix');
                    }

                    if (out.roast_ready) {
                        updateMessage(assistantId, (m) => ({
                            ...m,
                            essayRoastData: {
                                overallVerdict:
                                    typeof out.overall_verdict === 'string'
                                        ? out.overall_verdict
                                        : '',
                                scores: (out.scores &&
                                typeof out.scores === 'object'
                                    ? out.scores
                                    : {}) as Record<
                                    string,
                                    { score: number; reasoning: string }
                                >,
                                clicheCount:
                                    typeof out.cliche_count === 'number'
                                        ? out.cliche_count
                                        : 0,
                                topPriorityFix:
                                    typeof out.top_priority_fix === 'string'
                                        ? out.top_priority_fix
                                        : '',
                                actionUrl:
                                    typeof out.action_url === 'string'
                                        ? out.action_url
                                        : '/essay-suite',
                            },
                        }));
                        addCard('roast');
                    }

                    if (out.why_school_ready) {
                        updateMessage(assistantId, (m) => ({
                            ...m,
                            whySchoolData: {
                                schoolName:
                                    typeof out.school_name === 'string'
                                        ? out.school_name
                                        : '',
                                draftPreview:
                                    typeof out.draft_preview === 'string'
                                        ? out.draft_preview
                                        : '',
                                hooksUsed: (Array.isArray(out.hooks_used)
                                    ? out.hooks_used
                                    : []) as string[],
                                actionUrl:
                                    typeof out.action_url === 'string'
                                        ? out.action_url
                                        : '/essay-suite',
                            },
                        }));
                        addCard('whySchool');
                    }

                    // A proposal waits for the student's decision; nothing has changed yet.
                    if (
                        out.status === 'pending_approval' &&
                        typeof out.proposal_id === 'string'
                    ) {
                        awaitingCards.set(out.proposal_id, {
                            kind: 'proposal',
                            id: out.proposal_id,
                        });
                    }

                    if (
                        (out.status === 'tasks_started' ||
                            out.status === 'task_updated') &&
                        typeof out.goal_task_id === 'string'
                    ) {
                        awaitingCards.set(out.goal_task_id, {
                            kind: 'task',
                            id: out.goal_task_id,
                        });
                    }
                }

                const payload = evt.data as
                    | {
                          proposal?: ProposalData;
                          goal?: TaskGoal;
                          toolCallId?: string;
                          activity?: SubagentActivityEntry[];
                      }
                    | undefined;

                if (evt.type === 'data-proposal' && payload?.proposal) {
                    const proposal = payload.proposal;
                    awaitingCards.delete(proposal.id);
                    setParts((parts) =>
                        addCardPart(parts, {
                            kind: 'proposal',
                            id: proposal.id,
                            data: proposal,
                        }),
                    );
                }

                if (evt.type === 'data-task-goal' && payload?.goal) {
                    const goal = payload.goal;
                    awaitingCards.delete(goal.id);
                    setParts((parts) =>
                        addCardPart(parts, {
                            kind: 'task',
                            id: goal.id,
                            data: goal,
                        }),
                    );
                }

                // What a specialist is doing: its searches and what they found.
                if (
                    evt.type === 'data-subagent-activity' &&
                    payload?.toolCallId &&
                    Array.isArray(payload.activity)
                ) {
                    const toolId = payload.toolCallId;
                    const activity = payload.activity;
                    setParts((parts) =>
                        updateToolPart(parts, toolId, { activity }),
                    );
                }

                if (
                    evt.type === 'data-conversation-id' &&
                    evt.data?.conversationId
                ) {
                    const cid = evt.data.conversationId;
                    conversationIdRef.current = cid;
                    setConversationId(cid);
                    writeStorage(CONVERSATION_KEY, cid);
                }
            };

            const finish = () => {
                for (const part of awaitingCards.values()) {
                    setParts((parts) => addCardPart(parts, part));
                }
            };

            return { handle, finish };
        },
        [attachCouncilRun, attachEssayRun, updateMessage],
    );

    /**
     * Follow a background reply: read its parts in order with short requests until it has finished. The page
     * stays usable meanwhile, and a refreshed page can follow the same reply again from the start.
     */
    const followTurn = useCallback(
        async (turnId: string, assistantId: string) => {
            followAbortRef.current?.abort();
            const controller = new AbortController();
            followAbortRef.current = controller;
            activeTurnRef.current = turnId;
            setActiveAssistantId(assistantId);
            setIsTyping(true);

            const handler = createPartHandler(assistantId);
            const startedAt = Date.now();
            let cursor = 0;
            let failures = 0;
            let finalStatus: MentorTurnInfo['status'] = 'running';
            let lastTurn: MentorTurnInfo | null = null;

            try {
                for (;;) {
                    let data: {
                        turn: MentorTurnInfo;
                        events: VercelAiStreamPart[];
                        cursor: number;
                    };
                    const requestedAt = performance.now();

                    try {
                        const res = await fetch(
                            `/dashboard/chat/turns/${turnId}/events?after=${cursor}`,
                            {
                                credentials: 'same-origin',
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                signal: controller.signal,
                            },
                        );

                        if (res.status === 404) {
                            finalStatus = 'failed';

                            break;
                        }

                        if (!res.ok) {
                            throw new Error(`HTTP ${res.status}`);
                        }

                        data = (await res.json()) as typeof data;
                        failures = 0;
                    } catch (e) {
                        if (controller.signal.aborted) {
                            return;
                        }

                        // Offline or a hiccup: keep trying for a while; the reply keeps running on the server.
                        failures += 1;

                        if (failures > 30) {
                            finalStatus = 'failed';
                            console.error('[mentor] Lost the reply', e);

                            break;
                        }

                        await sleep(Math.min(4000, 300 * failures));

                        continue;
                    }

                    for (const evt of data.events) {
                        handler.handle(evt);
                    }

                    cursor = data.cursor;
                    lastTurn = data.turn;

                    const cid = data.turn.conversation_id;

                    // A new conversation is stored when its reply ends; this browser remembers it after that.
                    if (cid && cid !== conversationIdRef.current) {
                        conversationIdRef.current = cid;
                        setConversationId(cid);
                    }

                    const running =
                        data.turn.status === 'queued' ||
                        data.turn.status === 'running';
                    setWaitingToStart(
                        data.turn.status === 'queued' &&
                            Date.now() - startedAt > 4000,
                    );

                    if (!running && data.events.length === 0) {
                        finalStatus = data.turn.status;

                        break;
                    }

                    if (data.events.length >= 400) {
                        continue;
                    }

                    // Read again soon while the reply streams, but never keep a slow server busy: wait at least
                    // as long as the last read took, so page loads and other requests get their turn.
                    const took = performance.now() - requestedAt;
                    const base = data.events.length > 0 ? 150 : 400;
                    await sleep(
                        running
                            ? Math.min(3000, Math.max(base, took * 1.2))
                            : 0,
                    );
                }

                handler.finish();

                if (lastTurn?.conversation_id && lastTurn.conversation_stored) {
                    writeStorage(CONVERSATION_KEY, lastTurn.conversation_id);
                }

                if (finalStatus === 'failed') {
                    updateMessage(assistantId, (m) => ({
                        ...m,
                        streamError: 'failed',
                        ...(m.content.trim() === ''
                            ? {
                                  content:
                                      'Something went wrong. Use **Retry** below or try again in a moment.',
                                  parts: appendTextPart(
                                      m.parts ?? [],
                                      'Something went wrong. Use **Retry** below or try again in a moment.',
                                  ),
                              }
                            : {}),
                    }));
                }
            } finally {
                if (followAbortRef.current === controller) {
                    followAbortRef.current = null;
                    activeTurnRef.current = null;
                    setIsTyping(false);
                    setActiveAssistantId(null);
                    setWaitingToStart(false);
                    setUpdatedAt(Date.now());
                    void refreshRecent();
                }
            }
        },
        [createPartHandler, refreshRecent, updateMessage],
    );

    /** Stop the reply: the server ends it with "Generation stopped." within a moment, which the chat still reads. */
    const abort = useCallback(() => {
        const turnId = activeTurnRef.current;

        if (!turnId) {
            return;
        }

        void fetch(`/dashboard/chat/turns/${turnId}/cancel`, {
            method: 'POST',
            headers: buildLaravelJsonHeaders(csrfToken),
            credentials: 'same-origin',
        }).catch(() => {
            /* the reply finishes on its own */
        });
    }, [csrfToken]);

    const send = useCallback(
        async (text: string, options?: SendOptions) => {
            const trimmed = text.trim();

            if (!trimmed || isTyping) {
                return;
            }

            setOpen(true);

            const assistantId = crypto.randomUUID();
            const replacingId = options?.replaceAssistantId;
            const sentAttachments = options?.attachments ?? [];

            setActiveAssistantId(assistantId);

            if (replacingId) {
                setMessages((prev) => [
                    ...prev.filter((m) => m.id !== replacingId),
                    {
                        id: assistantId,
                        role: 'assistant',
                        content: '',
                        parts: [],
                    },
                ]);
            } else {
                const userMsg: ChatMessage = {
                    id: crypto.randomUUID(),
                    role: 'user',
                    content: trimmed,
                    attachments:
                        sentAttachments.length > 0
                            ? sentAttachments.map((a) => a.name)
                            : undefined,
                };
                setMessages((prev) => [
                    ...prev,
                    userMsg,
                    {
                        id: assistantId,
                        role: 'assistant',
                        content: '',
                        parts: [],
                    },
                ]);
            }

            setIsTyping(true);
            setUpdatedAt(Date.now());

            const fail = (
                content: string,
                streamError: ChatMessage['streamError'],
            ) =>
                updateMessage(assistantId, (m) => ({
                    ...m,
                    content,
                    parts: [{ kind: 'text', text: content }],
                    streamError,
                }));
            let following = false;

            try {
                const res = await fetch('/dashboard/chat/turns', {
                    method: 'POST',
                    headers: buildLaravelJsonHeaders(csrfToken),
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        message: trimmed,
                        conversation_id: conversationIdRef.current,
                        mode,
                        ...(sentAttachments.length > 0
                            ? { document_ids: sentAttachments.map((a) => a.id) }
                            : {}),
                    }),
                });

                if (res.status === 403) {
                    fail(
                        'You do not have access to this conversation.',
                        'failed',
                    );

                    return;
                }

                if (
                    res.status === 409 ||
                    res.status === 429 ||
                    res.status === 422
                ) {
                    let body: { message?: string; quota?: QuotaFeatureDetail } =
                        {};

                    try {
                        body = (await res.json()) as typeof body;
                    } catch {
                        /* ignore */
                    }

                    if (res.status === 429) {
                        fail(
                            typeof body.message === 'string' &&
                                body.message !== ''
                                ? body.message
                                : "You've reached your mentor message limit for your plan.",
                            'quota',
                        );

                        if (body.quota) {
                            setQuotaModal({ open: true, detail: body.quota });
                        }
                    } else {
                        fail(
                            body.message ??
                                'The mentor is still answering. Wait for that reply, then send again.',
                            'failed',
                        );
                    }

                    return;
                }

                if (!res.ok) {
                    throw new Error(
                        `The mentor could not start (${res.status}).`,
                    );
                }

                const { turn } = (await res.json()) as { turn: MentorTurnInfo };

                if (
                    turn.conversation_id &&
                    turn.conversation_id !== conversationIdRef.current
                ) {
                    conversationIdRef.current = turn.conversation_id;
                    setConversationId(turn.conversation_id);
                    writeStorage(CONVERSATION_KEY, turn.conversation_id);
                }

                following = true;
                await followTurn(turn.id, assistantId);
            } catch (e) {
                const msg = e instanceof Error ? e.message : String(e);
                console.error(`[mentor] Request failed: ${msg}`);
                const safeHint =
                    msg.length > 0 &&
                    msg.length < 500 &&
                    !msg.includes('<!DOCTYPE') &&
                    !msg.includes('<html')
                        ? msg
                        : null;
                updateMessage(assistantId, (m) => {
                    if (m.content.trim() !== '') {
                        return { ...m, streamError: 'failed' };
                    }

                    const note =
                        safeHint ??
                        'Something went wrong. Use **Retry** below or try again in a moment.';

                    return {
                        ...m,
                        content: note,
                        parts: appendTextPart(m.parts ?? [], note),
                        streamError: 'failed',
                    };
                });
            } finally {
                if (!following) {
                    setIsTyping(false);
                    setActiveAssistantId(null);
                    setUpdatedAt(Date.now());
                }
            }
        },
        [csrfToken, followTurn, isTyping, mode, setOpen, updateMessage],
    );

    // A reply still running from before a refresh (or from another tab): show it in its conversation and keep
    // following it. Checked once, before the panel continues the last conversation from this browser.
    useEffect(() => {
        if (!hydrated || startupRef.current) {
            return;
        }

        startupRef.current = true;

        void (async () => {
            try {
                const res = await fetch('/dashboard/chat/turns/active', {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const body = res.ok
                    ? ((await res.json()) as {
                          turn: MentorTurnInfo | null;
                          conversation?: StoredConversation | null;
                      })
                    : null;
                const turn = body?.turn ?? null;

                if (!turn) {
                    return;
                }

                restoredRef.current = true;
                // Its conversation's earlier messages come with it (none yet when the conversation is new).
                const stored = body?.conversation ?? null;

                if (stored) {
                    showConversation(stored.conversation_id, stored.messages, {
                        title: stored.title,
                        updatedAt: stored.updated_at,
                    });
                } else {
                    showConversation(null, []);
                }

                if (turn.conversation_id) {
                    conversationIdRef.current = turn.conversation_id;
                    setConversationId(turn.conversation_id);
                }

                const assistantId = crypto.randomUUID();
                setMessages((prev) => [
                    ...prev,
                    {
                        id: crypto.randomUUID(),
                        role: 'user',
                        content: turn.message,
                        attachments:
                            turn.attachments.length > 0
                                ? turn.attachments
                                : undefined,
                    },
                    {
                        id: assistantId,
                        role: 'assistant',
                        content: '',
                        parts: [],
                    },
                ]);
                setOpen(true);
                await followTurn(turn.id, assistantId);
            } catch {
                /* offline: nothing to pick up */
            } finally {
                setStartupChecked(true);
            }
        })();
    }, [hydrated, followTurn, setOpen, showConversation]);

    // Name a new conversation after its first question until the server's title is known.
    const shownTitle =
        title ??
        messages.find((m) => m.role === 'user')?.content.slice(0, 80) ??
        null;

    const value = useMemo<MentorContextValue>(
        () => ({
            open,
            setOpen,
            toggle,
            conversationId,
            title: shownTitle,
            updatedAt,
            messages,
            setMessages,
            loadingConversation,
            recent,
            refreshRecent,
            isTyping,
            activeAssistantId,
            waitingToStart,
            mode,
            setMode,
            send,
            abort,
            newConversation,
            loadConversation,
            councilWsState,
            essayDocumentFor,
            setEssayDocumentFor,
            draft,
            setDraft,
            attachments,
            setAttachments,
            compose,
            registerComposer,
            quotaHint,
            quotaModal,
            setQuotaModalOpen: (value: boolean) =>
                setQuotaModal((q) => ({ ...q, open: value })),
            plan: featureQuotas?.plan,
            csrfToken,
        }),
        [
            open,
            setOpen,
            toggle,
            conversationId,
            shownTitle,
            updatedAt,
            messages,
            loadingConversation,
            recent,
            refreshRecent,
            isTyping,
            activeAssistantId,
            waitingToStart,
            mode,
            setMode,
            send,
            abort,
            newConversation,
            loadConversation,
            councilWsState,
            essayDocumentFor,
            draft,
            attachments,
            compose,
            registerComposer,
            quotaHint,
            quotaModal,
            featureQuotas?.plan,
            csrfToken,
        ],
    );

    return (
        <MentorContext.Provider value={value}>
            {children}
        </MentorContext.Provider>
    );
}
