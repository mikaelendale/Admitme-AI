import { useEffect, useRef } from 'react';
import type { PropsWithChildren } from 'react';
import { Page, PageHeader, UnderlineTabs } from '@/components/page/kit';
import { useCurrentUrl } from '@/hooks/use-current-url';

const ITEMS: { title: string; href: string }[] = [
    { title: 'Profile', href: '/settings/profile' },
    { title: 'Security', href: '/settings/security' },
    { title: 'Billing', href: '/settings/billing' },
    { title: 'Add-ons', href: '/settings/add-ons' },
    { title: 'Email alerts', href: '/settings/notifications' },
    { title: 'AI', href: '/settings/ai' },
    { title: 'Browser extension', href: '/settings/browser-extension' },
    { title: 'Privacy', href: '/settings/privacy' },
];

/**
 * Settings: one page title, underline tabs, then the tab's sections in a narrow centred column.
 * The tab pages render sections only (their headings are h2), so the page keeps a single h1.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { currentUrl } = useCurrentUrl();
    const path = currentUrl.split('?')[0];
    const tabsRef = useRef<HTMLDivElement>(null);

    // On a phone the tab row scrolls sideways: bring the current tab into view.
    useEffect(() => {
        const nav = tabsRef.current?.querySelector('nav');
        const active = nav?.querySelector<HTMLElement>('[aria-current="page"]');

        if (!nav || !active || nav.scrollWidth <= nav.clientWidth) {
            return;
        }

        const offset =
            active.getBoundingClientRect().left -
            nav.getBoundingClientRect().left;
        nav.scrollLeft += offset - (nav.clientWidth - active.offsetWidth) / 2;
    }, [path]);

    return (
        <Page width="narrow">
            <PageHeader title="Settings" className="mb-5" />
            <div ref={tabsRef} className="-mx-5 mb-9 sm:mx-0">
                <UnderlineTabs
                    label="Settings"
                    className="gap-5 px-5 sm:px-0"
                    items={ITEMS.map((item) => ({
                        href: item.href,
                        label: item.title,
                        active:
                            path === item.href ||
                            path.startsWith(`${item.href}/`),
                    }))}
                />
            </div>
            {children}
        </Page>
    );
}
