'use client';

import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    CalendarDots,
    ChartLineUp,
    ChatCircle,
    ClipboardText,
    FileText,
    Kanban,
    ListChecks,
    SealCheck,
    Sparkle,
    UserCircle,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { useMentor } from '@/components/mentor/mentor-context';
import { relativeTime } from '@/components/mentor/mentor-types';
import {
    EmptyState,
    IconTile,
    ListCard,
    ListRow,
    Page,
    PageHeader,
    Section,
    Stat,
    StatusBadge,
} from '@/components/page/kit';
import type { Tone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import type { Auth } from '@/types';

type InboxItem = {
    id: string;
    kind:
        | 'approval'
        | 'document'
        | 'deadline'
        | 'deadline_missing'
        | 'task'
        | 'notification'
        | 'nudge';
    group: 'needs_you' | 'deadlines' | 'activity';
    tone: 'urgent' | 'warning' | 'info' | 'success' | 'muted';
    title: string;
    body: string | null;
    href: string;
    cta: string;
    at: string | null;
    days?: number;
    unread?: boolean;
};

type PageProps = {
    auth: Auth;
    hub_stats: {
        college_list_schools_count: number;
        upcoming_deadlines_60d: number;
        urgent_deadlines_14d: number;
        list_tiers: { reach: number; match: number; safety: number };
    };
    inbox: { items: InboxItem[]; counts: Record<InboxItem['group'], number> };
    readiness: Record<
        string,
        { label: string; ready: boolean; missing: string[] }
    >;
    applications_summary: { total: number; by_status: Record<string, number> };
    profile_completion: {
        completeness_score: number;
        needs_attention: boolean;
    } | null;
};

const KIND: Record<InboxItem['kind'], { icon: Icon; tone: Tone }> = {
    approval: { icon: SealCheck, tone: 'violet' },
    document: { icon: FileText, tone: 'orange' },
    deadline: { icon: CalendarDots, tone: 'blue' },
    deadline_missing: { icon: CalendarDots, tone: 'amber' },
    task: { icon: Kanban, tone: 'slate' },
    notification: { icon: Bell, tone: 'sky' },
    nudge: { icon: Sparkle, tone: 'pink' },
};

const STATUS_LABELS: Record<string, string> = {
    preparing: 'preparing',
    ready_for_review: 'ready for review',
    ready_to_submit: 'ready to submit',
    submitted: 'submitted',
    decision_received: 'decided',
    closed: 'closed',
};

function nextStep(props: PageProps): {
    title: string;
    body: string;
    href: string;
    cta: string;
    icon: Icon;
    tone: Tone;
} {
    const schools = props.hub_stats.college_list_schools_count;
    const score = props.profile_completion?.completeness_score ?? 0;
    const research = props.readiness.college_research;

    if (research && !research.ready) {
        return {
            title: 'Tell your mentor about you',
            body: `Still needed: ${research.missing.slice(0, 3).join(', ')}.`,
            href: '/profile-setup',
            cta: 'Continue',
            icon: UserCircle,
            tone: 'pink',
        };
    }

    if (schools === 0) {
        return {
            title: 'Build your shortlist',
            body: 'Your mentor suggests colleges with reasons. You choose which ones join your list.',
            href: '/college-lists',
            cta: 'Open college lists',
            icon: ListChecks,
            tone: 'green',
        };
    }

    if (props.applications_summary.total === 0) {
        return {
            title: 'Start your first application',
            body: `${schools} college${schools === 1 ? '' : 's'} on your list. Each one gets its own checklist.`,
            href: '/applications',
            cta: 'Open applications',
            icon: ClipboardText,
            tone: 'blue',
        };
    }

    if (score < 70) {
        return {
            title: 'Fill in the rest of your profile',
            body: 'Fuller answers make research and checklists more accurate.',
            href: '/profile-setup',
            cta: 'Continue',
            icon: UserCircle,
            tone: 'pink',
        };
    }

    return {
        title: 'Keep your applications moving',
        body: 'See what is left, who owes what, and the next deadline.',
        href: '/applications',
        cta: 'Open applications',
        icon: ClipboardText,
        tone: 'blue',
    };
}

function greeting(): string {
    const h = new Date().getHours();

    return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

function Row({ item, hydrated }: { item: InboxItem; hydrated: boolean }) {
    const style = KIND[item.kind];
    const tone: Tone =
        item.kind === 'deadline' && item.tone === 'urgent'
            ? 'red'
            : item.kind === 'task' && item.tone === 'warning'
              ? 'amber'
              : style.tone;
    const when = !hydrated
        ? null
        : item.kind === 'deadline' && item.at
          ? new Date(item.at).toLocaleDateString(undefined, {
                month: 'short',
                day: 'numeric',
            })
          : relativeTime(item.at);

    return (
        <ListRow
            href={item.href}
            icon={<IconTile icon={style.icon} tone={tone} variant="glyph" />}
            title={item.title}
            description={item.body}
            meta={
                item.tone === 'urgent' ? (
                    <StatusBadge tone="danger">
                        {item.days === 0 ? 'Today' : `${item.days ?? ''} days`}
                    </StatusBadge>
                ) : item.unread ? (
                    <StatusBadge tone="info">New</StatusBadge>
                ) : (
                    when
                )
            }
        />
    );
}

export default function Dashboard() {
    const props = usePage<PageProps>().props;
    const { inbox, auth, hub_stats, applications_summary, profile_completion } =
        props;
    const { compose, open: mentorOpen } = useMentor();
    const hydrated = useHydrated();
    const step = nextStep(props);
    const needs = inbox.items.filter((i) => i.group === 'needs_you');
    const deadlines = inbox.items
        .filter((i) => i.group === 'deadlines')
        .slice(0, 5);
    const activity = inbox.items
        .filter((i) => i.group === 'activity')
        .slice(0, 5);
    const tiers = hub_stats.list_tiers;
    const statuses = Object.entries(applications_summary.by_status)
        .filter(([, n]) => n > 0)
        .map(([status, n]) => `${n} ${STATUS_LABELS[status] ?? status}`)
        .join(' · ');

    return (
        <>
            <Head title="Home" />
            <Page width="default">
                <PageHeader
                    title={`${hydrated ? greeting() : 'Hello'}, ${auth.user.name.split(' ')[0]}`}
                    description={
                        needs.length > 0
                            ? `${needs.length} ${needs.length === 1 ? 'thing needs' : 'things need'} your attention.`
                            : 'Nothing needs your attention right now.'
                    }
                    actions={
                        mentorOpen ? undefined : (
                            <Button
                                onClick={() => compose()}
                                className="h-9 gap-2 rounded-full px-4"
                            >
                                <ChatCircle className="size-4" weight="fill" />
                                Ask your mentor
                            </Button>
                        )
                    }
                />

                <Section>
                    <Link
                        href={step.href}
                        className="group flex items-center gap-4 rounded-2xl border border-border bg-card p-4 transition-colors hover:bg-muted/50 sm:p-5"
                    >
                        <IconTile
                            icon={step.icon}
                            tone={step.tone}
                            variant="gradient"
                            size="lg"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="block text-[15.5px] font-semibold text-foreground">
                                {step.title}
                            </span>
                            <span className="mt-0.5 block text-[13.5px] text-muted-foreground">
                                {step.body}
                            </span>
                        </span>
                        <span className="hidden shrink-0 items-center gap-1 text-[13.5px] font-medium text-foreground sm:inline-flex">
                            {step.cta}
                            <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                        </span>
                    </Link>
                </Section>

                <Section title="Needs you" icon={Sparkle}>
                    {needs.length > 0 ? (
                        <ListCard>
                            {needs.map((item) => (
                                <Row
                                    key={item.id}
                                    item={item}
                                    hydrated={hydrated}
                                />
                            ))}
                        </ListCard>
                    ) : (
                        <EmptyState
                            title="You're all caught up"
                            description="Approvals, document values to check and urgent deadlines appear here."
                        />
                    )}
                </Section>

                <Section
                    title="Coming up"
                    icon={CalendarDots}
                    actions={
                        deadlines.length > 0 ? (
                            <Link
                                href="/deadlines"
                                className="text-[13px] text-muted-foreground hover:text-foreground"
                            >
                                Calendar
                            </Link>
                        ) : undefined
                    }
                >
                    {deadlines.length > 0 ? (
                        <ListCard>
                            {deadlines.map((item) => (
                                <Row
                                    key={item.id}
                                    item={item}
                                    hydrated={hydrated}
                                />
                            ))}
                        </ListCard>
                    ) : (
                        <EmptyState
                            title="No deadlines in the next four months"
                            description="Deadlines come from the colleges on your list."
                        />
                    )}
                </Section>

                <Section title="Progress" icon={ChartLineUp}>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Stat
                            label="Profile"
                            value={`${profile_completion?.completeness_score ?? 0}%`}
                            hint="of your profile is filled in"
                            href="/profile-setup"
                        />
                        <Stat
                            label="Colleges"
                            value={hub_stats.college_list_schools_count}
                            hint={
                                hub_stats.college_list_schools_count > 0
                                    ? `${tiers.reach} reach · ${tiers.match} match · ${tiers.safety} safety`
                                    : 'none on your list yet'
                            }
                            href="/college-lists"
                        />
                        <Stat
                            label="Applications"
                            value={applications_summary.total}
                            hint={statuses || 'none started yet'}
                            href="/applications"
                        />
                    </div>
                </Section>

                {activity.length > 0 ? (
                    <Section title="Recent activity" icon={Bell}>
                        <ListCard>
                            {activity.map((item) => (
                                <Row
                                    key={item.id}
                                    item={item}
                                    hydrated={hydrated}
                                />
                            ))}
                        </ListCard>
                    </Section>
                ) : null}
            </Page>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Home', href: '/dashboard' }],
};
