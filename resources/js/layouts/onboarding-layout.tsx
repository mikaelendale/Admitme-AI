'use client';

import type { PropsWithChildren } from 'react';
import { Toaster } from '@/components/ui/sonner';

/** Onboarding draws its own split layout (step rail + window); this only adds the toaster. */
export default function OnboardingLayout({ children }: PropsWithChildren) {
    return (
        <>
            {children}
            <Toaster richColors closeButton position="top-right" />
        </>
    );
}
