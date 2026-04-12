'use client';

import { Head, Link } from '@inertiajs/react';
import { ChatsCircle } from '@phosphor-icons/react';
import { dashboard } from '@/routes';
import { show as dashboardChatShow } from '@/routes/dashboard/chat';
import { index as dashboardChatsIndex } from '@/routes/dashboard/chats';

type Row = { id: string; title: string; updated_at: string | null };

function formatUpdated(iso: string | null): string {
    if (!iso) {
        return '';
    }
    try {
        return new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
}

export default function DashboardChatsIndex({ conversations }: { conversations: Row[] }) {
    return (
        <>
            <Head title="Chat history" />
            <div className="page-shell flex min-h-0 flex-1 flex-col px-3 py-6 sm:px-5 md:px-6">
                <header className="mb-6">
                    <h1 className="page-title">Chat history</h1>
                    <p className="page-description">
                        Open a past conversation. Your mentor chat is unchanged — same layout as the home
                        dashboard.
                    </p>
                </header>
                <div className="mx-auto w-full max-w-2xl">
                    <Link
                        href={dashboard.url()}
                        className="mb-4 inline-flex text-sm font-medium text-primary underline-offset-2 hover:underline"
                    >
                        Start a new chat
                    </Link>
                    {conversations.length === 0 ? (
                        <p className="rounded-lg border border-border/60 bg-muted/30 px-4 py-8 text-center text-sm text-muted-foreground">
                            No saved conversations yet. Send a message from the dashboard to create one.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border/60 rounded-lg border border-border/60 bg-card/40">
                            {conversations.map((c) => (
                                <li key={c.id}>
                                    <Link
                                        href={dashboardChatShow.url(c.id)}
                                        className="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/40"
                                    >
                                        <ChatsCircle
                                            className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium text-foreground">{c.title}</span>
                                            {c.updated_at ? (
                                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                                    {formatUpdated(c.updated_at)}
                                                </span>
                                            ) : null}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}

DashboardChatsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard.url() },
        { title: 'Chats', href: dashboardChatsIndex.url() },
    ],
};
