'use client';

import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    ArrowSquareOut,
    Bell,
    BellSimple,
    Check,
    EnvelopeSimple,
} from '@phosphor-icons/react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { toast } from 'sonner';
import {
    EmptyState,
    IconTile,
    ListCard,
    Page,
    PageHeader,
    Section,
    SegmentedTabs,
} from '@/components/page/kit';
import { RowList, SettingRow } from '@/components/settings/kit';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useHydrated } from '@/hooks/use-hydrated';
import { AppLayoutHeaderMeta } from '@/layouts/app-layout';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
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
    email_pass_expiry: boolean;
    email_payment_failed: boolean;
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
    { key: 'email_essay_complete', label: 'Essay generation finishes' },
    { key: 'email_college_list', label: 'My college list is ready' },
    { key: 'email_gap_fix', label: 'Gap analysis results are in' },
    { key: 'email_deadlines', label: 'A deadline is coming up' },
    { key: 'email_pass_expiry', label: 'My trial or pass is about to expire' },
    { key: 'email_payment_failed', label: 'A subscription payment fails' },
];

const FILTER_URL: Record<Filter, string> = {
    all: '/notifications',
    unread: '/notifications?filter=unread',
    read: '/notifications?filter=read',
};

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

/** A small icon-only action with a tooltip (open link, mark read). */
function RowAction({
    label,
    children,
}: {
    label: string;
    children: ReactElement;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>{children}</TooltipTrigger>
            <TooltipContent side="bottom">{label}</TooltipContent>
        </Tooltip>
    );
}

const iconButton =
    'inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50';

export default function Notifications() {
    const { filter, notifications, unread_count, notification_preferences } =
        usePage<PageProps>().props;
    const hydrated = useHydrated();

    const [prefs, setPrefs] = useState(notification_preferences);
    const [prefsBusy, setPrefsBusy] = useState(false);
    // Follow the server's preferences when they change (after a reload), like the old effect did.
    const [prefsSource, setPrefsSource] = useState(notification_preferences);

    if (prefsSource !== notification_preferences) {
        setPrefsSource(notification_preferences);
        setPrefs(notification_preferences);
    }

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
            <AppLayoutHeaderMeta>
                {unread_count > 0 ? `${unread_count} unread` : 'All read'}
            </AppLayoutHeaderMeta>

            <Page width="narrow">
                <PageHeader
                    title="Notifications"
                    description="Updates from AdmitMe. Open a link or mark an item read when you're done."
                    actions={
                        unread_count > 0 ? (
                            <Form
                                action="/notifications/read-all"
                                method="post"
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="h-9 gap-1.5 rounded-full px-4"
                                    >
                                        <Check
                                            className="size-4"
                                            weight="bold"
                                        />
                                        Mark all read
                                    </Button>
                                )}
                            </Form>
                        ) : undefined
                    }
                />

                <Section>
                    <SegmentedTabs<Filter>
                        label="Notification filters"
                        className="mb-4"
                        value={filter}
                        onChange={(next) => router.visit(FILTER_URL[next])}
                        items={[
                            { value: 'all', label: 'All' },
                            {
                                value: 'unread',
                                label: 'Unread',
                                count: unread_count,
                            },
                            { value: 'read', label: 'Read' },
                        ]}
                    />

                    {notifications.length === 0 ? (
                        <EmptyState
                            icon={
                                <IconTile
                                    icon={BellSimple}
                                    tone="sky"
                                    variant="glyph"
                                />
                            }
                            title={emptyMessage(filter)}
                        />
                    ) : (
                        <ListCard>
                            {notifications.map((n) => (
                                <li
                                    key={n.id}
                                    className="flex items-start gap-4 px-5 py-4"
                                >
                                    <span className="relative mt-0.5 flex shrink-0">
                                        <IconTile
                                            icon={Bell}
                                            tone={n.read_at ? 'slate' : 'sky'}
                                            variant="glyph"
                                        />
                                        {!n.read_at ? (
                                            <span
                                                aria-hidden
                                                className="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-blue-500 ring-2 ring-card"
                                            />
                                        ) : null}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={cn(
                                                'text-[15px] leading-snug text-foreground',
                                                n.read_at
                                                    ? 'font-normal'
                                                    : 'font-medium',
                                            )}
                                        >
                                            {!n.read_at ? (
                                                <span className="sr-only">
                                                    Unread:{' '}
                                                </span>
                                            ) : null}
                                            {n.title}
                                        </p>
                                        {n.body ? (
                                            <p className="mt-0.5 text-[13.5px] leading-snug text-muted-foreground">
                                                {n.body}
                                            </p>
                                        ) : null}
                                        <p className="nums mt-1 text-[12.5px] text-muted-foreground">
                                            {hydrated
                                                ? formatWhen(n.created_at)
                                                : ' '}
                                        </p>
                                    </div>
                                    <div className="-mr-1.5 flex shrink-0 items-center gap-0.5">
                                        {n.action_url ? (
                                            <RowAction label="Open link">
                                                <a
                                                    href={n.action_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    aria-label={`Open: ${n.title}`}
                                                    className={iconButton}
                                                >
                                                    <ArrowSquareOut
                                                        className="size-[18px]"
                                                        aria-hidden
                                                    />
                                                </a>
                                            </RowAction>
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
                                                    <RowAction label="Mark read">
                                                        <button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                            aria-label={`Mark read: ${n.title}`}
                                                            className={
                                                                iconButton
                                                            }
                                                        >
                                                            <Check
                                                                className="size-[18px]"
                                                                weight="bold"
                                                                aria-hidden
                                                            />
                                                        </button>
                                                    </RowAction>
                                                )}
                                            </Form>
                                        ) : null}
                                    </div>
                                </li>
                            ))}
                        </ListCard>
                    )}
                </Section>

                <Section
                    title="Email me when"
                    icon={EnvelopeSimple}
                    description="In-app notifications always appear here. Choose which updates we also send by email."
                >
                    <RowList>
                        {EMAIL_PREF_ROWS.map((row) => (
                            <SettingRow
                                key={row.key}
                                inline
                                htmlFor={`pref-${row.key}`}
                                title={
                                    <span className="font-normal">
                                        {row.label}
                                    </span>
                                }
                            >
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
                            </SettingRow>
                        ))}
                    </RowList>
                </Section>
            </Page>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
};
