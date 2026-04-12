'use client';

import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { Icon } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

export type AppWorkspaceNavLinkItem = {
    key: string;
    icon: Icon;
    label: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /** When set, use instead of isCurrentOrParentUrl(href) */
    matchActive?: (pathname: string) => boolean;
    /** Inertia visit method (default get). Use post for logout. */
    method?: NonNullable<InertiaLinkProps['method']>;
    onClick?: () => void;
    /** Unread badge count (e.g. notifications inbox); shown when &gt; 0. */
    badgeCount?: number;
};

export type AppWorkspaceNavButtonItem = {
    key: string;
    icon: Icon;
    label: string;
};

export type AppWorkspaceNavItem =
    | AppWorkspaceNavLinkItem
    | AppWorkspaceNavButtonItem;

export function isAppWorkspaceNavLink(
    item: AppWorkspaceNavItem,
): item is AppWorkspaceNavLinkItem {
    return 'href' in item && item.href != null;
}

export function WorkspaceSidebarNavButtons({
    variant,
    primaryItems,
    footerItems,
    onItemClick,
    railFooterSlot,
    drawerFooterTrailing,
    className,
}: {
    variant: 'rail' | 'drawer';
    primaryItems: AppWorkspaceNavItem[];
    footerItems: AppWorkspaceNavItem[];
    onItemClick?: () => void;
    /** Rendered below footer links on the rail (e.g. mode toggle) */
    railFooterSlot?: ReactNode;
    /** Right side of sheet footer row (e.g. mode toggle) */
    drawerFooterTrailing?: ReactNode;
    className?: string;
}) {
    const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    const itemActive = (item: AppWorkspaceNavItem): boolean => {
        if (!isAppWorkspaceNavLink(item)) {
            return false;
        }

        if (item.matchActive) {
            return item.matchActive(currentUrl);
        }

        return isCurrentOrParentUrl(item.href);
    };

    const itemClassName = (
        item: AppWorkspaceNavItem,
        active: boolean,
        placement: 'primary' | 'footer',
    ) =>
        cn(
            'flex items-center transition-colors',
            variant === 'rail' && 'rounded-full p-2',
            variant === 'drawer' &&
                'w-full gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium',
            active
                ? variant === 'rail'
                    ? 'bg-accent text-primary'
                    : placement === 'footer'
                      ? 'border border-sidebar-border bg-sidebar-accent text-sidebar-foreground'
                      : 'border border-border bg-accent text-primary'
                : cn(
                      variant === 'drawer' && placement === 'footer'
                          ? 'border border-transparent text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-foreground'
                          : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground',
                      variant === 'drawer' &&
                          placement === 'primary' &&
                          'border border-transparent',
                  ),
        );

    const renderItemTrigger = (
        item: AppWorkspaceNavItem,
        placement: 'primary' | 'footer',
    ) => {
        const active = itemActive(item);
        const classes = itemClassName(item, active, placement);

        if (isAppWorkspaceNavLink(item)) {
            const badgeCount = item.badgeCount ?? 0;
            const showBadge = badgeCount > 0 && variant === 'rail';
            const drawerBadge = badgeCount > 0 && variant === 'drawer';

            return (
                <Link
                    href={item.href}
                    method={item.method}
                    onClick={() => {
                        item.onClick?.();
                        onItemClick?.();
                    }}
                    className={cn(
                        classes,
                        (showBadge || drawerBadge) && 'relative',
                    )}
                    aria-current={active ? 'page' : undefined}
                    aria-label={variant === 'rail' ? item.label : undefined}
                >
                    <span className="relative inline-flex shrink-0 items-center justify-center">
                        <item.icon size={20} className="shrink-0" />
                        {showBadge ? (
                            <span
                                className="pointer-events-none absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[9px] font-bold leading-none text-destructive-foreground shadow-sm"
                                aria-hidden
                            >
                                {badgeCount > 9 ? '9+' : badgeCount}
                            </span>
                        ) : null}
                    </span>
                    {variant === 'drawer' && (
                        <span className="flex min-w-0 flex-1 items-center justify-between gap-2">
                            <span className="truncate">{item.label}</span>
                            {drawerBadge ? (
                                <span
                                    className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-destructive px-1.5 text-[10px] font-bold leading-none text-destructive-foreground"
                                    aria-hidden
                                >
                                    {badgeCount > 9 ? '9+' : badgeCount}
                                </span>
                            ) : null}
                        </span>
                    )}
                </Link>
            );
        }

        return (
            <button
                type="button"
                onClick={onItemClick}
                className={classes}
                aria-label={variant === 'rail' ? item.label : undefined}
            >
                <item.icon size={20} className="shrink-0" />
                {variant === 'drawer' && (
                    <span className="truncate">{item.label}</span>
                )}
            </button>
        );
    };

    const renderRailItem = (
        item: AppWorkspaceNavItem,
        placement: 'primary' | 'footer',
    ) => (
        <Tooltip key={item.key}>
            <TooltipTrigger asChild>
                {renderItemTrigger(item, placement)}
            </TooltipTrigger>
            <TooltipContent side="right" sideOffset={8}>
                {item.label}
            </TooltipContent>
        </Tooltip>
    );

    if (variant === 'rail') {
        return (
            <div
                className={cn(
                    'flex min-h-0 w-full flex-1 flex-col',
                    className,
                )}
            >
                <div className="flex w-full flex-1 flex-col items-center justify-center gap-4 py-6">
                    {primaryItems.map((item) => renderRailItem(item, 'primary'))}
                </div>
                <div className="flex flex-col items-center gap-2 pb-5">
                    {footerItems.map((item) => renderRailItem(item, 'footer'))}
                    {railFooterSlot}
                </div>
            </div>
        );
    }

    return (
        <div className={cn('flex min-h-0 flex-1 flex-col gap-0', className)}>
            <nav
                className="flex flex-1 flex-col gap-1 overflow-y-auto px-3 py-4"
                aria-label="Primary navigation"
            >
                {primaryItems.map((item) => (
                    <span key={item.key} className="contents">
                        {renderItemTrigger(item, 'primary')}
                    </span>
                ))}
            </nav>
            {footerItems.length > 0 && (
                <div className="mt-auto flex items-center justify-between gap-2 border-t border-sidebar-border px-4 py-4">
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        {footerItems.map((item) => (
                            <span key={item.key} className="contents">
                                {renderItemTrigger(item, 'footer')}
                            </span>
                        ))}
                    </div>
                    {drawerFooterTrailing}
                </div>
            )}
        </div>
    );
}
