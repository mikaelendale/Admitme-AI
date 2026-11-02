import { Link } from '@inertiajs/react';
import { LogoMark } from '@/components/brand/logo-mark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-canvas p-4 sm:p-8">
            <div className="w-full max-w-[400px] rounded-2xl border border-border bg-background p-7 shadow-window sm:p-9">
                <div className="mb-7 flex flex-col items-center gap-4 text-center">
                    <Link
                        href={home()}
                        aria-label="AdmitMe home"
                        className="flex size-10 items-center justify-center rounded-xl bg-foreground text-background"
                    >
                        <LogoMark className="size-[18px]" />
                    </Link>
                    <div className="space-y-1.5">
                        <h1 className="text-[22px] font-semibold tracking-tight text-foreground">
                            {title}
                        </h1>
                        {description ? (
                            <p className="text-[14px] text-muted-foreground">
                                {description}
                            </p>
                        ) : null}
                    </div>
                </div>
                {children}
            </div>
            <p className="mt-6 text-[12.5px] text-muted-foreground">
                AdmitMe · your admissions workspace
            </p>
        </div>
    );
}
