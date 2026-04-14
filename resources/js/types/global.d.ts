import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            isSubscribed: boolean;
            sidebarOpen: boolean;
            college_list_council?: {
                enabled: boolean;
                reverb: {
                    key: string;
                    wsHost: string;
                    wsPort: number;
                    wssPort: number;
                    scheme: string;
                };
            } | null;
            unread_notification_count?: number;
            [key: string]: unknown;
        };
    }
}
