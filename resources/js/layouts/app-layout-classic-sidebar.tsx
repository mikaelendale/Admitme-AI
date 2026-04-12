/**
 * Laravel starter-kit style layout: collapsible AppSidebar + AppSidebarHeader.
 * Use this instead of the default `app-layout` when you need the classic shell.
 */
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

export default function AppLayoutClassicSidebar({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            {children}
        </AppSidebarLayout>
    );
}
