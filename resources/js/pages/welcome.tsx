import { Head, Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login, register } from '@/routes';
import ModeToggle from '@/components/mode-toggle';

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

function WelcomeDotPattern() {
    return (
        <motion.div
            aria-hidden
            className="pointer-events-none absolute inset-0 z-0"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.7, ease: [0.22, 1, 0.36, 1] }}
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

export default function Welcome({
    canRegister = true,
}: {
    canRegister?: boolean;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="AdmitMe AI" />

            <div className="fixed right-4 top-4 z-30">
                <ModeToggle />
            </div>

            <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background px-6 text-foreground">
                <WelcomeDotPattern />
                <motion.div
                    className="relative z-10 flex w-full max-w-sm flex-col items-center gap-6 text-center"
                    variants={stagger}
                    initial="hidden"
                    animate="show"
                >
                    <motion.div variants={item}>
                        <AppLogoIcon className="size-10 fill-current" />
                    </motion.div>

                    <motion.div
                        variants={item}
                        className="flex items-center gap-2 rounded-full border border-border bg-muted px-3 py-1 text-[11px] text-muted-foreground"
                    >
                        <span className="relative flex size-1.5">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                            <span className="relative inline-flex size-1.5 rounded-full bg-amber-500" />
                        </span>
                        Private beta · under active development
                    </motion.div>

                    <motion.div variants={item} className="space-y-3">
                        <h1 className="font-serif text-xl leading-tight font-medium tracking-normal text-foreground italic sm:text-2xl sm:tracking-wider">
                            AdmitMe AI
                        </h1>
                        <p className="font-serif text-sm leading-relaxed text-muted-foreground italic sm:text-base sm:tracking-wide">
                            A calmer way to build a stronger application.
                        </p>
                        <p className="text-sm leading-relaxed text-muted-foreground">
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
                                className="w-full rounded-md border border-border bg-foreground py-2 text-sm font-medium text-background transition-colors hover:opacity-90"
                            >
                                Open dashboard
                            </Link>
                        ) : (
                            <>
                                {canRegister && (
                                    <Link
                                        href={register()}
                                        className="w-full rounded-md border border-border bg-foreground py-2 text-sm font-medium text-background transition-colors hover:opacity-90"
                                    >
                                        Request access
                                    </Link>
                                )}
                                <Link
                                    href={login()}
                                    className="w-full rounded-md border border-border py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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
