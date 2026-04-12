'use client';

import type { PropsWithChildren } from 'react';
import ModeToggle from '@/components/mode-toggle';

function OnboardingDotPattern() {
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

export default function OnboardingLayout({ children }: PropsWithChildren) {
    return (
        <div className="relative flex h-svh min-h-0 w-full bg-background">
            <OnboardingDotPattern />
            <div className="fixed right-4 top-4 z-30">
                <ModeToggle />
            </div>
            <main className="relative z-10 flex min-h-0 min-w-0 flex-1 flex-col">
                {children}
            </main>
        </div>
    );
}
