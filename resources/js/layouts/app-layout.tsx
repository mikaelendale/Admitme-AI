'use client';

import { Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    ChatsCircle,
    Clock as ClockPassIcon,
    CrownSimple,
    GearSixIcon,
    HouseSimpleIcon,
    List as ListIcon,
    ChartLine,
    ListChecks,
    PenNib,
    SealCheck,
    SignOut,
    UserCircle,
    WarningCircle,
} from '@phosphor-icons/react';
import {
    createContext,
    useContext,
    useLayoutEffect,
    useMemo,
    useState,
} from 'react';
import type { Dispatch, ReactNode, SetStateAction } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Breadcrumbs } from '@/components/breadcrumbs';
import ModeToggle from '@/components/mode-toggle';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    WorkspaceSidebarNavButtons,
    isAppWorkspaceNavLink,
} from '@/components/workspace-sidebar-nav';
import type { AppWorkspaceNavItem } from '@/components/workspace-sidebar-nav';
import { cn } from '@/lib/utils';
import {
    collegeLists,
    dashboard,
    essaySuite,
    gapFix,
    logout,
    profileOverview,
} from '@/routes';
import { index as dashboardChatsIndex } from '@/routes/dashboard/chats';
import { edit as billingEdit } from '@/routes/billing';
import { edit as editProfile } from '@/routes/profile';
import type { AppLayoutProps } from '@/types';
import { Toaster } from '@/components/ui/sonner';

type PlatformAccessShare = {
    has_access: boolean;
    source: 'subscription' | 'pass' | 'none';
    pass_plan: string | null;
    ends_at: string | null;
    days_remaining: number | null;
    enforcement_enabled: boolean;
    display: { headline: string; price_line: string } | null;
};

function AppSidebarPlanBadge({
    className,
    variant = 'rail',
}: {
    className?: string;
    variant?: 'rail' | 'drawer';
}) {
    const { auth, platform_access } = usePage<{
        auth: { user: unknown };
        platform_access: PlatformAccessShare | null;
    }>().props;

    if (!auth.user || !platform_access) {
        return null;
    }

    const expiringSoon =
        platform_access.source === 'pass' &&
        platform_access.has_access &&
        platform_access.days_remaining !== null &&
        platform_access.days_remaining <= 3;

    const locked =
        !platform_access.has_access && platform_access.enforcement_enabled;

    const Icon = locked
        ? WarningCircle
        : platform_access.source === 'subscription'
          ? CrownSimple
          : platform_access.source === 'pass'
            ? expiringSoon
                ? WarningCircle
                : ClockPassIcon
            : SealCheck;

    const endsLabel =
        platform_access.ends_at != null
            ? new Date(platform_access.ends_at).toLocaleDateString(undefined, {
                  dateStyle: 'medium',
              })
            : null;

    const tooltipLines: string[] = [];
    if (locked) {
        tooltipLines.push('Workspace access expired.');
        tooltipLines.push('Open Plan & billing to subscribe or buy a pass.');
    } else if (platform_access.source === 'subscription') {
        tooltipLines.push('Pro subscription');
        tooltipLines.push('Active — full access');
    } else if (platform_access.source === 'pass') {
        const d = platform_access.display;
        tooltipLines.push(d?.headline ?? 'Time-limited pass');
        if (d?.price_line) {
            tooltipLines.push(d.price_line);
        }
        if (endsLabel) {
            tooltipLines.push(`Access until ${endsLabel}`);
        }
        if (platform_access.days_remaining !== null) {
            tooltipLines.push(
                `${platform_access.days_remaining} day(s) remaining`,
            );
        }
    } else if (!platform_access.enforcement_enabled) {
        tooltipLines.push('Access checks disabled');
        tooltipLines.push('Enforcement off in this environment');
    } else {
        tooltipLines.push('No active pass');
        tooltipLines.push('Subscribe or purchase access on Plan & billing');
    }

    const railLabel = locked
        ? 'Renew'
        : platform_access.source === 'subscription'
          ? 'Pro'
          : platform_access.source === 'pass'
            ? platform_access.days_remaining !== null
                ? `${platform_access.days_remaining}d`
                : platform_access.pass_plan === 'trial'
                  ? 'Trial'
                  : platform_access.pass_plan === 'sprint'
                    ? 'Sprint'
                    : 'Pass'
            : !platform_access.enforcement_enabled
              ? 'Dev'
              : 'Plan';

    const drawerTitle = locked
        ? 'Access expired'
        : platform_access.source === 'subscription'
          ? 'Pro active'
          : platform_access.source === 'pass'
            ? (platform_access.display?.headline ?? 'Pass active')
            : !platform_access.enforcement_enabled
              ? 'Enforcement off'
              : 'Choose a plan';

    const drawerSub =
        platform_access.source === 'pass' &&
        platform_access.days_remaining !== null &&
        !locked
            ? `${platform_access.days_remaining} days left`
            : platform_access.source === 'subscription'
              ? 'Full workspace'
              : locked
                ? 'Billing'
                : null;

    const shellRail = cn(
        'flex w-[2.75rem] flex-col items-center gap-1 rounded-xl border px-1 py-2 transition-[box-shadow,transform] duration-200',
        'bg-linear-to-b from-card/90 via-card/50 to-muted/25',
        'shadow-[inset_0_1px_0_0_color-mix(in_oklch,var(--foreground)_8%,transparent)]',
        'hover:shadow-[inset_0_1px_0_0_color-mix(in_oklch,var(--foreground)_12%,transparent),0_0_0_1px_color-mix(in_oklch,var(--primary)_20%,transparent)]',
        locked &&
            'border-destructive/35 from-destructive/10 via-destructive/5 to-muted/20 text-destructive shadow-none hover:shadow-[0_0_0_1px_color-mix(in_oklch,var(--destructive)_35%,transparent)]',
        platform_access.source === 'subscription' &&
            !locked &&
            'border-primary/30 from-primary/12 via-primary/5 to-muted/20 text-primary',
        platform_access.source === 'pass' &&
            !locked &&
            !expiringSoon &&
            'border-amber-500/25 from-amber-500/10 via-amber-500/5 to-muted/20 text-amber-900 dark:text-amber-100',
        platform_access.source === 'pass' &&
            !locked &&
            expiringSoon &&
            'border-amber-500/40 from-amber-500/15 to-muted/20 text-amber-800 dark:text-amber-200',
        platform_access.source === 'none' &&
            !locked &&
            'border-border/60 text-muted-foreground',
        className,
    );

    const shellDrawer = cn(
        'flex min-w-0 max-w-full flex-1 items-center gap-2.5 rounded-xl border px-3 py-2',
        'bg--card/80 ',
        locked && 'border-destructive/30 from-destructive/10 text-destructive',
        platform_access.source === 'subscription' &&
            !locked &&
            'border-primary/25 from-primary/10 text-foreground',
        platform_access.source === 'pass' &&
            !locked &&
            'border-amber-500/20 from-amber-500/8 text-foreground',
        className,
    );

    const iconClass =
        variant === 'drawer' ? 'size-[1.125rem] shrink-0' : 'size-4 shrink-0';

    const innerRail = (
        <>
            <Icon className={iconClass} />
            <span className="max-w-10 truncate text-center text-[8px] font-bold uppercase leading-tight tracking-[0.14em] text-foreground/90">
                {railLabel}
            </span>
        </>
    );

    const innerDrawer = (
        <>
            <span
                className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-lg border border-border/50 bg-background/60',
                    locked && 'border-destructive/30 bg-destructive/10',
                    platform_access.source === 'subscription' &&
                        !locked &&
                        'border-primary/25 bg-primary/10',
                    platform_access.source === 'pass' &&
                        !locked &&
                        'border-amber-500/25 bg-amber-500/10',
                )}
            >
                <Icon className="size-4" />
            </span>
            <span className="min-w-0 flex-1 text-left">
                <span className="block truncate text-xs font-semibold tracking-tight text-foreground">
                    {drawerTitle}
                </span>
                {drawerSub ? (
                    <span className="mt-0.5 block truncate text-[10px] text-muted-foreground">
                        {drawerSub}
                    </span>
                ) : null}
            </span>
        </>
    );

    const triggerClass = variant === 'rail' ? shellRail : shellDrawer;

    const trigger =
        locked ? (
            <Link
                href={billingEdit()}
                className={cn(
                    triggerClass,
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                )}
                aria-label="Plan and access — renew billing"
            >
                {variant === 'rail' ? innerRail : innerDrawer}
            </Link>
        ) : (
            <button
                type="button"
                className={cn(
                    triggerClass,
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                )}
                aria-label="Plan and access status"
            >
                {variant === 'rail' ? innerRail : innerDrawer}
            </button>
        );

    return (
        <Tooltip>
            <TooltipTrigger asChild>{trigger}</TooltipTrigger>
            <TooltipContent
                side={variant === 'rail' ? 'right' : 'bottom'}
                sideOffset={8}
                className="max-w-xs border border  bg-primary-foreground backdrop-blur-sm"
            >
                <div className="space-y-1 text-left">
                    {tooltipLines.map((line, i) => (
                        <p
                            key={i}
                            className={cn(
                                'text-xs leading-snug',
                                i === 0
                                    ? 'font-medium text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {line}
                        </p>
                    ))}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

function AppLayoutDotPattern() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 z-0"
            style={{
                backgroundImage:
                    'radial-gradient(circle at center, color-mix(in oklch, var(--foreground) 16%, transparent) 1px, transparent 1.2px)',
                backgroundSize: '16px 16px',
                maskImage:
                    'linear-gradient(to top, black 0%, black 3%, rgba(0,0,0,0.4) 14%, transparent 26%)',
                WebkitMaskImage:
                    'linear-gradient(to top, black 0%, black 3%, rgba(0,0,0,0.4) 14%, transparent 26%)',
            }}
        />
    );
}

const APP_LAYOUT_PRIMARY_NAV: AppWorkspaceNavItem[] = [
    {
        key: 'home',
        icon: HouseSimpleIcon,
        label: 'Home',
        href: dashboard(),
        matchActive: (path) =>
            path === dashboard.url() || path.startsWith('/dashboard/chat/'),
    },
    {
        key: 'chats',
        icon: ChatsCircle,
        label: 'Chats',
        href: dashboardChatsIndex.url(),
        matchActive: (path) =>
            path === dashboardChatsIndex.url() || path.startsWith(`${dashboardChatsIndex.url()}/`),
    },
    {
        key: 'college-lists',
        icon: ListChecks,
        label: 'College lists',
        href: collegeLists(),
        matchActive: (path) =>
            path === collegeLists.url() || path.startsWith(`${collegeLists.url()}/`),
    },
    {
        key: 'gap-fix',
        icon: ChartLine,
        label: 'Gap + Fix',
        href: gapFix(),
        matchActive: (path) =>
            path === gapFix.url() || path.startsWith(`${gapFix.url()}/`),
    },
    {
        key: 'essay-suite',
        icon: PenNib,
        label: 'Essays',
        href: essaySuite(),
        matchActive: (path) =>
            path === essaySuite.url() ||
            path.startsWith(`${essaySuite.url()}/`),
    },
    {
        key: 'profile-overview',
        icon: UserCircle,
        label: 'Profile',
        href: profileOverview(),
        matchActive: (path) =>
            path === profileOverview.url() ||
            path.startsWith(`${profileOverview.url()}/`),
    },
];

const APP_LAYOUT_FOOTER_NAV_MOBILE: AppWorkspaceNavItem[] = [
    {
        key: 'notifications',
        icon: Bell,
        label: 'Notifications',
        href: '/notifications',
        matchActive: (path) => path === '/notifications',
    },
    {
        key: 'settings',
        icon: GearSixIcon,
        label: 'Settings',
        href: editProfile(),
        matchActive: (path) => path.startsWith('/settings'),
    },
];

const APP_LAYOUT_FOOTER_NAV_DESKTOP: AppWorkspaceNavItem[] = [
    ...APP_LAYOUT_FOOTER_NAV_MOBILE,
    {
        key: 'logout',
        icon: SignOut,
        label: 'Log out',
        href: logout.url(),
        method: 'post',
        matchActive: () => false,
        onClick: () => router.flushAll(),
    },
];

function footerNavWithNotificationBadge(
    items: AppWorkspaceNavItem[],
    unread: number,
): AppWorkspaceNavItem[] {
    if (unread <= 0) {
        return items;
    }

    return items.map((item) =>
        item.key === 'notifications' && isAppWorkspaceNavLink(item)
            ? { ...item, badgeCount: unread }
            : item,
    );
}

const SetAppLayoutHeaderTrailingContext = createContext<Dispatch<
    SetStateAction<ReactNode>
> | null>(null);

export function useSetAppLayoutHeaderTrailing(): Dispatch<
    SetStateAction<ReactNode>
> {
    const set = useContext(SetAppLayoutHeaderTrailingContext);

    if (!set) {
        throw new Error(
            'useSetAppLayoutHeaderTrailing must be used within AppLayout',
        );
    }

    return set;
}

export default function AppLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const [headerTrailing, setHeaderTrailing] = useState<ReactNode>(null);
    const { unread_notification_count = 0 } = usePage<{
        unread_notification_count?: number;
    }>().props;

    const footerDesktopNav = useMemo(
        () =>
            footerNavWithNotificationBadge(
                APP_LAYOUT_FOOTER_NAV_DESKTOP,
                unread_notification_count,
            ),
        [unread_notification_count],
    );

    const footerMobileNav = useMemo(
        () =>
            footerNavWithNotificationBadge(
                APP_LAYOUT_FOOTER_NAV_MOBILE,
                unread_notification_count,
            ),
        [unread_notification_count],
    );

    return (
        <SetAppLayoutHeaderTrailingContext.Provider value={setHeaderTrailing}>
            <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
                <div className="relative flex h-svh max-h-svh min-h-0 w-full overflow-hidden bg-background">
                    <AppLayoutDotPattern />

                    <aside
                        className="relative z-10 hidden h-full min-h-0 w-16 shrink-0 flex-col items-center bg-background lg:flex"
                        aria-label="Primary navigation"
                    >
                        <div className="flex justify-center px-2 py-1 pt-4">
                            <div className="flex h-8 w-8 items-center justify-center rounded">
                                <AppLogoIcon className="size-10" />
                            </div>
                        </div>

                        {/* <div className="flex w-full justify-center px-1.5 pb-2">
                            <AppSidebarPlanBadge />
                        </div> */}

                        <WorkspaceSidebarNavButtons
                            variant="rail"
                            primaryItems={APP_LAYOUT_PRIMARY_NAV}
                            footerItems={footerDesktopNav}
                            railFooterSlot={<> <ModeToggle />  <AppSidebarPlanBadge /></>}
                        />
                    </aside>

                    <div className="relative z-10 flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                        <header className="flex shrink-0 items-center gap-2 bg-background px-3 py-3 sm:gap-3 sm:px-4 md:px-6">
                            <SheetTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="shrink-0 lg:hidden"
                                    aria-label="Open navigation menu"
                                >
                                    <ListIcon className="size-5" />
                                </Button>
                            </SheetTrigger>

                            {breadcrumbs.length > 0 ? (
                                <div className="flex min-w-0 flex-1 items-center">
                                    <div className="flex max-w-full min-w-0 items-center rounded-lg bg-muted px-2 py-1 sm:px-3 sm:py-1.5">
                                        <Breadcrumbs
                                            breadcrumbs={breadcrumbs}
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div className="min-w-0 flex-1" />
                            )}

                            <div className="flex shrink-0 items-center gap-2">
                                {headerTrailing}
                            </div>
                        </header>

                        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto overscroll-contain">
                            {children}
                        </div>
                    </div>
                </div>
                <Toaster richColors closeButton position="top-right" />

                <SheetContent
                    side="left"
                    showCloseButton
                    className="flex w-[min(100vw,18rem)] max-w-[min(100vw,18rem)] flex-col gap-0 border-r border-sidebar-border bg-sidebar p-0 sm:max-w-xs"
                >
                    <SheetHeader className="border-b border-sidebar-border px-4 py-3 text-left">
                        <SheetTitle className="flex flex-col gap-3 text-sidebar-foreground">
                            <span className="flex items-center gap-3">
                                <AppLogoIcon className="size-10 shrink-0" />
                                <span className="truncate text-sm font-semibold tracking-tight">
                                    AdmitMe
                                </span>
                            </span>
                            <AppSidebarPlanBadge variant="drawer" />
                        </SheetTitle>
                    </SheetHeader>
                    <WorkspaceSidebarNavButtons
                        variant="drawer"
                        primaryItems={APP_LAYOUT_PRIMARY_NAV}
                        footerItems={footerMobileNav}
                        onItemClick={() => setMobileNavOpen(false)}
                        drawerFooterTrailing={<ModeToggle />}
                    />
                </SheetContent>
            </Sheet>
        </SetAppLayoutHeaderTrailingContext.Provider>
    );
}

export function AppLayoutHeaderTrailing({ children }: { children: ReactNode }) {
    const setTrailing = useSetAppLayoutHeaderTrailing();
    useLayoutEffect(() => {
        setTrailing(children);

        return () => setTrailing(null);
    }, [children, setTrailing]);

    return null;
}
