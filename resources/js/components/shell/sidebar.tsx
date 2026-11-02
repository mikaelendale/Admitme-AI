'use client';

import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    Browsers,
    CalendarBlank,
    CaretUpDown,
    ChartLine,
    ClipboardText,
    CreditCard,
    DownloadSimple,
    FileText,
    GearSix,
    HouseSimple,
    Kanban,
    ListChecks,
    MagnifyingGlass,
    Monitor,
    Moon,
    NotePencil,
    PenNib,
    Plus,
    SealCheck,
    SidebarSimple,
    SignOut,
    Sun,
    UserCircle,
    UsersThree,
    X,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import { useState } from 'react';
import { LogoMark } from '@/components/brand/logo-mark';
import { useMentor } from '@/components/mentor/mentor-context';
import { IconTile } from '@/components/page/kit';
import type { Tone } from '@/components/page/kit';
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
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useHydrated } from '@/hooks/use-hydrated';
import { cn } from '@/lib/utils';

type NavItem = {
    key: string;
    label: string;
    href: string;
    icon: Icon;
    match: (path: string) => boolean;
    badge?: number;
};
type ToolItem = {
    key: string;
    label: string;
    href: string;
    icon: Icon;
    tone: Tone;
};

const under =
    (...prefixes: string[]) =>
    (path: string) =>
        prefixes.some((p) => path === p || path.startsWith(`${p}/`));

export const NAV: NavItem[] = [
    {
        key: 'home',
        label: 'Home',
        href: '/dashboard',
        icon: HouseSimple,
        match: (p) => p === '/dashboard' || p.startsWith('/dashboard/chat'),
    },
    {
        key: 'college-lists',
        label: 'College lists',
        href: '/college-lists',
        icon: ListChecks,
        match: under('/college-lists'),
    },
    {
        key: 'applications',
        label: 'Applications',
        href: '/applications',
        icon: ClipboardText,
        match: under('/applications'),
    },
    {
        key: 'deadlines',
        label: 'Deadlines',
        href: '/deadlines',
        icon: CalendarBlank,
        match: under('/deadlines'),
    },
    {
        key: 'documents',
        label: 'Documents',
        href: '/documents',
        icon: FileText,
        match: under('/documents'),
    },
    {
        key: 'writing',
        label: 'Writing',
        href: '/writing',
        icon: NotePencil,
        match: under('/writing'),
    },
    {
        key: 'approvals',
        label: 'Approvals',
        href: '/approvals',
        icon: SealCheck,
        match: under('/approvals'),
    },
    {
        key: 'tasks',
        label: 'Tasks',
        href: '/tasks',
        icon: Kanban,
        match: under('/tasks'),
    },
    {
        key: 'profile',
        label: 'Profile',
        href: '/profile-overview',
        icon: UserCircle,
        match: under('/profile-overview', '/profile-setup'),
    },
];

export const TOOLS: ToolItem[] = [
    {
        key: 'essays',
        label: 'Essay suite',
        href: '/essay-suite',
        icon: PenNib,
        tone: 'violet',
    },
    {
        key: 'gap-fix',
        label: 'Gap + Fix',
        href: '/gap-fix',
        icon: ChartLine,
        tone: 'orange',
    },
    {
        key: 'council',
        label: 'List council',
        href: '/college-lists',
        icon: UsersThree,
        tone: 'green',
    },
    {
        key: 'extension',
        label: 'Browser extension',
        href: '/settings/browser-extension',
        icon: Browsers,
        tone: 'blue',
    },
];

/** The page a path belongs to, for the content header. */
export function navItemFor(
    path: string,
): { label: string; href: string; icon: Icon } | undefined {
    if (path.startsWith('/settings')) {
        return { label: 'Settings', href: '/settings/profile', icon: GearSix };
    }

    if (path.startsWith('/notifications')) {
        return { label: 'Notifications', href: '/notifications', icon: Bell };
    }

    if (path.startsWith('/essay-suite')) {
        return { label: 'Essay suite', href: '/essay-suite', icon: PenNib };
    }

    if (path.startsWith('/gap-fix')) {
        return { label: 'Gap + Fix', href: '/gap-fix', icon: ChartLine };
    }

    if (path.startsWith('/exports')) {
        return { label: 'Your data', href: '/exports', icon: DownloadSimple };
    }

    return NAV.find((item) => item.match(path));
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

const THEMES: { value: Appearance; icon: Icon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
];

type AccessShare = {
    has_access: boolean;
    source: string;
    days_remaining: number | null;
    enforcement_enabled: boolean;
    display: { headline: string } | null;
} | null;

function accessLine(
    access: AccessShare,
): { text: string; tone: 'ok' | 'warn' | 'bad' } | null {
    if (!access) {
        return null;
    }

    if (!access.has_access && access.enforcement_enabled) {
        return { text: 'Access expired — renew', tone: 'bad' };
    }

    if (access.source === 'subscription') {
        return { text: 'Pro plan', tone: 'ok' };
    }

    if (access.source === 'pass') {
        const days = access.days_remaining;

        return {
            text: `${access.display?.headline ?? 'Pass'}${days !== null ? ` · ${days}d left` : ''}`,
            tone: days !== null && days <= 3 ? 'warn' : 'ok',
        };
    }

    return access.enforcement_enabled
        ? { text: 'No active plan', tone: 'warn' }
        : null;
}

function AccountMenu({ collapsed }: { collapsed: boolean }) {
    const { auth, platform_access } = usePage<{
        auth: { user: { name: string; email: string; avatar?: string } | null };
        platform_access: AccessShare;
    }>().props;
    const { appearance, updateAppearance } = useAppearance();
    const user = auth.user;

    if (!user) {
        return null;
    }

    const access = accessLine(platform_access);
    const avatar = (
        <span className="relative flex size-8 shrink-0 items-center justify-center rounded-full bg-foreground text-[11.5px] font-semibold text-background">
            {user.avatar ? (
                <img
                    src={user.avatar}
                    alt=""
                    className="size-full rounded-full object-cover"
                />
            ) : (
                initials(user.name)
            )}
            {access && access.tone !== 'ok' ? (
                <span
                    aria-hidden
                    className={cn(
                        'absolute -top-0.5 -right-0.5 size-2.5 rounded-full ring-2 ring-canvas',
                        access.tone === 'bad' ? 'bg-destructive' : 'bg-warning',
                    )}
                />
            ) : null}
        </span>
    );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Account"
                    className={cn(
                        'flex w-full items-center gap-2.5 rounded-xl p-1.5 text-left transition-colors hover:bg-sidebar-accent',
                        collapsed && 'justify-center',
                    )}
                >
                    {avatar}
                    {!collapsed ? (
                        <>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13.5px] font-medium text-foreground">
                                    {user.name}
                                </span>
                                <span className="block truncate text-[12px] text-muted-foreground">
                                    {access?.text ?? user.email}
                                </span>
                            </span>
                            <CaretUpDown className="size-4 shrink-0 text-muted-foreground" />
                        </>
                    ) : null}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                side={collapsed ? 'right' : 'top'}
                align={collapsed ? 'end' : 'start'}
                sideOffset={8}
                className="w-64"
            >
                <DropdownMenuLabel className="px-2 py-2">
                    <p className="truncate text-[13px] font-medium text-foreground">
                        {user.name}
                    </p>
                    <p className="truncate text-xs font-normal text-muted-foreground">
                        {user.email}
                    </p>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild className="gap-2">
                    <Link href="/settings/profile">
                        <GearSix className="size-4" />
                        Settings
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild className="gap-2">
                    <Link href="/settings/billing">
                        <CreditCard className="size-4" />
                        <span
                            className={cn(
                                access?.tone === 'bad' && 'text-destructive',
                                access?.tone === 'warn' && 'text-warning',
                            )}
                        >
                            {access?.text ?? 'Plan & billing'}
                        </span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild className="gap-2">
                    <Link href="/exports">
                        <DownloadSimple className="size-4" />
                        Export my data
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <div
                    className="flex items-center gap-1 px-1.5 py-1"
                    role="radiogroup"
                    aria-label="Theme"
                >
                    {THEMES.map((theme) => (
                        <button
                            key={theme.value}
                            type="button"
                            role="radio"
                            aria-checked={appearance === theme.value}
                            onClick={() => updateAppearance(theme.value)}
                            className={cn(
                                'flex h-7 flex-1 items-center justify-center gap-1 rounded-md text-xs transition-colors',
                                appearance === theme.value
                                    ? 'bg-muted font-medium text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <theme.icon className="size-3.5" />
                            {theme.label}
                        </button>
                    ))}
                </div>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild className="gap-2">
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="w-full"
                        onClick={() => router.flushAll()}
                    >
                        <SignOut className="size-4" />
                        Log out
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function NavLink({
    item,
    active,
    collapsed,
    onNavigate,
}: {
    item: NavItem;
    active: boolean;
    collapsed: boolean;
    onNavigate?: () => void;
}) {
    const link = (
        <Link
            href={item.href}
            prefetch
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            aria-label={
                collapsed
                    ? item.badge
                        ? `${item.label} (${item.badge})`
                        : item.label
                    : undefined
            }
            className={cn(
                'relative flex h-9 items-center gap-2.5 rounded-[10px] text-[14px] transition-colors',
                collapsed ? 'w-9 justify-center' : 'px-2.5',
                active
                    ? 'bg-sidebar-accent font-medium text-foreground'
                    : 'text-foreground/75 hover:bg-sidebar-accent/70 hover:text-foreground',
            )}
        >
            <item.icon
                className={cn(
                    'size-[18px] shrink-0',
                    active ? 'text-foreground' : 'text-muted-foreground',
                )}
                weight={active ? 'fill' : 'regular'}
            />
            {!collapsed ? (
                <span className="min-w-0 flex-1 truncate">{item.label}</span>
            ) : null}
            {item.badge ? (
                collapsed ? (
                    <span
                        aria-hidden
                        className="absolute top-1 right-1 size-2 rounded-full bg-brand ring-2 ring-canvas"
                    />
                ) : (
                    <span className="nums rounded-full bg-foreground px-1.5 py-px text-[11px] font-semibold text-background">
                        {item.badge > 9 ? '9+' : item.badge}
                    </span>
                )
            ) : null}
        </Link>
    );

    return collapsed ? (
        <Tooltip>
            <TooltipTrigger asChild>{link}</TooltipTrigger>
            <TooltipContent side="right" sideOffset={8}>
                {item.label}
            </TooltipContent>
        </Tooltip>
    ) : (
        link
    );
}

/** A one-line nudge at the foot of the sidebar (reference: the dismissible announcement card). */
function ProfileNudge() {
    const { profile_completion } = usePage<{
        profile_completion: {
            completeness_score: number;
            needs_attention: boolean;
        } | null;
    }>().props;
    const [dismissed, setDismissed] = useState(false);
    const score = profile_completion?.completeness_score;

    if (dismissed || score === undefined || score === null || score >= 80) {
        return null;
    }

    return (
        <div className="group/nudge relative mb-1.5 rounded-xl border border-border bg-background px-3 py-2.5 shadow-card">
            <button
                type="button"
                onClick={() => setDismissed(true)}
                aria-label="Dismiss"
                className="absolute top-1.5 right-1.5 rounded-md p-0.5 text-muted-foreground opacity-0 transition-opacity group-hover/nudge:opacity-100 hover:bg-muted hover:text-foreground focus-visible:opacity-100"
            >
                <X className="size-3" />
            </button>
            <Link href="/profile-setup" className="block">
                <span className="flex items-baseline justify-between gap-2 pr-4">
                    <span className="text-[12.5px] font-medium text-foreground">
                        Profile {score}% done
                    </span>
                    <span className="text-[12px] text-muted-foreground">
                        Continue
                    </span>
                </span>
                <span
                    className="mt-2 block h-1 overflow-hidden rounded-full bg-muted"
                    aria-hidden
                >
                    <span
                        className="block h-full rounded-full bg-foreground/70"
                        style={{ width: `${score}%` }}
                    />
                </span>
            </Link>
        </div>
    );
}

export function Sidebar({
    collapsed,
    onToggleCollapsed,
    onSearch,
    onNavigate,
    variant = 'desktop',
}: {
    collapsed: boolean;
    onToggleCollapsed?: () => void;
    onSearch: () => void;
    onNavigate?: () => void;
    variant?: 'desktop' | 'drawer';
}) {
    const { currentUrl } = useCurrentUrl();
    const path = currentUrl.split('?')[0];
    const mentor = useMentor();
    const hydrated = useHydrated();
    const { pending_approval_count = 0, unread_notification_count = 0 } =
        usePage<{
            pending_approval_count?: number;
            unread_notification_count?: number;
        }>().props;
    const nav = NAV.map((item) =>
        item.key === 'approvals' && pending_approval_count > 0
            ? { ...item, badge: pending_approval_count }
            : item,
    );
    const notifications: NavItem = {
        key: 'notifications',
        label: 'Notifications',
        href: '/notifications',
        icon: Bell,
        match: under('/notifications'),
        badge: unread_notification_count || undefined,
    };
    const recent = hydrated ? mentor.recent.slice(0, 6) : [];

    const newChat = () => {
        mentor.newConversation();
        mentor.setOpen(true);
        onNavigate?.();
    };

    return (
        <div
            className={cn(
                'flex h-full min-h-0 w-full flex-col',
                collapsed ? 'items-center px-2 py-3' : 'px-3 py-3',
            )}
        >
            {/* Brand + tools */}
            <div
                className={cn(
                    'mb-3 flex shrink-0 items-center',
                    collapsed ? 'flex-col gap-2' : 'gap-2 px-1',
                )}
            >
                <Link
                    href="/dashboard"
                    onClick={onNavigate}
                    className="flex min-w-0 items-center gap-2.5 rounded-lg py-1"
                    aria-label="AdmitMe home"
                >
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-[8px] bg-foreground text-background">
                        <LogoMark className="size-3.5" />
                    </span>
                    {!collapsed ? (
                        <span className="truncate text-[15px] font-semibold tracking-tight text-foreground">
                            AdmitMe
                        </span>
                    ) : null}
                </Link>
                {!collapsed ? <span className="flex-1" /> : null}
                <Tooltip>
                    <TooltipTrigger asChild>
                        <button
                            type="button"
                            onClick={onSearch}
                            aria-label="Search"
                            className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground"
                        >
                            <MagnifyingGlass className="size-[18px]" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent side={collapsed ? 'right' : 'bottom'}>
                        Search · Ctrl K
                    </TooltipContent>
                </Tooltip>
                {variant === 'desktop' && onToggleCollapsed ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                onClick={onToggleCollapsed}
                                aria-label={
                                    collapsed
                                        ? 'Expand sidebar'
                                        : 'Collapse sidebar'
                                }
                                className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground"
                            >
                                <SidebarSimple className="size-[18px]" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side={collapsed ? 'right' : 'bottom'}>
                            {collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                        </TooltipContent>
                    </Tooltip>
                ) : null}
            </div>

            <div
                className={cn(
                    'scrollbar-none min-h-0 w-full flex-1 overflow-y-auto',
                    collapsed && 'flex flex-col items-center',
                )}
            >
                <nav
                    aria-label="Workspace"
                    className={cn(
                        'flex flex-col gap-0.5',
                        collapsed && 'items-center',
                    )}
                >
                    {collapsed ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    onClick={newChat}
                                    aria-label="New chat"
                                    className="flex size-9 items-center justify-center rounded-[10px] text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground"
                                >
                                    <Plus className="size-[18px]" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent side="right">
                                New chat
                            </TooltipContent>
                        </Tooltip>
                    ) : (
                        <button
                            type="button"
                            onClick={newChat}
                            className="flex h-9 items-center gap-2.5 rounded-[10px] px-2.5 text-[14px] text-foreground/75 transition-colors hover:bg-sidebar-accent/70 hover:text-foreground"
                        >
                            <Plus className="size-[18px] text-muted-foreground" />
                            New chat
                        </button>
                    )}
                    {nav.map((item) => (
                        <NavLink
                            key={item.key}
                            item={item}
                            active={item.match(path)}
                            collapsed={collapsed}
                            onNavigate={onNavigate}
                        />
                    ))}
                    <NavLink
                        item={notifications}
                        active={notifications.match(path)}
                        collapsed={collapsed}
                        onNavigate={onNavigate}
                    />
                </nav>

                <div
                    className={cn(
                        'mt-6',
                        collapsed && 'flex flex-col items-center',
                    )}
                >
                    {!collapsed ? (
                        <p className="mb-1.5 px-2.5 text-[12.5px] text-muted-foreground">
                            Tools
                        </p>
                    ) : (
                        <span
                            aria-hidden
                            className="mb-2 h-px w-5 bg-sidebar-border"
                        />
                    )}
                    <ul
                        className={cn(
                            'flex flex-col gap-0.5',
                            collapsed && 'items-center',
                        )}
                    >
                        {TOOLS.map((tool) => {
                            const active =
                                path.startsWith(tool.href) &&
                                tool.key !== 'council';
                            const link = (
                                <Link
                                    href={tool.href}
                                    onClick={onNavigate}
                                    aria-label={
                                        collapsed ? tool.label : undefined
                                    }
                                    className={cn(
                                        'flex h-9 items-center gap-2.5 rounded-[10px] text-[14px] transition-colors',
                                        collapsed
                                            ? 'w-9 justify-center'
                                            : 'px-2',
                                        active
                                            ? 'bg-sidebar-accent font-medium text-foreground'
                                            : 'text-foreground/75 hover:bg-sidebar-accent/70 hover:text-foreground',
                                    )}
                                >
                                    <IconTile
                                        icon={tool.icon}
                                        tone={tool.tone}
                                        variant="gradient"
                                        size="sm"
                                        className="size-[22px] rounded-full"
                                    />
                                    {!collapsed ? (
                                        <span className="truncate">
                                            {tool.label}
                                        </span>
                                    ) : null}
                                </Link>
                            );

                            return (
                                <li key={tool.key}>
                                    {collapsed ? (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                {link}
                                            </TooltipTrigger>
                                            <TooltipContent side="right">
                                                {tool.label}
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : (
                                        link
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </div>

                {!collapsed ? (
                    <div className="mt-6">
                        <div className="mb-1.5 flex items-center justify-between px-2.5">
                            <p className="text-[12.5px] text-muted-foreground">
                                Chats
                            </p>
                            <Link
                                href="/dashboard/chats"
                                onClick={onNavigate}
                                className="text-[12px] text-muted-foreground hover:text-foreground"
                            >
                                All
                            </Link>
                        </div>
                        {recent.length === 0 ? (
                            <p className="px-2.5 text-[13px] text-muted-foreground/70">
                                No chats yet.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-0.5">
                                {recent.map((chat) => (
                                    <li key={chat.id}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                mentor.setOpen(true);
                                                void mentor.loadConversation(
                                                    chat.id,
                                                );
                                                onNavigate?.();
                                            }}
                                            className={cn(
                                                'flex h-8 w-full items-center rounded-[10px] px-2.5 text-left text-[13.5px] transition-colors',
                                                mentor.conversationId ===
                                                    chat.id
                                                    ? 'bg-sidebar-accent text-foreground'
                                                    : 'text-foreground/70 hover:bg-sidebar-accent/70 hover:text-foreground',
                                            )}
                                        >
                                            <span className="truncate">
                                                {chat.title || 'Untitled chat'}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                ) : null}
            </div>

            <div
                className={cn(
                    'mt-2 w-full shrink-0',
                    collapsed && 'flex flex-col items-center',
                )}
            >
                {!collapsed ? <ProfileNudge /> : null}
                <AccountMenu collapsed={collapsed} />
            </div>
        </div>
    );
}
