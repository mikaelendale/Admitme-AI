import { Link, router } from '@inertiajs/react';
import { SignOut } from '@phosphor-icons/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { toUrl } from '@/lib/utils';
import { addons, logout } from '@/routes';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const settingsNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Billing',
        href: '/settings/billing',
        icon: null,
    },
    {
        title: 'Add-ons',
        href: addons.url(),
        icon: null,
    },
    {
        title: 'Email alerts',
        href: '/settings/notifications',
        icon: null,
    },
    {
        title: 'AI',
        href: '/settings/ai',
        icon: null,
    },
    {
        title: 'Privacy',
        href: '/settings/privacy',
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const activeItem =
        settingsNavItems.find((item) => isCurrentOrParentUrl(item.href)) ??
        settingsNavItems[0];
    const activeValue = toUrl(activeItem.href);

    return (
        <div className="flex min-h-0 flex-1 flex-col px-4 py-6 sm:px-6">
            <div className="mx-auto flex w-full max-w-3xl flex-col">
                <div className="mb-4 w-full ">
                    <Heading
                        title="Settings"
                        description="Manage your profile and account settings"
                    />
                </div>

                <Tabs value={activeValue} className="mb-10 w-full">
                    <div className="-mx-1 overflow-x-auto pb-1">
                        <TabsList
                            variant="line"
                            className="inline-flex min-w-min gap-1 px-1"
                            aria-label="Settings navigation"
                        >
                        {settingsNavItems.map((item) => (
                            <TabsTrigger
                                key={toUrl(item.href)}
                                value={toUrl(item.href)}
                                asChild
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="mr-1.5 h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </TabsTrigger>
                        ))}
                        </TabsList>
                    </div>
                </Tabs>

                <div className="mb-8 lg:hidden">
                    <Button
                        variant="outline"
                        className="w-full justify-center gap-2"
                        asChild
                    >
                        <Link
                            href={logout.url()}
                            method="post"
                            as="button"
                            onClick={() => router.flushAll()}
                        >
                            <SignOut className="size-4" weight="bold" />
                            Log out
                        </Link>
                    </Button>
                </div>

                <section className="w-full max-w-xl space-y-12">
                    {children}
                </section>
            </div>
        </div>
    );
}
