'use client';

import {
    ChatCircleText,
    Check,
    CircleNotch,
    FileText,
    FolderOpen,
    Lightning,
    PaperPlaneRight,
    Plus,
    Stop,
    UploadSimple,
    WarningCircle,
    X,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ChangeEvent, DragEvent, KeyboardEvent } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    HoverCard,
    HoverCardContent,
    HoverCardTrigger,
} from '@/components/ui/hover-card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { buildLaravelJsonHeaders } from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import { useMentor } from './mentor-context';
import { formatBytes } from './mentor-types';
import type { ChatMode, MentorAttachment } from './mentor-types';

const MAX_ATTACHMENTS = 4;
const ACCEPT = '.pdf,.docx,.txt,.png,.jpg,.jpeg';

const MODE_HINT: Record<ChatMode, string> = {
    ask: 'Ask reads your profile, list and applications. It cannot change anything.',
    agent: 'Agent can start work and propose changes. Nothing changes until you approve.',
};

type Uploading = { tempId: string; name: string; error: string | null };

/** A sensible starting type from the file name; the student can change it on the Documents page. */
function guessKind(name: string): string {
    const n = name.toLowerCase();

    if (/transcript|grade|report card|marksheet|results/.test(n)) {
        return 'transcript';
    }

    if (/\b(sat|act|toefl|ielts|duolingo|ap)\b|score/.test(n)) {
        return 'test_score_report';
    }

    if (/resume|cv|activit/.test(n)) {
        return 'resume';
    }

    if (/recommend|reference/.test(n)) {
        return 'recommendation';
    }

    return 'other';
}

const reading = (a: MentorAttachment) =>
    a.status === 'queued' || a.status === 'processing';

function fileTone(nameOrMime: string | null | undefined): string {
    const v = (nameOrMime ?? '').toLowerCase();

    if (v.includes('pdf')) {
        return 'text-red-500';
    }

    if (v.includes('word') || v.includes('docx')) {
        return 'text-blue-500';
    }

    if (
        v.includes('png') ||
        v.includes('jpg') ||
        v.includes('jpeg') ||
        v.includes('image')
    ) {
        return 'text-sky-500';
    }

    return 'text-muted-foreground';
}

function AttachmentCard({
    attachment,
    onRemove,
}: {
    attachment: MentorAttachment;
    onRemove: () => void;
}) {
    const ext = (attachment.name.split('.').pop() ?? '').toUpperCase();
    const meta = [
        ext,
        formatBytes(attachment.sizeBytes),
        attachment.pages
            ? `${attachment.pages} page${attachment.pages === 1 ? '' : 's'}`
            : null,
    ].filter(Boolean);
    const failed = attachment.status === 'failed';

    return (
        <HoverCard>
            <HoverCardTrigger asChild>
                <li className="relative flex max-w-[15rem] items-center gap-2.5 rounded-xl bg-muted py-2 pr-3 pl-2">
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background shadow-card">
                        {reading(attachment) ? (
                            <CircleNotch
                                className="size-4 animate-spin text-muted-foreground"
                                aria-label="Still being read"
                            />
                        ) : failed ? (
                            <WarningCircle
                                className="size-4 text-warning"
                                aria-label="Could not be read"
                            />
                        ) : (
                            <FileText
                                className={cn(
                                    'size-4',
                                    fileTone(
                                        attachment.mime ?? attachment.name,
                                    ),
                                )}
                                weight="duotone"
                            />
                        )}
                    </span>
                    <span className="min-w-0">
                        <span className="block truncate text-[13px] font-medium text-foreground">
                            {attachment.name}
                        </span>
                        <span className="block truncate text-[11.5px] text-muted-foreground">
                            {reading(attachment)
                                ? 'Reading…'
                                : failed
                                  ? 'Could not be read'
                                  : meta.join(' · ')}
                        </span>
                    </span>
                    <button
                        type="button"
                        onClick={onRemove}
                        aria-label={`Remove ${attachment.name}`}
                        className="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full border border-border bg-background text-muted-foreground shadow-card hover:text-foreground"
                    >
                        <X className="size-2.5" weight="bold" />
                    </button>
                </li>
            </HoverCardTrigger>
            <HoverCardContent className="w-[19rem]">
                <p className="truncate text-[14.5px] font-medium text-foreground">
                    {attachment.name}
                </p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                    {[attachment.kindLabel, ...meta].join(' · ')}
                </p>
                <div className="mt-2.5 rounded-xl bg-muted p-3">
                    {failed ? (
                        <p className="text-[12.5px] text-muted-foreground">
                            Could not be read:{' '}
                            {attachment.failureReason ?? 'unknown reason'}. The
                            mentor is told it has no text.
                        </p>
                    ) : reading(attachment) ? (
                        <p className="text-[12.5px] text-muted-foreground">
                            Still being read. If you send now, the mentor is
                            told the text is not ready yet.
                        </p>
                    ) : attachment.preview ? (
                        <>
                            <p className="mb-1 text-[11.5px] font-medium text-muted-foreground">
                                From page 1
                            </p>
                            <p className="max-h-28 overflow-hidden fade-bottom text-[12.5px] leading-relaxed whitespace-pre-line text-foreground/80">
                                {attachment.preview}
                            </p>
                        </>
                    ) : (
                        <p className="text-[12.5px] text-muted-foreground">
                            No readable text on the first page.
                        </p>
                    )}
                </div>
                <p className="mt-2 text-[11.5px] text-muted-foreground">
                    Saved in your Documents. Its text is sent with this message
                    only.
                </p>
            </HoverCardContent>
        </HoverCard>
    );
}

/** Ask / Agent, where the reference shows the model. */
function ModeToggle({
    mode,
    onChange,
    disabled,
}: {
    mode: ChatMode;
    onChange: (mode: ChatMode) => void;
    disabled?: boolean;
}) {
    return (
        <div
            role="radiogroup"
            aria-label="Mentor mode"
            className="flex shrink-0 items-center rounded-full border border-border p-0.5"
        >
            {(['ask', 'agent'] as const).map((value) => {
                const ModeIcon = value === 'ask' ? ChatCircleText : Lightning;
                const active = mode === value;

                return (
                    <Tooltip key={value}>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                role="radio"
                                aria-checked={active}
                                disabled={disabled}
                                onClick={() => onChange(value)}
                                className={cn(
                                    'inline-flex h-7 items-center gap-1 rounded-full px-2.5 text-[12.5px] font-medium transition-colors disabled:opacity-60',
                                    active
                                        ? 'bg-foreground text-background'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <ModeIcon
                                    className="size-3.5"
                                    weight={active ? 'fill' : 'regular'}
                                />
                                {value === 'ask' ? 'Ask' : 'Agent'}
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="top" className="max-w-56">
                            {MODE_HINT[value]}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </div>
    );
}

export function MentorComposer({ autoFocus = false }: { autoFocus?: boolean }) {
    const mentor = useMentor();
    const {
        draft,
        setDraft,
        attachments,
        setAttachments,
        send,
        isTyping,
        abort,
        mode,
        setMode,
        registerComposer,
        csrfToken,
        quotaHint,
    } = mentor;
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    const fileRef = useRef<HTMLInputElement | null>(null);
    const [uploading, setUploading] = useState<Uploading[]>([]);
    const [library, setLibrary] = useState<MentorAttachment[] | null>(null);
    const [libraryError, setLibraryError] = useState(false);
    const [dragging, setDragging] = useState(false);

    const setTextarea = useCallback(
        (el: HTMLTextAreaElement | null) => {
            textareaRef.current = el;
            registerComposer(el);
        },
        [registerComposer],
    );

    // Grow with the text, up to about eight lines.
    useEffect(() => {
        const el = textareaRef.current;

        if (!el) {
            return;
        }

        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
    }, [draft]);

    useEffect(() => {
        if (autoFocus) {
            textareaRef.current?.focus();
        }
    }, [autoFocus]);

    const fetchLibrary = useCallback(async () => {
        try {
            const res = await fetch('/documents/attachable', {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!res.ok) {
                throw new Error(String(res.status));
            }

            const json = (await res.json()) as {
                documents: MentorAttachment[];
            };
            setLibrary(json.documents);
            setLibraryError(false);

            return json.documents;
        } catch {
            setLibraryError(true);

            return null;
        }
    }, []);

    // Keep attached files that are still being read up to date.
    const pendingIds = attachments
        .filter(reading)
        .map((a) => a.id)
        .join(',');
    useEffect(() => {
        if (pendingIds === '') {
            return;
        }

        const id = window.setInterval(() => {
            void fetchLibrary().then((docs) => {
                if (docs) {
                    setAttachments((current) =>
                        current.map(
                            (a) => docs.find((d) => d.id === a.id) ?? a,
                        ),
                    );
                }
            });
        }, 2500);

        return () => window.clearInterval(id);
    }, [pendingIds, fetchLibrary, setAttachments]);

    const room = MAX_ATTACHMENTS - attachments.length - uploading.length;

    const upload = useCallback(
        async (files: File[]) => {
            for (const file of files.slice(0, Math.max(0, room))) {
                const tempId = crypto.randomUUID();
                setUploading((u) => [
                    ...u,
                    { tempId, name: file.name, error: null },
                ]);
                const body = new FormData();
                body.append('file', file);
                body.append('kind', guessKind(file.name));

                try {
                    const headers = buildLaravelJsonHeaders(csrfToken);
                    delete headers['Content-Type'];
                    const res = await fetch('/documents', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers,
                        body,
                    });
                    const json = (await res.json().catch(() => ({}))) as {
                        document?: MentorAttachment;
                        message?: string;
                        errors?: Record<string, string[]>;
                    };

                    if (!res.ok || !json.document) {
                        const message =
                            json.errors?.file?.[0] ??
                            json.message ??
                            'Upload failed.';
                        setUploading((u) =>
                            u.map((c) =>
                                c.tempId === tempId
                                    ? { ...c, error: message }
                                    : c,
                            ),
                        );
                        continue;
                    }

                    setUploading((u) => u.filter((c) => c.tempId !== tempId));
                    setAttachments((a) => [...a, json.document!]);
                } catch {
                    setUploading((u) =>
                        u.map((c) =>
                            c.tempId === tempId
                                ? { ...c, error: 'Network error.' }
                                : c,
                        ),
                    );
                }
            }
        },
        [csrfToken, room, setAttachments],
    );

    const canSend =
        !isTyping && draft.trim() !== '' && !uploading.some((u) => !u.error);

    const submit = () => {
        if (!canSend) {
            return;
        }

        const text = draft;
        const files = attachments;
        setDraft('');
        setAttachments([]);
        setUploading([]);
        void send(text, { attachments: files });
    };

    const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            submit();
        }
    };

    const onDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setDragging(false);
        const files = Array.from(e.dataTransfer.files ?? []);

        if (files.length > 0) {
            void upload(files);
        }
    };

    const toggleFromLibrary = (doc: MentorAttachment) => {
        setAttachments((current) =>
            current.some((a) => a.id === doc.id)
                ? current.filter((a) => a.id !== doc.id)
                : current.length >= MAX_ATTACHMENTS
                  ? current
                  : [...current, doc],
        );
    };

    return (
        <div className="px-3 pt-1 pb-3">
            <div
                onDragOver={(e) => {
                    if (e.dataTransfer.types.includes('Files')) {
                        e.preventDefault();
                        setDragging(true);
                    }
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                className={cn(
                    'rounded-2xl border border-border bg-background shadow-card transition-[border-color,box-shadow] duration-150 focus-within:border-foreground/25',
                    dragging && 'border-dashed border-foreground/40',
                )}
            >
                {attachments.length > 0 || uploading.length > 0 ? (
                    <ul
                        className="flex flex-wrap gap-2 px-3 pt-3"
                        aria-label="Attached files"
                    >
                        {attachments.map((a) => (
                            <AttachmentCard
                                key={a.id}
                                attachment={a}
                                onRemove={() =>
                                    setAttachments((c) =>
                                        c.filter((x) => x.id !== a.id),
                                    )
                                }
                            />
                        ))}
                        {uploading.map((u) => (
                            <li
                                key={u.tempId}
                                title={u.error ?? 'Uploading'}
                                className={cn(
                                    'flex max-w-[15rem] items-center gap-2 rounded-xl py-2 pr-2 pl-2.5 text-[13px]',
                                    u.error
                                        ? 'bg-destructive/10 text-destructive'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {u.error ? (
                                    <WarningCircle className="size-4 shrink-0" />
                                ) : (
                                    <CircleNotch className="size-4 shrink-0 animate-spin" />
                                )}
                                <span className="min-w-0 truncate">
                                    {u.error ?? u.name}
                                </span>
                                {u.error ? (
                                    <button
                                        type="button"
                                        aria-label="Dismiss"
                                        className="rounded p-0.5 hover:bg-destructive/10"
                                        onClick={() =>
                                            setUploading((c) =>
                                                c.filter(
                                                    (x) =>
                                                        x.tempId !== u.tempId,
                                                ),
                                            )
                                        }
                                    >
                                        <X className="size-3" />
                                    </button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                ) : null}

                <label htmlFor="mentor-composer-input" className="sr-only">
                    Message the mentor
                </label>
                <textarea
                    id="mentor-composer-input"
                    ref={setTextarea}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={onKeyDown}
                    rows={2}
                    placeholder={
                        attachments.length > 0
                            ? 'Ask about the attached files…'
                            : 'Ask about your colleges, applications or profile…'
                    }
                    className="block max-h-[200px] min-h-[56px] w-full resize-none bg-transparent px-3.5 pt-3 pb-1 text-[14px] leading-relaxed text-foreground outline-none placeholder:text-muted-foreground/80"
                />

                <div className="flex items-center gap-2 px-2.5 pt-1 pb-2.5">
                    <ModeToggle
                        mode={mode}
                        onChange={setMode}
                        disabled={isTyping}
                    />
                    <span aria-hidden className="h-5 w-px bg-border" />
                    <DropdownMenu
                        onOpenChange={(open) => open && void fetchLibrary()}
                    >
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        disabled={room <= 0}
                                        aria-label="Attach a file"
                                        className="inline-flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
                                    >
                                        <Plus className="size-[18px]" />
                                    </button>
                                </DropdownMenuTrigger>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                {room <= 0
                                    ? 'Up to 4 files per message'
                                    : 'Attach a file'}
                            </TooltipContent>
                        </Tooltip>
                        <DropdownMenuContent
                            align="start"
                            side="top"
                            className="w-72"
                        >
                            <DropdownMenuItem
                                onSelect={() => fileRef.current?.click()}
                                className="gap-2"
                            >
                                <UploadSimple className="size-4" />
                                Upload a file
                                <span className="ml-auto text-[11px] text-muted-foreground">
                                    PDF, DOCX, TXT
                                </span>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel className="flex items-center gap-1.5 px-2 py-1 text-[12px] font-normal text-muted-foreground">
                                <FolderOpen className="size-3.5" />
                                Your documents
                            </DropdownMenuLabel>
                            {library === null && !libraryError ? (
                                <p className="px-2 py-1.5 text-xs text-muted-foreground">
                                    Loading…
                                </p>
                            ) : libraryError ? (
                                <p className="px-2 py-1.5 text-xs text-destructive">
                                    Could not load your documents.
                                </p>
                            ) : library && library.length === 0 ? (
                                <p className="px-2 py-1.5 text-xs text-muted-foreground">
                                    No documents yet. Upload one above.
                                </p>
                            ) : (
                                <div className="max-h-60 scrollbar-thin overflow-y-auto">
                                    {library?.map((doc) => {
                                        const attached = attachments.some(
                                            (a) => a.id === doc.id,
                                        );

                                        return (
                                            <DropdownMenuItem
                                                key={doc.id}
                                                onSelect={(e) => {
                                                    e.preventDefault();
                                                    toggleFromLibrary(doc);
                                                }}
                                                className="gap-2"
                                            >
                                                <FileText
                                                    className={cn(
                                                        'size-4 shrink-0',
                                                        fileTone(
                                                            doc.mime ??
                                                                doc.name,
                                                        ),
                                                    )}
                                                    weight="duotone"
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-[13px]">
                                                        {doc.name}
                                                    </span>
                                                    <span className="block truncate text-[11.5px] text-muted-foreground">
                                                        {doc.kindLabel}
                                                        {doc.statusLabel
                                                            ? ` · ${doc.statusLabel}`
                                                            : ''}
                                                    </span>
                                                </span>
                                                {attached ? (
                                                    <Check className="size-4 text-foreground" />
                                                ) : null}
                                            </DropdownMenuItem>
                                        );
                                    })}
                                </div>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <input
                        ref={fileRef}
                        type="file"
                        accept={ACCEPT}
                        multiple
                        className="hidden"
                        onChange={(e: ChangeEvent<HTMLInputElement>) => {
                            const files = Array.from(e.target.files ?? []);
                            e.target.value = '';
                            void upload(files);
                        }}
                    />

                    <div className="ml-auto shrink-0">
                        {isTyping ? (
                            <button
                                type="button"
                                onClick={abort}
                                aria-label="Stop generating"
                                className="inline-flex size-8 items-center justify-center rounded-[10px] bg-foreground text-background transition-transform active:scale-95"
                            >
                                <Stop className="size-3.5" weight="fill" />
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={submit}
                                disabled={!canSend}
                                aria-label="Send message"
                                className={cn(
                                    'inline-flex size-8 items-center justify-center rounded-[10px] transition-[background-color,color,transform] active:scale-95',
                                    canSend
                                        ? 'bg-foreground text-background'
                                        : 'bg-muted text-muted-foreground/60',
                                )}
                            >
                                <PaperPlaneRight
                                    className="size-4"
                                    weight="fill"
                                />
                            </button>
                        )}
                    </div>
                </div>
            </div>
            <p
                className={cn(
                    'mt-1.5 px-1.5 text-[11.5px] leading-snug',
                    quotaHint?.warning
                        ? 'text-warning'
                        : 'text-muted-foreground/80',
                )}
            >
                {quotaHint ? `${quotaHint.text}. ` : ''}
                {MODE_HINT[mode]}
            </p>
        </div>
    );
}
