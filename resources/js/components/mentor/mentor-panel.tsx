'use client';

import { Link, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    ClockCounterClockwise,
    DownloadSimple,
    NotePencil,
    X,
} from '@phosphor-icons/react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { LogoMark } from '@/components/brand/logo-mark';
import { ProseMarkdown } from '@/components/markdown/prose-markdown';
import { QuotaExhaustedModal } from '@/components/quota-exhausted-modal';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { MentorComposer } from './mentor-composer';
import { useMentor } from './mentor-context';
import { AssistantMessage, UserMessage } from './mentor-message';
import { relativeTime } from './mentor-types';
import type { ChatMessage } from './mentor-types';

/** Suggestions that fit the page the student is looking at. */
function promptsFor(path: string): string[] {
    if (path.startsWith('/college-lists')) {
        return [
            'Which colleges on my list fit my budget?',
            'Is my list balanced between reach, match and safety?',
            'What do my colleges require from international students?',
        ];
    }

    if (path.startsWith('/applications/')) {
        return [
            'What is left to do on this application?',
            'Which deadline comes first and what should I do this week?',
            'Who still needs to send something for me?',
        ];
    }

    if (path.startsWith('/applications')) {
        return [
            'Which application should I work on first?',
            'What is blocking my applications?',
            'Summarise my deadlines for the next month.',
        ];
    }

    if (path.startsWith('/documents')) {
        return [
            'What did you read from my transcript?',
            'Which document values still need my review?',
            'Which documents am I missing for my applications?',
        ];
    }

    if (path.startsWith('/writing') || path.startsWith('/essay')) {
        return [
            'Which essays do my colleges ask for?',
            'How should I start my personal statement?',
            'What do my colleges allow AI help with?',
        ];
    }

    if (path.startsWith('/profile')) {
        return [
            'What is missing from my profile?',
            'What do you know about me so far?',
            'Which of my details are unconfirmed?',
        ];
    }

    return [
        'What should I do next for my applications?',
        'What is missing from my profile?',
        'Which deadlines are coming up?',
    ];
}

function conversationMarkdown(messages: ChatMessage[]): string {
    const lines = ['# Mentor chat', ''];

    for (const m of messages) {
        if (m.content.trim() !== '') {
            lines.push(
                m.role === 'user' ? '## You' : '## Mentor',
                '',
                m.content.trim(),
                '',
            );
        }
    }

    return lines.join('\n').trimEnd();
}

function HeaderButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick?: () => void;
    children: React.ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={onClick}
                    aria-label={label}
                    className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{label}</TooltipContent>
        </Tooltip>
    );
}

function HistoryMenu() {
    const {
        loadConversation,
        conversationId,
        messages,
        recent,
        refreshRecent,
    } = useMentor();

    const download = () => {
        const blob = new Blob([conversationMarkdown(messages)], {
            type: 'text/markdown;charset=utf-8',
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `mentor-chat-${new Date().toISOString().slice(0, 10)}.md`;
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <DropdownMenu onOpenChange={(open) => open && void refreshRecent()}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            aria-label="Chat history"
                            className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground data-[state=open]:bg-muted data-[state=open]:text-foreground"
                        >
                            <ClockCounterClockwise className="size-[18px]" />
                        </button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent side="bottom">Chat history</TooltipContent>
            </Tooltip>
            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel className="px-2 py-1.5 text-[12px] font-normal text-muted-foreground">
                    Recent chats
                </DropdownMenuLabel>
                {recent.length === 0 ? (
                    <p className="px-2 py-2 text-xs text-muted-foreground">
                        No saved chats yet.
                    </p>
                ) : null}
                <div className="max-h-72 scrollbar-thin overflow-y-auto">
                    {recent.map((item) => (
                        <DropdownMenuItem
                            key={item.id}
                            onSelect={() => void loadConversation(item.id)}
                            className="flex-col items-start gap-0"
                        >
                            <span
                                className={cn(
                                    'w-full truncate text-[13px]',
                                    item.id === conversationId && 'font-medium',
                                )}
                            >
                                {item.title || 'Untitled chat'}
                            </span>
                            <span className="text-[11px] text-muted-foreground">
                                {relativeTime(item.updated_at) ?? ''}
                            </span>
                        </DropdownMenuItem>
                    ))}
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    disabled={messages.length === 0}
                    onSelect={download}
                    className="gap-2"
                >
                    <DownloadSimple className="size-4" />
                    Download this chat (.md)
                </DropdownMenuItem>
                <DropdownMenuItem asChild className="gap-2">
                    <Link href="/dashboard/chats">
                        <ClockCounterClockwise className="size-4" />
                        All chats
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function useMinuteTick(): void {
    const [, setTick] = useState(0);
    useEffect(() => {
        const id = window.setInterval(() => setTick((t) => t + 1), 60000);

        return () => window.clearInterval(id);
    }, []);
}

export function MentorPanel({
    variant,
    className,
}: {
    variant: 'docked' | 'overlay';
    className?: string;
}) {
    const mentor = useMentor();
    const { messages, isTyping, activeAssistantId, send, title } = mentor;
    const page = usePage<{ auth: { user: { name: string } | null } }>();
    const path = page.url.split('?')[0];
    const firstName = page.props.auth.user?.name?.split(' ')[0];
    useMinuteTick();

    const scrollRef = useRef<HTMLDivElement>(null);
    const [atBottom, setAtBottom] = useState(true);

    const onScroll = useCallback(() => {
        const el = scrollRef.current;

        if (el) {
            setAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 120);
        }
    }, []);

    useLayoutEffect(() => {
        const el = scrollRef.current;

        if (el && atBottom) {
            el.scrollTop = el.scrollHeight;
        }
    }, [messages, atBottom]);

    const lastAssistant = [...messages]
        .reverse()
        .find((m) => m.role === 'assistant')?.id;
    const essayMessage = messages.find(
        (m) => m.id === mentor.essayDocumentFor,
    )?.essayJob;

    const retryFor = (msg: ChatMessage) => {
        const index = messages.findIndex((m) => m.id === msg.id);
        const question = [...messages.slice(0, index)]
            .reverse()
            .find((m) => m.role === 'user');

        if (question) {
            void send(question.content, { replaceAssistantId: msg.id });
        }
    };

    return (
        <section
            aria-label="Mentor"
            className={cn(
                'flex h-full min-h-0 w-full flex-col bg-background',
                className,
            )}
        >
            <header className="flex h-14 shrink-0 items-center gap-2 pr-2.5 pl-4">
                <p className="min-w-0 flex-1 truncate text-[15px] font-medium text-foreground">
                    {messages.length > 0 ? (title ?? 'Chat') : 'New chat'}
                </p>
                <HeaderButton label="New chat" onClick={mentor.newConversation}>
                    <NotePencil className="size-[18px]" />
                </HeaderButton>
                <HistoryMenu />
                <HeaderButton
                    label="Close"
                    onClick={() => mentor.setOpen(false)}
                >
                    <X className="size-[18px]" />
                </HeaderButton>
            </header>

            <div className="relative min-h-0 flex-1">
                <div
                    ref={scrollRef}
                    onScroll={onScroll}
                    className="h-full scrollbar-thin overflow-y-auto px-4 pt-2 pb-6"
                >
                    {mentor.loadingConversation ? (
                        <div
                            className="space-y-4"
                            aria-label="Loading conversation"
                        >
                            <div className="ml-auto h-10 w-2/3 animate-pulse rounded-[20px] bg-muted" />
                            <div className="h-4 w-1/2 animate-pulse rounded bg-muted" />
                            <div className="h-20 w-full animate-pulse rounded-xl bg-muted" />
                        </div>
                    ) : messages.length === 0 ? (
                        <div className="flex h-full flex-col justify-end">
                            <span className="mb-4 flex size-9 items-center justify-center rounded-xl bg-foreground text-background">
                                <LogoMark className="size-4" />
                            </span>
                            <p className="text-[20px] leading-snug font-semibold tracking-tight text-foreground">
                                How can I help
                                {firstName ? `, ${firstName}` : ''}?
                            </p>
                            <p className="mt-1 text-[13.5px] leading-relaxed text-muted-foreground">
                                I know your saved profile, list, documents and
                                applications. Ask only reads; Agent proposes
                                every change for your approval.
                            </p>
                            <ul className="mt-5 space-y-1">
                                {promptsFor(path).map((prompt) => (
                                    <li key={prompt}>
                                        <button
                                            type="button"
                                            onClick={() => void send(prompt)}
                                            className="group flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left text-[13.5px] text-foreground/85 transition-colors hover:bg-muted"
                                        >
                                            <span className="flex-1">
                                                {prompt}
                                            </span>
                                            <ArrowRight className="size-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <div className="space-y-7">
                            {messages.map((msg) =>
                                msg.role === 'user' ? (
                                    <UserMessage key={msg.id} message={msg} />
                                ) : (
                                    <AssistantMessage
                                        key={msg.id}
                                        message={msg}
                                        live={
                                            isTyping &&
                                            activeAssistantId === msg.id
                                        }
                                        waiting={
                                            mentor.waitingToStart &&
                                            activeAssistantId === msg.id
                                        }
                                        onRetry={
                                            msg.streamError ||
                                            msg.id === lastAssistant
                                                ? () => retryFor(msg)
                                                : undefined
                                        }
                                        csrfToken={mentor.csrfToken}
                                        councilWsState={mentor.councilWsState}
                                        onCouncilReplySent={() =>
                                            mentor.setMessages((prev) =>
                                                prev.map((m) =>
                                                    m.id === msg.id &&
                                                    m.councilFeed
                                                        ? {
                                                              ...m,
                                                              councilFeed: {
                                                                  ...m.councilFeed,
                                                                  status: 'running',
                                                                  preflightQuestions:
                                                                      [],
                                                              },
                                                          }
                                                        : m,
                                                ),
                                            )
                                        }
                                        onOpenEssay={() =>
                                            mentor.setEssayDocumentFor(msg.id)
                                        }
                                    />
                                ),
                            )}
                        </div>
                    )}
                </div>
                {!atBottom && messages.length > 0 ? (
                    <button
                        type="button"
                        onClick={() => {
                            const el = scrollRef.current;
                            el?.scrollTo({
                                top: el.scrollHeight,
                                behavior: 'smooth',
                            });
                        }}
                        className="absolute bottom-3 left-1/2 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1.5 text-xs font-medium text-foreground shadow-raised"
                    >
                        <ArrowDown className="size-3.5" />
                        Latest
                    </button>
                ) : null}
            </div>

            <MentorComposer autoFocus={variant === 'overlay'} />

            <Dialog
                open={essayMessage !== undefined}
                onOpenChange={(open) =>
                    !open && mentor.setEssayDocumentFor(null)
                }
            >
                <DialogContent className="max-h-[85vh] overflow-hidden sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {essayMessage?.runType === 'draft'
                                ? 'Essay draft'
                                : 'Essay brainstorm'}
                        </DialogTitle>
                        <DialogDescription>
                            {essayMessage?.schoolName ??
                                essayMessage?.title ??
                                'From your mentor chat'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="max-h-[60vh] scrollbar-thin overflow-y-auto pr-1">
                        {!essayMessage ? null : essayMessage.status ===
                          'running' ? (
                            <p className="text-sm text-muted-foreground">
                                Still writing… this updates on its own.
                            </p>
                        ) : essayMessage.status === 'failed' ? (
                            <p className="text-sm text-destructive">
                                {essayMessage.errorMessage ??
                                    'This run failed.'}
                            </p>
                        ) : essayMessage.outputMarkdown ? (
                            <ProseMarkdown
                                content={essayMessage.outputMarkdown}
                                className="text-sm"
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No output yet.
                            </p>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <QuotaExhaustedModal
                open={mentor.quotaModal.open}
                onOpenChange={mentor.setQuotaModalOpen}
                quota={mentor.quotaModal.detail}
                plan={mentor.plan}
            />
        </section>
    );
}
