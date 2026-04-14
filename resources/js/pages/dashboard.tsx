'use client';

import { Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDots,
    ChartLine,
    Fire,
    ListChecks,
    PenNib,
    PencilSimple,
    Target,
    UserCircle,
    Wallet,
    WarningCircle,
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

// ─── Quick action chips ───────────────────────────────────────────────────────
const QUICK_ACTIONS = [
    { key: 'gap-analysis',  icon: Target,       label: 'Gap analysis' },
    { key: 'college-list',  icon: ListChecks,   label: 'Build my list' },
    { key: 'essay-roast',   icon: Fire,         label: 'Roast my essay' },
    { key: 'why-school',    icon: PencilSimple, label: 'Why This School' },
    { key: 'deadlines',     icon: CalendarDots, label: 'My deadlines' },
] as const;

const QUICK_PROMPTS: Record<(typeof QUICK_ACTIONS)[number]['key'], string> = {
    'gap-analysis': "Run a gap analysis on my college list and tell me what's holding me back from my reach schools.",
    'college-list': 'Build me a college list based on my profile.',
    'essay-roast':  "I want you to roast my essay. I'll paste it in my next message.",
    'why-school':   'Write a Why This School essay draft for one of my list schools.',
    'deadlines':    "Check all the deadlines on my college list and tell me what's coming up.",
};

// ─── Suggestion cards ─────────────────────────────────────────────────────────
const SUGGESTION_CARDS = [
    {
        key: 'gap-analysis',
        icon: Target,
        accent: 'bg-primary/10 text-primary border-primary/20',
        boldText: 'Gap analysis',
        rest: 'See what\'s holding you back from your reaches',
        prompt: "Run a gap analysis on my college list and tell me what's holding me back from my reach schools.",
    },
    {
        key: 'essay-roast',
        icon: Fire,
        accent: 'bg-rose-500/10 text-rose-500 border-rose-500/20',
        boldText: 'Essay roast',
        rest: 'Honest, specific feedback that actually helps',
        prompt: "I want you to roast my essay. I'll paste it in my next message.",
    },
    {
        key: 'college-list',
        icon: ListChecks,
        accent: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
        boldText: 'Build my list',
        rest: 'Balanced reach, match, and safety picks',
        prompt: 'Build me a college list based on my profile.',
    },
    {
        key: 'why-school',
        icon: PencilSimple,
        accent: 'bg-violet-500/10 text-violet-500 border-violet-500/20',
        boldText: 'Why This School',
        rest: 'Draft a compelling supplement in minutes',
        prompt: 'Write a Why This School essay draft for one of my list schools.',
    },
] as const;

// ─── Workspace nav ────────────────────────────────────────────────────────────
const WORKSPACE_LINKS = [
    { key: 'lists',   href: collegeLists.url(),   label: 'College lists',  icon: ListChecks,  color: 'text-emerald-500' },
    { key: 'essays',  href: essaySuite.url(),      label: 'Essay suite',    icon: PenNib,      color: 'text-violet-500'  },
    { key: 'gap-fix', href: gapFix.url(),          label: 'Gap + Fix',      icon: ChartLine,   color: 'text-amber-500'   },
    { key: 'profile', href: profileOverview.url(), label: 'Profile',        icon: UserCircle,  color: 'text-blue-500'    },
    { key: 'billing', href: billingEdit.url(),     label: 'Plan',           icon: Wallet,      color: 'text-muted-foreground' },
] as const;

// ─── Helpers ──────────────────────────────────────────────────────────────────
function getGreeting(): string {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
}

function firstName(name: string): string {
    return name.split(' ')[0] ?? name;
}

function accessBadge(pa: PlatformAccessShare | null): { label: string; cls: string } | null {
    if (!pa) return null;
    if (!pa.has_access && pa.enforcement_enabled) {
        return { label: 'Renew', cls: 'border-destructive/40 bg-destructive/10 text-destructive' };
    }
    if (pa.source === 'subscription') {
        return { label: 'Pro', cls: 'border-primary/30 bg-primary/10 text-primary' };
    }
    if (pa.source === 'pass') {
        const d = pa.days_remaining;
        return {
            label: d !== null ? `${d}d left` : 'Pass',
            cls: 'border-amber-500/40 bg-amber-500/10 text-amber-600 dark:text-amber-400',
        };
    }
    return null;
}

// ─── Nudge strip ─────────────────────────────────────────────────────────────
function NudgeStrip({ nudges }: { nudges: DashboardNudge[] }) {
    if (nudges.length === 0) return null;
    const top = nudges[0];
    const isUrgent = top.severity === 'urgent';
    const isWarning = top.severity === 'warning';
    return (
        <Link
            href={top.href}
            className={cn(
                'group mb-5 flex items-center gap-3 rounded-xl border px-4 py-3 transition-opacity hover:opacity-90',
                isUrgent && 'border-destructive/40 bg-destructive/10',
                isWarning && 'border-amber-500/40 bg-amber-500/10',
                !isUrgent && !isWarning && 'border-border/60 bg-card/60',
            )}
        >
            <WarningCircle
                size={18}
                weight="fill"
                className={cn(
                    'shrink-0',
                    isUrgent && 'text-destructive',
                    isWarning && 'text-amber-500',
                    !isUrgent && !isWarning && 'text-muted-foreground',
                )}
            />
            <div className="min-w-0 flex-1">
                <span className="text-sm font-semibold text-foreground">{top.title}</span>
                {top.body ? (
                    <span className="ml-2 text-sm text-muted-foreground">{top.body}</span>
                ) : null}
            </div>
            <span className="shrink-0 text-xs font-medium text-primary group-hover:underline">
                {top.cta} <ArrowRight className="inline size-3 align-middle" />
            </span>
        </Link>
    );
}

// ─── Tier bar ─────────────────────────────────────────────────────────────────
function TierBar({ tiers }: { tiers: HubStats['list_tiers'] }) {
    const total = tiers.reach + tiers.match + tiers.safety;
    if (total === 0) return null;
    return (
        <div className="mt-2 flex items-center gap-3">
            <div className="flex h-1.5 flex-1 overflow-hidden rounded-full">
                {tiers.reach > 0 && (
                    <div
                        className="bg-primary/70 transition-all"
                        style={{ width: `${(tiers.reach / total) * 100}%` }}
                    />
                )}
                {tiers.match > 0 && (
                    <div
                        className="bg-chart-2/70 transition-all"
                        style={{ width: `${(tiers.match / total) * 100}%` }}
                    />
                )}
                {tiers.safety > 0 && (
                    <div
                        className="bg-muted-foreground/40 transition-all"
                        style={{ width: `${(tiers.safety / total) * 100}%` }}
                    />
                )}
            </div>
            <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                {tiers.reach > 0 && <span><span className="font-semibold text-foreground">{tiers.reach}</span> reach</span>}
                {tiers.match > 0 && <span><span className="font-semibold text-foreground">{tiers.match}</span> match</span>}
                {tiers.safety > 0 && <span><span className="font-semibold text-foreground">{tiers.safety}</span> safety</span>}
            </div>
        </div>
    );
}

// ─── Main hub ─────────────────────────────────────────────────────────────────
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
    const greeting = useMemo(() => getGreeting(), []);
    const badge = useMemo(() => accessBadge(platform_access ?? null), [platform_access]);
    const name = firstName(auth.user.name);
    const hasSchools = hub_stats.college_list_schools_count > 0;
    const urgentDeadlines = hub_stats.urgent_deadlines_14d;

    const handleQuickAction = useCallback(
        (key: (typeof QUICK_ACTIONS)[number]['key']) => send(QUICK_PROMPTS[key]),
        [send],
    );

    const handleSuggestion = useCallback(
        (prompt: string) => send(prompt),
        [send],
    );

    // Contextual data line below greeting
    const dataLine = useMemo(() => {
        const parts: string[] = [];
        if (hasSchools) {
            parts.push(`${hub_stats.college_list_schools_count} school${hub_stats.college_list_schools_count !== 1 ? 's' : ''} on your list`);
        }
        if (urgentDeadlines > 0) {
            parts.push(`${urgentDeadlines} deadline${urgentDeadlines !== 1 ? 's' : ''} in 14 days`);
        } else if (hub_stats.upcoming_deadlines_60d > 0) {
            parts.push(`${hub_stats.upcoming_deadlines_60d} upcoming deadline${hub_stats.upcoming_deadlines_60d !== 1 ? 's' : ''}`);
        }
        return parts.join(' · ');
    }, [hub_stats, urgentDeadlines, hasSchools]);

    return (
        <div className="flex flex-1 flex-col overflow-y-auto px-4 py-8 scrollbar-none sm:px-6 sm:py-10">
            <div className="mx-auto w-full max-w-2xl">

                {/* ── Hero greeting ── */}
                <motion.div
                    initial={{ opacity: 0, y: 18 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.5, ease: [0.25, 0.1, 0.25, 1] }}
                    className="mb-7 flex flex-col items-center text-center"
                >
                    <AppLogoIcon className="mb-4 size-9 opacity-80" />
                    <div className="mb-1.5 flex items-center gap-2.5">
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-[1.75rem]">
                            {greeting}, {name}.
                        </h1>
                        {badge && (
                            <span className={cn('rounded-full border px-2 py-0.5 text-xs font-semibold', badge.cls)}>
                                {badge.label}
                            </span>
                        )}
                    </div>
                    {dataLine ? (
                        <p className={cn(
                            'text-sm',
                            urgentDeadlines > 0
                                ? 'font-medium text-amber-600 dark:text-amber-400'
                                : 'text-muted-foreground',
                        )}>
                            {dataLine}
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Your admissions mentor is ready — what's on your mind?
                        </p>
                    )}
                    {hasSchools && (
                        <div className="mt-3 w-48">
                            <TierBar tiers={hub_stats.list_tiers} />
                        </div>
                    )}
                </motion.div>

                {/* ── Profile attention strip ── */}
                {profile_completion?.needs_attention && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ duration: 0.3, delay: 0.05 }}
                        className="mb-4"
                    >
                        <Link
                            href={profileOverview.url()}
                            className="flex items-center gap-3 rounded-xl border border-amber-500/35 bg-amber-500/8 px-4 py-2.5 text-sm transition-opacity hover:opacity-90"
                        >
                            <div className="flex-1">
                                <span className="font-semibold text-foreground">
                                    Your profile is {profile_completion.completeness_score}% complete
                                </span>
                                <span className="ml-2 text-muted-foreground">
                                    — the mentor uses it for every recommendation.
                                </span>
                            </div>
                            <span className="shrink-0 text-xs font-medium text-amber-600 dark:text-amber-400">
                                Finish it <ArrowRight className="inline size-3 align-middle" />
                            </span>
                        </Link>
                    </motion.div>
                )}

                {/* ── Nudge ── */}
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.3, delay: 0.08 }}
                >
                    <NudgeStrip nudges={dashboard_nudges} />
                </motion.div>

                {/* ── Prompt ── */}
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.12, ease: 'easeOut' }}
                    className="mb-2.5"
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
                            voiceInput: false,
                            modeDividers: false,
                        }}
                        copy={{
                            defaultPlaceholder: 'Ask me anything about college admissions…',
                            searchPlaceholder: 'Search the web…',
                        }}
                    />
                </motion.div>

                {/* ── Quick chips ── */}
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.35, delay: 0.2 }}
                    className="mb-8 flex flex-wrap justify-center gap-1.5"
                >
                    {QUICK_ACTIONS.map((action) => (
                        <button
                            key={action.key}
                            type="button"
                            onClick={() => handleQuickAction(action.key)}
                            className="inline-flex items-center gap-1.5 rounded-full border border-border/70 bg-background px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors hover:border-primary/30 hover:bg-primary/5 hover:text-foreground"
                        >
                            <action.icon className="size-3" weight="bold" />
                            {action.label}
                        </button>
                    ))}
                </motion.div>

                {/* ── Suggestion cards ── */}
                <motion.section
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.24, ease: 'easeOut' }}
                    className="mb-8"
                >
                    <p className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground/50">
                        Start here
                    </p>
                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                        {SUGGESTION_CARDS.map((card, i) => (
                            <motion.button
                                key={card.key}
                                type="button"
                                onClick={() => handleSuggestion(card.prompt)}
                                initial={{ opacity: 0, y: 12 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.35, delay: 0.28 + i * 0.06, ease: 'easeOut' }}
                                className="group flex flex-col items-start gap-3 rounded-xl border border-border/60 bg-card/50 p-4 text-left transition-all hover:border-border hover:bg-card hover:shadow-sm"
                            >
                                <span className={cn(
                                    'flex size-8 items-center justify-center rounded-lg border',
                                    card.accent,
                                )}>
                                    <card.icon className="size-4" weight="bold" />
                                </span>
                                <div>
                                    <p className="text-sm font-semibold text-foreground leading-snug">
                                        {card.boldText}
                                    </p>
                                    <p className="mt-0.5 text-xs leading-snug text-muted-foreground">
                                        {card.rest}
                                    </p>
                                </div>
                            </motion.button>
                        ))}
                    </div>
                </motion.section>

                {/* ── Workspace nav ── */}
                <motion.section
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.38, ease: 'easeOut' }}
                    className="mb-8"
                >
                    <p className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground/50">
                        Workspace
                    </p>
                    <div className="grid grid-cols-5 gap-2">
                        {WORKSPACE_LINKS.map((item) => (
                            <Link
                                key={item.key}
                                href={item.href}
                                className="group flex flex-col items-center gap-2 rounded-xl border border-border/50 bg-card/40 py-4 text-center transition-all hover:border-border hover:bg-card hover:shadow-sm"
                            >
                                <span className={cn(
                                    'flex size-9 items-center justify-center rounded-xl border border-border/50 bg-background transition-all group-hover:border-border',
                                    item.color,
                                )}>
                                    <item.icon className="size-4" />
                                </span>
                                <span className="text-[11px] font-medium text-muted-foreground group-hover:text-foreground">
                                    {item.label}
                                </span>
                            </Link>
                        ))}
                    </div>
                </motion.section>

                {/* ── Stats row ── */}
                <motion.section
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.4, delay: 0.44, ease: 'easeOut' }}
                    className="mb-6 grid grid-cols-3 gap-3"
                >
                    <Link
                        href={collegeLists.url()}
                        className="group rounded-xl border border-border/50 bg-card/40 px-4 py-3.5 transition-all hover:border-border hover:bg-card"
                    >
                        <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground/50">Schools</p>
                        <p className="mt-1 text-2xl font-bold tabular-nums tracking-tight text-foreground">
                            {hub_stats.college_list_schools_count || '—'}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {hub_stats.college_list_schools_count > 0 ? 'on your list' : 'not built yet'}
                        </p>
                    </Link>

                    <Link
                        href={collegeLists.url()}
                        className={cn(
                            'group rounded-xl border px-4 py-3.5 transition-all hover:bg-card',
                            urgentDeadlines > 0
                                ? 'border-amber-500/40 bg-amber-500/8 hover:border-amber-500/60'
                                : 'border-border/50 bg-card/40 hover:border-border',
                        )}
                    >
                        <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground/50">Deadlines</p>
                        <p className={cn(
                            'mt-1 text-2xl font-bold tabular-nums tracking-tight',
                            urgentDeadlines > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground',
                        )}>
                            {urgentDeadlines > 0 ? urgentDeadlines : hub_stats.upcoming_deadlines_60d || '—'}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {urgentDeadlines > 0 ? 'in the next 14 days' : 'in the next 60 days'}
                        </p>
                    </Link>

                    <Link
                        href={profileOverview.url()}
                        className={cn(
                            'group rounded-xl border px-4 py-3.5 transition-all hover:bg-card',
                            profile_completion?.needs_attention
                                ? 'border-amber-500/30 bg-amber-500/5 hover:border-amber-500/50'
                                : 'border-border/50 bg-card/40 hover:border-border',
                        )}
                    >
                        <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground/50">Profile</p>
                        <p className="mt-1 text-2xl font-bold tabular-nums tracking-tight text-foreground">
                            {profile_completion?.completeness_score != null
                                ? `${profile_completion.completeness_score}%`
                                : '—'}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {profile_completion?.needs_attention ? 'needs attention' : 'completeness'}
                        </p>
                    </Link>
                </motion.section>

                {/* ── Past chats footnote ── */}
                {hub_stats.mentor_conversations_count > 0 && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ duration: 0.3, delay: 0.5 }}
                        className="pb-4 text-center"
                    >
                        <Link
                            href={dashboardChatsIndex.url()}
                            className="text-xs text-muted-foreground/60 underline-offset-3 hover:text-muted-foreground hover:underline"
                        >
                            {hub_stats.mentor_conversations_count} previous chat{hub_stats.mentor_conversations_count !== 1 ? 's' : ''} saved
                        </Link>
                    </motion.div>
                )}

            </div>
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────
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
