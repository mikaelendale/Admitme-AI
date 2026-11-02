'use client';

import { router } from '@inertiajs/react';
import {
    ChatCircle,
    MagnifyingGlass,
    NotePencil,
    UploadSimple,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { useMentor } from '@/components/mentor/mentor-context';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { NAV, TOOLS } from './sidebar';

type Command = {
    id: string;
    group: string;
    label: string;
    hint?: string;
    icon: Icon;
    run: () => void;
};

/** Search pages, tools, actions and recent chats; opens with Ctrl/⌘ K. */
export function CommandMenu({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const mentor = useMentor();
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const listRef = useRef<HTMLUListElement>(null);

    useEffect(() => {
        const onKey = (e: globalThis.KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                onOpenChange(!open);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open, onOpenChange]);

    const commands = useMemo<Command[]>(() => {
        const go = (href: string) => () => router.visit(href);

        return [
            {
                id: 'new-chat',
                group: 'Actions',
                label: 'New chat with your mentor',
                icon: NotePencil,
                run: () => {
                    mentor.newConversation();
                    mentor.setOpen(true);
                },
            },
            {
                id: 'upload',
                group: 'Actions',
                label: 'Upload a document',
                icon: UploadSimple,
                run: go('/documents'),
            },
            ...NAV.map((item) => ({
                id: `nav-${item.key}`,
                group: 'Pages',
                label: item.label,
                icon: item.icon,
                run: go(item.href),
            })),
            ...TOOLS.map((tool) => ({
                id: `tool-${tool.key}`,
                group: 'Tools',
                label: tool.label,
                icon: tool.icon,
                run: go(tool.href),
            })),
            {
                id: 'settings',
                group: 'Pages',
                label: 'Settings',
                icon: NAV[0].icon,
                hint: 'Profile, security, billing…',
                run: go('/settings/profile'),
            },
            ...mentor.recent.slice(0, 8).map((chat) => ({
                id: `chat-${chat.id}`,
                group: 'Chats',
                label: chat.title || 'Untitled chat',
                icon: ChatCircle,
                run: () => {
                    mentor.setOpen(true);
                    void mentor.loadConversation(chat.id);
                },
            })),
        ];
    }, [mentor]);

    const results = useMemo(() => {
        const q = query.trim().toLowerCase();

        return q === ''
            ? commands
            : commands.filter(
                  (c) =>
                      c.label.toLowerCase().includes(q) ||
                      c.group.toLowerCase().includes(q) ||
                      (c.hint ?? '').toLowerCase().includes(q),
              );
    }, [commands, query]);

    const run = (command: Command | undefined) => {
        if (!command) {
            return;
        }

        onOpenChange(false);
        setQuery('');
        command.run();
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(results.length - 1, i + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(0, i - 1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            run(results[active]);
        }
    };

    useEffect(() => {
        listRef.current
            ?.querySelector('[data-active="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    let lastGroup = '';

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                onOpenChange(value);

                if (!value) {
                    setQuery('');
                    setActive(0);
                }
            }}
        >
            <DialogContent
                showCloseButton={false}
                aria-describedby={undefined}
                className="top-[18%] translate-y-0 gap-0 overflow-hidden rounded-2xl p-0 sm:max-w-lg"
            >
                <DialogTitle className="sr-only">Search</DialogTitle>
                <div className="flex items-center gap-2.5 border-b border-border px-4">
                    <MagnifyingGlass className="size-[18px] shrink-0 text-muted-foreground" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setActive(0);
                        }}
                        onKeyDown={onKeyDown}
                        placeholder="Search pages, tools and chats…"
                        aria-label="Search"
                        className="h-12 flex-1 bg-transparent text-[15px] text-foreground outline-none placeholder:text-muted-foreground"
                    />
                    <kbd className="rounded-md border border-border px-1.5 py-0.5 font-mono text-[10.5px] text-muted-foreground">
                        Esc
                    </kbd>
                </div>
                <ul
                    ref={listRef}
                    role="listbox"
                    aria-label="Results"
                    className="max-h-[360px] scrollbar-thin overflow-y-auto p-1.5"
                >
                    {results.length === 0 ? (
                        <li className="px-3 py-6 text-center text-[13.5px] text-muted-foreground">
                            Nothing matches “{query}”.
                        </li>
                    ) : null}
                    {results.map((command, index) => {
                        const header =
                            command.group !== lastGroup ? command.group : null;
                        lastGroup = command.group;

                        return (
                            <li key={command.id}>
                                {header ? (
                                    <p className="px-2.5 pt-2.5 pb-1 text-[12px] text-muted-foreground">
                                        {header}
                                    </p>
                                ) : null}
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={index === active}
                                    data-active={index === active}
                                    onMouseEnter={() => setActive(index)}
                                    onClick={() => run(command)}
                                    className={cn(
                                        'flex h-10 w-full items-center gap-3 rounded-lg px-2.5 text-left text-[14px]',
                                        index === active
                                            ? 'bg-muted text-foreground'
                                            : 'text-foreground/80',
                                    )}
                                >
                                    <command.icon className="size-[18px] shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1 truncate">
                                        {command.label}
                                    </span>
                                    {command.hint ? (
                                        <span className="truncate text-[12.5px] text-muted-foreground">
                                            {command.hint}
                                        </span>
                                    ) : null}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
