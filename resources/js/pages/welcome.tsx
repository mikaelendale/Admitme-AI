import { Head, Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { LogoMark } from '@/components/brand/logo-mark';
import ModeToggle from '@/components/mode-toggle';
import { dashboard, login, register } from '@/routes';

const stagger = {
    hidden: { opacity: 0 },
    show: {
        opacity: 1,
        transition: {
            staggerChildren: 0.08,
            delayChildren: 0.06,
        },
    },
};

const item = {
    hidden: { opacity: 0, y: 14 },
    show: {
        opacity: 1,
        y: 0,
        transition: {
            type: 'spring' as const,
            stiffness: 380,
            damping: 28,
        },
    },
};

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="AdmitMe AI" />

            <div className="fixed top-4 right-4 z-30">
                <ModeToggle />
            </div>

            <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-canvas px-6 text-foreground">
                <motion.div
                    className="relative z-10 flex w-full max-w-sm flex-col items-center gap-6 text-center"
                    variants={stagger}
                    initial="hidden"
                    animate="show"
                >
                    <motion.div variants={item}>
                        <span className="flex size-11 items-center justify-center rounded-xl bg-foreground text-background">
                            <LogoMark className="size-5" />
                        </span>
                    </motion.div>

                    <motion.div
                        variants={item}
                        className="flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1 text-[12px] text-muted-foreground"
                    >
                        <span className="relative flex size-1.5">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                            <span className="relative inline-flex size-1.5 rounded-full bg-amber-500" />
                        </span>
                        Private beta · under active development
                    </motion.div>

                    <motion.div variants={item} className="space-y-3">
                        <h1 className="text-[30px] leading-tight font-semibold tracking-tight text-foreground">
                            AdmitMe
                        </h1>
                        <p className="text-[16px] leading-relaxed text-foreground/80">
                            A calmer way to build a stronger application.
                        </p>
                        <p className="text-[14px] leading-relaxed text-muted-foreground">
                            Gap analysis, essay feedback, and strategic guidance
                            — all in one place.
                        </p>
                    </motion.div>

                    <motion.div
                        variants={item}
                        className="flex w-full flex-col gap-2"
                    >
                        {auth.user ? (
                            <Link
                                href={dashboard()}
                                className="w-full rounded-full bg-foreground py-2.5 text-sm font-medium text-background transition-opacity hover:opacity-90"
                            >
                                Open dashboard
                            </Link>
                        ) : (
                            <>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="w-full rounded-full bg-foreground py-2.5 text-sm font-medium text-background transition-opacity hover:opacity-90"
                                    >
                                        Request access
                                    </Link>
                                )}
                                <Link
                                    href={login()}
                                    className="w-full rounded-full border border-border bg-background py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted"
                                >
                                    Sign in
                                </Link>
                            </>
                        )}
                    </motion.div>

                    <motion.p
                        variants={item}
                        className="text-xs text-muted-foreground/60"
                    >
                        Features may change without notice.
                    </motion.p>
                </motion.div>
            </div>
        </>
    );
}
