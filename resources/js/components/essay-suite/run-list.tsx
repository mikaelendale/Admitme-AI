'use client';

import { CaretRight } from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { IconTile } from '@/components/page/kit';
import type { Tone } from '@/components/page/kit';
import { useHydrated } from '@/hooks/use-hydrated';
import { cn } from '@/lib/utils';
import type { EssayRecentRunRow } from './run-types';
import { sentenceCase } from './ui';

/** Past brainstorm or draft runs as one quiet list; the open one is tinted. */
export function SavedRunList({
    runs,
    selectedId,
    onOpen,
    icon,
    tone,
    emptyText,
}: {
    runs: EssayRecentRunRow[];
    selectedId: string | null;
    onOpen: (id: string) => void;
    icon: Icon;
    tone: Tone;
    emptyText: string;
}) {
    const hydrated = useHydrated();

    if (runs.length === 0) {
        return (
            <p className="rounded-2xl border border-dashed border-border px-5 py-6 text-center text-[13.5px] text-muted-foreground">
                {emptyText}
            </p>
        );
    }

    return (
        <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
            {runs.map((r) => {
                const active = selectedId === r.id;
                const when =
                    hydrated && r.created_at
                        ? new Date(r.created_at).toLocaleDateString(undefined, {
                              month: 'short',
                              day: 'numeric',
                          })
                        : null;

                return (
                    <li key={r.id}>
                        <button
                            type="button"
                            aria-current={active ? 'true' : undefined}
                            onClick={() => onOpen(r.id)}
                            className={cn(
                                'flex w-full items-start gap-3.5 px-4 py-3.5 text-left transition-colors outline-none hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-inset sm:px-5',
                                active && 'bg-muted/70',
                            )}
                        >
                            <IconTile
                                icon={icon}
                                tone={tone}
                                variant="glyph"
                                size="sm"
                                className="mt-0.5"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[14.5px] font-medium text-foreground">
                                    {r.title_for_card ?? r.school_name}
                                </span>
                                <span className="mt-0.5 block text-[13px] text-muted-foreground">
                                    {[
                                        r.title_for_card ? r.school_name : null,
                                        sentenceCase(r.status),
                                        r.has_output ? 'saved' : null,
                                        when,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                {r.user_prompt_excerpt ? (
                                    <span className="mt-1 line-clamp-2 block text-[13px] leading-snug text-muted-foreground/80">
                                        {r.user_prompt_excerpt}
                                    </span>
                                ) : null}
                            </span>
                            <CaretRight
                                className={cn(
                                    'mt-1 size-4 shrink-0 text-muted-foreground/60',
                                    active && 'text-foreground',
                                )}
                                aria-hidden
                            />
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
