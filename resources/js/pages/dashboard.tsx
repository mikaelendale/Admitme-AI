'use client';

import { Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BookOpenText,
    CalendarDots,
    ChartLine,
    Fire,
    Lightbulb,
    ListChecks,
    MagnifyingGlass,
    Notebook,
    PenNib,
    PencilSimple,
    Sparkle,
    Target,
    UserCircle,
    Wallet,
} from '@phosphor-icons/react';
import { motion } from 'framer-motion';
import { useCallback, useMemo } from 'react';
import { PromptInputBox } from '@/components/ai-prompt-box';
import {
    DashboardMentorChat,
    type DashboardChatInitialMessage,
    type DashboardMentorChatEmptyHelpers,
} from '@/components/dashboard-mentor-chat';
import AppLogoIcon from '@/components/app-logo-icon';
import type { Auth } from '@/types';
import { cn } from '@/lib/utils';
import {
    collegeLists,
    dashboard,
    essaySuite,
    gapFix,
    profileOverview,
} from '@/routes';
import { index as dashboardChatsIndex } from '@/routes/dashboard/chats';
import { edit as billingEdit } from '@/routes/billing';

type PlatformAccessShare = {
    has_access: boolean;
    source: 'subscription' | 'pass' | 'none';
    pass_plan: string | null;
    ends_at: string | null;
    days_remaining: number | null;
    enforcement_enabled: boolean;
    display: { headline: string; price_line: string } | null;
};

const QUICK_ACTIONS = [
    { key: 'gap-analysis', icon: Target, label: 'What are my biggest gaps?' },
    { key: 'college-list', icon: ListChecks, label: 'Build my college list' },
    { key: 'essay-roast', icon: Fire, label: 'Roast my essay' },
    { key: 'why-school', icon: PencilSimple, label: 'Why this school draft' },
    { key: 'deadlines', icon: CalendarDots, label: 'Check my deadlines' },
] as const;

const QUICK_PROMPTS: Record<(typeof QUICK_ACTIONS)[number]['key'], string> = {
    'gap-analysis':
        'Run a gap analysis on my college list and tell me what\'s holding me back from my reach schools.',
    'college-list':
        'Build me a college list based on my profile.',
    'essay-roast':
        'I want you to roast my essay. I\'ll paste it in my next message.',
    'why-school':
        'Write a Why This School essay draft for one of my list schools.',
    'deadlines':
        'Check all the deadlines on my college list and tell me what\'s coming up.',
};

const WORKSPACE_LINKS = [
    {
        key: 'lists',
        href: collegeLists.url(),
        label: 'College lists',
        sub: 'Balance & deadlines',
        icon: ListChecks,
    },
    {
        key: 'gap-fix',
        href: gapFix.url(),
        label: 'Gap + Fix',
        sub: 'Risks & roadmap',
        icon: ChartLine,
    },
    {
        key: 'essays',
        href: essaySuite.url(),
        label: 'Essay suite',
        sub: 'Brainstorm & critique',
        icon: PenNib,
    },
    {
        key: 'profile',
        href: profileOverview.url(),
        label: 'Profile',
        sub: 'Scores & intake',
        icon: UserCircle,
    },
    {
        key: 'billing',
        href: billingEdit.url(),
        label: 'Plan & billing',
        sub: 'Pro or pass',
        icon: Wallet,
    },
] as const;

const SUGGESTION_CARDS = [
    {
        key: 'gap-analysis',
        accent: 'bg-primary/10 text-primary',
        icon: Target,
        boldText: 'Gap analysis',
        rest: 'See your rejection risks',
        prompt: 'Run a gap analysis on my college list and tell me what\'s holding me back from my reach schools.',
    },
    {
        key: 'essay-feedback',
        accent: 'bg-destructive/10 text-destructive',
        icon: Fire,
        boldText: 'Essay feedback',
        rest: 'Brutal, honest critique',
        prompt: 'I want you to roast my essay. I\'ll paste it in my next message.',
    },
    {
        key: 'college-list',
        accent: 'bg-chart-1/10 text-chart-1',
        icon: ListChecks,
        boldText: 'College list',
        rest: 'Reach, match, safety',
        prompt: 'Build me a college list based on my profile.',
    },
    {
        key: 'why-school',
        accent: 'bg-chart-2/10 text-chart-2',
        icon: PencilSimple,
        boldText: 'Why This School',
        rest: 'Draft in minutes',
        prompt: 'Write a Why This School essay draft for one of my list schools.',
    },
] as const;

function getGreeting(): string {
    const hour = new Date().getHours();
    if (hour < 12) return 'Good Morning';
    if (hour < 17) return 'Good Afternoon';
    return 'Good Evening';
}

function firstName(name: string): string {
    return name.split(' ')[0] ?? name;
}

function accessHubLine(platformAccess: PlatformAccessShare | null | undefined): {
    value: string;
    caption: string;
    variant: 'default' | 'primary' | 'amber' | 'destructive';
} {
    if (!platformAccess) {
        return { value: '—', caption: 'Plan', variant: 'default' };
    }
    if (!platformAccess.has_access && platformAccess.enforcement_enabled) {
        return { value: '!', caption: 'Renew access', variant: 'destructive' };
    }
    if (platformAccess.source === 'subscription') {
        return { value: 'Pro', caption: 'Active', variant: 'primary' };
    }
    if (platformAccess.source === 'pass') {
        const d = platformAccess.days_remaining;
        const short =
            d !== null ? `${d}d` : platformAccess.pass_plan === 'trial' ? 'Trial' : 'Pass';
        const cap =
            platformAccess.display?.headline ??
            (platformAccess.pass_plan === 'sprint' ? 'Sprint' : 'Time-limited');
        return {
            value: short,
            caption: cap,
            variant: 'amber' as const,
        };
    }
    if (!platformAccess.enforcement_enabled) {
        return { value: 'Open', caption: 'Dev mode', variant: 'default' };
    }
    return { value: '—', caption: 'Pick a plan', variant: 'default' };
}

function HubStatTile({
    label,
    value,
    hint,
    variant = 'default',
}: {
    label: string;
    value: string;
    hint?: string;
    variant?: 'default' | 'primary' | 'amber' | 'destructive';
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-border/60 bg-card/90 px-3 py-2.5',
                variant === 'primary' && 'border-primary/25 bg-primary/10',
                variant === 'amber' && 'border-amber-500/25 bg-amber-500/10',
                variant === 'destructive' && 'border-destructive/30 bg-destructive/10',
            )}
        >
            <p className="ui-eyebrow">{label}</p>
            <p className="mt-1 text-base font-semibold tabular-nums tracking-tight text-foreground">
                {value}
            </p>
            {hint ? (
                <p className="mt-0.5 text-sm leading-snug text-muted-foreground">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

type ProfileCompletionShare = {
    needs_attention: boolean;
    completeness_score: number;
    completed_steps: number[];
} | null;

type HubStats = {
    college_list_schools_count: number;
    upcoming_deadlines_60d: number;
    urgent_deadlines_14d: number;
    mentor_conversations_count: number;
    list_tiers: { reach: number; match: number; safety: number };
};

type DashboardNudge = {
    id: string;
    severity: 'info' | 'warning' | 'urgent';
    title: string;
    body: string;
    href: string;
    cta: string;
};

function nudgeCardClass(severity: DashboardNudge['severity']): string {
    switch (severity) {
        case 'urgent':
            return 'border-destructive/40 bg-destructive/10';
        case 'warning':
            return 'border-amber-500/40 bg-amber-500/10';
        default:
            return 'border-border/60 bg-card/60';
    }
}

function DashboardHubLanding({
    send,
    isTyping,
    auth,
    platform_access,
    profile_completion,
    hub_stats,
    dashboard_nudges,
}: DashboardMentorChatEmptyHelpers & {
    auth: Auth;
    platform_access: PlatformAccessShare | null;
    profile_completion: ProfileCompletionShare;
    hub_stats: HubStats;
    dashboard_nudges: DashboardNudge[];
}) {
    const accessLine = useMemo(
        () => accessHubLine(platform_access ?? null),
        [platform_access],
    );
    const greeting = useMemo(() => getGreeting(), []);

    const handleQuickAction = useCallback(
        (key: (typeof QUICK_ACTIONS)[number]['key']) => {
            send(QUICK_PROMPTS[key]);
        },
        [send],
    );

    const handleSuggestionClick = useCallback(
        (prompt: string) => {
            send(prompt);
        },
        [send],
    );

    return (
        <div className="flex flex-1 flex-col px-3 py-6 scrollbar-none sm:px-5 sm:py-8 md:px-6">
            {profile_completion?.needs_attention ? (
                <div className="mx-auto mb-4 w-full max-w-3xl rounded-lg border border-amber-500/35 bg-amber-500/10 px-3 py-2.5 text-center text-sm text-amber-950 dark:text-amber-100">
                    <Link
                        href={profileOverview.url()}
                        className="font-semibold text-foreground underline underline-offset-2"
                    >
                        Complete your profile
                    </Link>{' '}
                    <span className="text-muted-foreground">
                        ({profile_completion.completeness_score}/100) so lists and mentor match you.
                    </span>
                </div>
            ) : null}
            <div className="mx-auto flex w-full max-w-3xl flex-col items-center">
                <motion.div
                    initial={{ opacity: 0, scale: 0.8 }}
                    animate={{ opacity: 1, scale: 1 }}
                    transition={{ duration: 0.5, ease: [0.25, 0.1, 0.25, 1] }}
                >
                    <AppLogoIcon className="mb-4 size-10" />
                </motion.div>

                <motion.h1
                    initial={{ opacity: 0, y: 16 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5, delay: 0.1, ease: 'easeOut' }}
                    className="mb-1 text-center text-xl font-semibold tracking-tight text-foreground"
                >
                    {greeting}, {firstName(auth.user.name)}.
                </motion.h1>
                <motion.p
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5, delay: 0.18, ease: 'easeOut' }}
                    className="mb-4 text-center text-sm leading-snug text-muted-foreground"
                >
                    Your admissions hub — chat with your mentor, then jump into lists, essays, and
                    profile.
                </motion.p>

                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.22, ease: 'easeOut' }}
                    className="mb-3 flex flex-wrap justify-center gap-2"
                >
                    {QUICK_ACTIONS.map((action, i) => (
                        <motion.div
                            key={action.key}
                            initial={{ opacity: 0, scale: 0.9 }}
                            animate={{ opacity: 1, scale: 1 }}
                            transition={{ duration: 0.3, delay: 0.3 + i * 0.06, ease: 'easeOut' }}
                        >
                            <button
                                type="button"
                                onClick={() => handleQuickAction(action.key)}
                                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3.5 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:border-primary/30 hover:bg-primary/5 hover:text-foreground"
                            >
                                <action.icon className="size-3.5" weight="bold" />
                                {action.label}
                            </button>
                        </motion.div>
                    ))}
                </motion.div>

                <motion.div
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.28, ease: 'easeOut' }}
                    className="mb-6 w-full"
                >
                    <PromptInputBox
                        onSend={(message) => send(message)}
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
                </motion.div>
            </div>

            <div className="mx-auto w-full max-w-3xl space-y-4 pb-10">
                {dashboard_nudges.length > 0 ? (
                    <motion.section
                        initial={{ opacity: 0, y: 12 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.45, delay: 0.5, ease: 'easeOut' }}
                    >
                        <p className="ui-eyebrow mb-2">Reminders</p>
                        <ul className="flex flex-col gap-2">
                            {dashboard_nudges.map((nudge, i) => (
                                <motion.li
                                    key={nudge.id}
                                    initial={{ opacity: 0, y: 8 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{
                                        duration: 0.35,
                                        delay: 0.52 + i * 0.05,
                                        ease: 'easeOut',
                                    }}
                                >
                                    <Link
                                        href={nudge.href}
                                        className={cn(
                                            'flex flex-col gap-1 rounded-xl border px-3 py-2.5 transition-colors hover:opacity-95 sm:flex-row sm:items-center sm:justify-between sm:gap-3',
                                            nudgeCardClass(nudge.severity),
                                        )}
                                    >
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-foreground">
                                                {nudge.title}
                                            </p>
                                            <p className="mt-0.5 text-sm leading-snug text-muted-foreground">
                                                {nudge.body}
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-sm font-medium text-primary">
                                            {nudge.cta}
                                            <ArrowRight className="ml-0.5 inline size-3.5 align-text-bottom" />
                                        </span>
                                    </Link>
                                </motion.li>
                            ))}
                        </ul>
                    </motion.section>
                ) : null}

                <motion.section
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.54, ease: 'easeOut' }}
                >
                    <p className="ui-eyebrow mb-2">Workspace</p>
                    <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                        {WORKSPACE_LINKS.map((item) => (
                            <Link
                                key={item.key}
                                href={item.href}
                                className="group flex items-center gap-2 rounded-xl border border-border/60 bg-card/40 px-3 py-2.5 transition-colors hover:border-primary/25 hover:bg-primary/5"
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border/50 bg-background/80 text-muted-foreground transition-colors group-hover:border-primary/20 group-hover:text-primary">
                                    <item.icon className="size-4" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-center gap-0.5 text-sm font-semibold text-foreground">
                                        {item.label}
                                        <ArrowRight className="size-3 opacity-0 transition-opacity group-hover:opacity-100" />
                                    </span>
                                    <span className="block truncate text-sm text-muted-foreground">
                                        {item.sub}
                                    </span>
                                </span>
                            </Link>
                        ))}
                    </div>
                </motion.section>

                <motion.section
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.4, ease: 'easeOut' }}
                    className="overflow-hidden rounded-xl border border-border/60 bg-muted/20"
                >
                    <div className="border-b border-border/50 px-3 py-2">
                        <p className="ui-eyebrow tracking-[0.2em]">Progress snapshot</p>
                    </div>
                    <div className="grid grid-cols-2 gap-2 p-2 sm:grid-cols-4">
                        <HubStatTile
                            label="Access"
                            value={accessLine.value}
                            hint={accessLine.caption}
                            variant={accessLine.variant}
                        />
                        <HubStatTile
                            label="Profile"
                            value={
                                profile_completion?.completeness_score != null
                                    ? `${profile_completion.completeness_score}%`
                                    : '—'
                            }
                            hint={
                                profile_completion?.completeness_score != null
                                    ? profile_completion.needs_attention
                                        ? 'Completeness — keep going'
                                        : 'Completeness'
                                    : 'Open profile overview'
                            }
                            variant="default"
                        />
                        <HubStatTile
                            label="Schools"
                            value={String(hub_stats.college_list_schools_count)}
                            hint="Active on your list"
                            variant="default"
                        />
                        <HubStatTile
                            label="Deadlines"
                            value={String(hub_stats.upcoming_deadlines_60d)}
                            hint="Due in the next 60 days"
                            variant={
                                hub_stats.urgent_deadlines_14d > 0 ? 'amber' : 'default'
                            }
                        />
                    </div>
                    {hub_stats.mentor_conversations_count > 0 ? (
                        <p className="border-t border-border/40 px-3 py-2 text-center text-xs text-muted-foreground">
                            <Link
                                href={dashboardChatsIndex.url()}
                                className="font-medium text-primary underline-offset-2 hover:underline"
                            >
                                {hub_stats.mentor_conversations_count} saved mentor chat
                                {hub_stats.mentor_conversations_count === 1 ? '' : 's'}
                            </Link>
                        </p>
                    ) : null}
                </motion.section>

                <motion.section
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.6, ease: 'easeOut' }}
                >
                    <p className="ui-eyebrow mb-2">Try asking in chat</p>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        {SUGGESTION_CARDS.map((card, i) => (
                            <motion.div
                                key={card.key}
                                initial={{ opacity: 0, y: 16 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{
                                    duration: 0.4,
                                    delay: 0.62 + i * 0.06,
                                    ease: 'easeOut',
                                }}
                            >
                                <button
                                    type="button"
                                    onClick={() =>
                                        handleSuggestionClick(card.prompt)
                                    }
                                    className="flex h-full w-full flex-col items-start gap-2 rounded-xl border border-border/60 bg-card/50 p-4 text-left transition-shadow hover:border-border hover:shadow-sm"
                                >
                                    <div
                                        className={`flex size-7 items-center justify-center rounded-md ${card.accent}`}
                                    >
                                        <card.icon className="size-3.5" weight="bold" />
                                    </div>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        <span className="font-semibold text-foreground">
                                            {card.boldText}
                                        </span>{' '}
                                        {card.rest}
                                    </p>
                                </button>
                            </motion.div>
                        ))}
                    </div>
                </motion.section>
            </div>
        </div>
    );
}

type InitialChatMessage = DashboardChatInitialMessage;

export default function Dashboard() {
    const {
        auth,
        platform_access,
        csrf_token,
        profile_completion,
        initial_conversation_id = null,
        initial_chat_messages = [],
        hub_stats,
        dashboard_nudges = [],
    } = usePage<{
        auth: Auth;
        platform_access: PlatformAccessShare | null;
        csrf_token?: string;
        profile_completion: ProfileCompletionShare;
        initial_conversation_id?: string | null;
        initial_chat_messages?: InitialChatMessage[];
        hub_stats: HubStats;
        dashboard_nudges?: DashboardNudge[];
    }>().props;

    const renderEmpty = useCallback(
        (helpers: DashboardMentorChatEmptyHelpers) => (
            <DashboardHubLanding
                {...helpers}
                auth={auth}
                platform_access={platform_access ?? null}
                profile_completion={profile_completion ?? null}
                hub_stats={hub_stats}
                dashboard_nudges={dashboard_nudges ?? []}
            />
        ),
        [auth, platform_access, profile_completion, hub_stats, dashboard_nudges],
    );

    return (
        <DashboardMentorChat
            csrfToken={csrf_token}
            initialConversationId={initial_conversation_id}
            initialMessages={initial_chat_messages}
            renderEmpty={renderEmpty}
        />
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard.url() }],
};
