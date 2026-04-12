'use client';

import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowSquareOut, Check } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { buildLaravelJsonHeaders, readCsrfTokenFromMeta } from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';

type Filter = 'all' | 'unread' | 'read';

type NotificationRow = {
    id: string;
    title: string;
    body: string | null;
    read_at: string | null;
    created_at: string;
    action_url: string | null;
};

type EmailNotificationPreferences = {
    email_essay_complete: boolean;
    email_college_list: boolean;
    email_gap_fix: boolean;
    email_deadlines: boolean;
};

type PageProps = {
    filter: Filter;
    notifications: NotificationRow[];
    unread_count: number;
    notification_preferences: EmailNotificationPreferences;
};

const EMAIL_PREF_ROWS: {
    key: keyof EmailNotificationPreferences;
    label: string;
}[] = [
    {
        key: 'email_essay_complete',
        label: 'Email me when essay generation completes',
    },
    {
        key: 'email_college_list',
        label: 'Email me when my college list is ready',
    },
    {
        key: 'email_gap_fix',
        label: 'Email me for gap analysis results',
    },
    {
        key: 'email_deadlines',
        label: 'Email me for deadline reminders',
    },
];

function formatWhen(iso: string): string {
    try {
        return new Date(iso).toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

function emptyMessage(filter: Filter): string {
    switch (filter) {
        case 'unread':
            return 'No unread notifications.';
        case 'read':
            return 'No read notifications yet.';
        default:
            return "You're all caught up — nothing in your inbox yet.";
    }
}

export default function Notifications() {
    const { filter, notifications, unread_count, notification_preferences } =
        usePage<PageProps>().props;

    const [prefs, setPrefs] = useState(notification_preferences);
    const [prefsBusy, setPrefsBusy] = useState(false);

    useEffect(() => {
        setPrefs(notification_preferences);
    }, [notification_preferences]);

    async function patchEmailPreference(
        key: keyof EmailNotificationPreferences,
        value: boolean,
    ): Promise<void> {
        const token = readCsrfTokenFromMeta();
        if (!token) {
            toast.error('Missing security token. Refresh and try again.');
            return;
        }

        const prev = prefs[key];
        setPrefs((p) => ({ ...p, [key]: value }));
        setPrefsBusy(true);
        try {
            const res = await fetch('/settings/notification-preferences', {
                method: 'PATCH',
                headers: buildLaravelJsonHeaders(token),
                body: JSON.stringify({ key, value }),
            });
            const data = (await res.json().catch(() => null)) as {
                ok?: boolean;
            } | null;
            if (!res.ok || !data?.ok) {
                throw new Error('Request failed');
            }
        } catch {
            setPrefs((p) => ({ ...p, [key]: prev }));
            toast.error('Could not update email preference.');
        } finally {
            setPrefsBusy(false);
        }
    }

    return (
        <>
            <Head title="Notifications" />

            <div className="page-shell">
                <header className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                        <h1 className="page-title">Notifications</h1>
                        <p className="page-description">
                            Updates from AdmitMe stay here. Filter by read or
                            unread, open links when available, and mark items
                            read when you are done.
                        </p>
                    </div>
                    {unread_count > 0 ? (
                        <Form
                            action="/notifications/read-all"
                            method="post"
                            options={{ preserveScroll: true }}
                            className="shrink-0"
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                    className="gap-1.5"
                                >
                                    <Check className="size-4" weight="bold" />
                                    Mark all read
                                </Button>
                            )}
                        </Form>
                    ) : null}
                </header>

                <section
                    className="mb-6 rounded-xl border border-border/60 bg-card/40 px-4 py-4"
                    aria-labelledby="email-prefs-heading"
                >
                    <h2
                        id="email-prefs-heading"
                        className="text-sm font-semibold text-foreground"
                    >
                        Email preferences
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        In-app notifications always appear here. Choose which
                        updates we may also send by email.
                    </p>
                    <ul className="mt-4 space-y-3">
                        {EMAIL_PREF_ROWS.map((row) => (
                            <li
                                key={row.key}
                                className="flex items-center justify-between gap-4"
                            >
                                <Label
                                    htmlFor={`pref-${row.key}`}
                                    className="max-w-[min(100%,28rem)] cursor-pointer text-sm font-normal leading-snug text-foreground"
                                >
                                    {row.label}
                                </Label>
                                <Switch
                                    id={`pref-${row.key}`}
                                    checked={prefs[row.key]}
                                    disabled={prefsBusy}
                                    onCheckedChange={(v) => {
                                        void patchEmailPreference(
                                            row.key,
                                            v === true,
                                        );
                                    }}
                                />
                            </li>
                        ))}
                    </ul>
                </section>

                <Tabs value={filter} className="mb-6 w-full">
                    <div className="-mx-1 overflow-x-auto pb-1">
                        <TabsList
                            variant="line"
                            className="inline-flex min-w-min gap-1 px-1"
                            aria-label="Notification filters"
                        >
                            <TabsTrigger value="all" asChild>
                                <Link href="/notifications">All</Link>
                            </TabsTrigger>
                            <TabsTrigger value="unread" asChild>
                                <Link href="/notifications?filter=unread">
                                    Unread
                                </Link>
                            </TabsTrigger>
                            <TabsTrigger value="read" asChild>
                                <Link href="/notifications?filter=read">
                                    Read
                                </Link>
                            </TabsTrigger>
                        </TabsList>
                    </div>
                </Tabs>

                {notifications.length === 0 ? (
                    <div className="rounded-xl border border-border/60 bg-muted/15 px-4 py-10 text-center text-sm text-muted-foreground">
                        {emptyMessage(filter)}
                    </div>
                ) : (
                    <ul className="space-y-2">
                        {notifications.map((n) => (
                            <li
                                key={n.id}
                                className={cn(
                                    'rounded-xl border border-border/60 bg-card/40 px-4 py-3 transition-colors',
                                    n.read_at
                                        ? 'opacity-80'
                                        : 'border-l-4 border-l-primary border-border/60 bg-primary/5',
                                )}
                            >
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 flex-1 space-y-1">
                                        <p className="text-sm font-medium text-foreground">
                                            {n.title}
                                        </p>
                                        {n.body ? (
                                            <p className="text-sm leading-snug text-muted-foreground">
                                                {n.body}
                                            </p>
                                        ) : null}
                                        <p className="text-xs text-muted-foreground">
                                            {formatWhen(n.created_at)}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                                        {n.action_url ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="gap-1"
                                                asChild
                                            >
                                                <a
                                                    href={n.action_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    Open
                                                    <ArrowSquareOut
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                </a>
                                            </Button>
                                        ) : null}
                                        {!n.read_at ? (
                                            <Form
                                                action={`/notifications/${n.id}/read`}
                                                method="post"
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="secondary"
                                                        size="sm"
                                                        disabled={
                                                            processing
                                                        }
                                                    >
                                                        Mark read
                                                    </Button>
                                                )}
                                            </Form>
                                        ) : null}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
};
