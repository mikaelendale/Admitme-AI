'use client';

import type { ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { Buildings, Fire, Lightbulb, ListBullets, TextT } from '@phosphor-icons/react';
import { useMemo, useState } from 'react';
import { PaywallModal } from '@/components/paywall-modal';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { BrainstormWorkspace } from '@/components/essay-suite/brainstorm-workspace';
import { DraftWorkspace } from '@/components/essay-suite/draft-workspace';
import { RoastScoreWorkspace } from '@/components/essay-suite/roast-score-workspace';
import { WhyThisSchoolWorkspace } from '@/components/essay-suite/why-this-school-workspace';
import type { EssayRecentRunRow, EssaySchoolOption } from '@/components/essay-suite/run-types';
import type { WhySchoolDraftRow } from '@/types/essay';
import { coerceMentorTone } from '@/lib/essaySuiteRequirements';
import { cn } from '@/lib/utils';
import { essaySuite } from '@/routes';

type SuiteMode = 'brainstorm' | 'draft' | 'roast_score' | 'why_this_school';

type EssaySuitePageProps = {
    essay_schools?: EssaySchoolOption[];
    essay_active_schools?: EssaySchoolOption[];
    essay_recent_runs?: EssayRecentRunRow[];
    essay_why_school_drafts?: WhySchoolDraftRow[];
    supplements_nav_badge_count?: number;
    essay_defaults?: { mentor_tone?: string };
};

function Panel({
    title,
    children,
    className,
}: {
    title: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'rounded-lg border border-border/50 bg-card/30 p-3',
                className,
            )}
        >
            <h3 className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                {title}
            </h3>
            <div className="mt-2">{children}</div>
        </div>
    );
}

export default function EssaySuite() {
    const {
        essay_schools = [],
        essay_active_schools,
        essay_recent_runs = [],
        essay_why_school_drafts = [],
        supplements_nav_badge_count = 0,
        essay_defaults = {},
        isSubscribed = false,
    } = usePage<EssaySuitePageProps>().props;

    const activeSchools = essay_active_schools ?? essay_schools;
    const defaultMentorTone = coerceMentorTone(essay_defaults.mentor_tone);

    const [mode, setMode] = useState<SuiteMode>('brainstorm');
    const [paywallOpen, setPaywallOpen] = useState(false);

    const hasPaidAccess = isSubscribed === true;

    const countLabel = useMemo(() => {
        if (mode === 'brainstorm') {
            const n = essay_recent_runs.filter((r) => r.run_type === 'brainstorm').length;

            return `${n} brainstorm run${n === 1 ? '' : 's'}`;
        }
        if (mode === 'draft') {
            const n = essay_recent_runs.filter((r) => r.run_type === 'draft').length;

            return `${n} draft job${n === 1 ? '' : 's'}`;
        }
        if (mode === 'roast_score') {
            return 'Roast + quick score';
        }

        return 'Why This School drafts';
    }, [essay_recent_runs, mode]);

    return (
        <>
            <Head title="Essay suite" />

            <div className="page-shell relative">
                <div
                    className={cn(
                        !hasPaidAccess && 'pointer-events-none select-none blur-sm',
                    )}
                >
                <header className="mb-4 space-y-1">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground">
                        Essay suite
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Draft and brainstorm run as background jobs per school. Roast + Score and Why This School call
                        the essay models directly. Supplements open on a dedicated tracker page.
                    </p>
                </header>

                <section className="mb-4 overflow-hidden rounded-xl border border-border/60 bg-muted/20">
                    <div className="border-b border-border/50 px-3 py-2">
                        <p className="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                            Essay intelligence
                        </p>
                    </div>
                    <div className="grid grid-cols-1 divide-y divide-border/50 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-5 lg:divide-x">
                        <div className="p-3">
                            <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                <Lightbulb className="size-3.5 text-primary" aria-hidden />
                                Brainstorm
                            </div>
                            <p className="mt-1 text-sm leading-snug text-muted-foreground">
                                Angles per school via queued markdown jobs.
                            </p>
                        </div>
                        <div className="p-3">
                            <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                <TextT className="size-3.5 text-muted-foreground" aria-hidden />
                                Draft
                            </div>
                            <p className="mt-1 text-sm leading-snug text-muted-foreground">
                                First drafts from seed + prompt, async.
                            </p>
                        </div>
                        <div className="p-3">
                            <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                <Fire className="size-3.5 text-amber-500/90" aria-hidden />
                                Roast + Score
                            </div>
                            <p className="mt-1 text-sm leading-snug text-muted-foreground">
                                Brutal verdict, clichés, five calibrated scores, top fix — or score-only.
                            </p>
                        </div>
                        <div className="p-3">
                            <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                <Buildings className="size-3.5 text-muted-foreground" aria-hidden />
                                Why This School
                            </div>
                            <p className="mt-1 text-sm leading-snug text-muted-foreground">
                                First draft grounded in your list row + profile.
                            </p>
                        </div>
                        <div className="p-3">
                            <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                <ListBullets className="size-3.5 text-muted-foreground" aria-hidden />
                                Supplements
                            </div>
                            <p className="mt-1 text-sm leading-snug text-muted-foreground">
                                Prompts, limits, deadlines, statuses — no AI calls.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="mb-3 grid gap-2 sm:grid-cols-3">
                    <Panel title="Modes">
                        <p className="text-sm text-muted-foreground">
                            Use tabs for in-page tools; Supplements opens its own view.
                        </p>
                    </Panel>
                    <Panel title="Jobs">
                        <p className="text-sm text-muted-foreground">
                            Brainstorm &amp; Draft outputs appear on each run card; poll until complete.
                        </p>
                    </Panel>
                    <Panel title="Roast + Score">
                        <p className="text-sm text-muted-foreground">
                            Full roast persists server-side; quick score is lightweight (scores + top fix only).
                        </p>
                    </Panel>
                </div>

                <Tabs
                    value={mode}
                    onValueChange={(v) => setMode(v as SuiteMode)}
                    className="gap-3"
                >
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                        <TabsList className="h-auto w-full flex-wrap gap-0.5 bg-muted/50 p-1 sm:w-fit">
                            <TabsTrigger
                                value="brainstorm"
                                className="gap-1 rounded-md px-2 py-1.5 text-sm"
                            >
                                <Lightbulb className="size-3.5" />
                                Brainstorm
                            </TabsTrigger>
                            <TabsTrigger value="draft" className="gap-1 rounded-md px-2 py-1.5 text-sm">
                                <TextT className="size-3.5" />
                                Draft
                            </TabsTrigger>
                            <TabsTrigger
                                value="roast_score"
                                className="gap-1 rounded-md px-2 py-1.5 text-sm"
                            >
                                <Fire className="size-3.5" />
                                Roast + Score
                            </TabsTrigger>
                            <TabsTrigger
                                value="why_this_school"
                                className="gap-1 rounded-md px-2 py-1.5 text-sm"
                            >
                                <Buildings className="size-3.5" />
                                Why This School
                            </TabsTrigger>
                        </TabsList>

                        <Link
                            href="/essay-suite/supplements"
                            className={cn(
                                'inline-flex h-9 items-center justify-center gap-1 rounded-md border border-transparent px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground',
                            )}
                        >
                            <ListBullets className="size-3.5" />
                            Supplements
                            {supplements_nav_badge_count > 0 ? (
                                <span className="rounded-full bg-amber-500/25 px-1.5 py-0.5 text-xs font-semibold text-amber-950 dark:text-amber-100">
                                    {supplements_nav_badge_count}
                                </span>
                            ) : null}
                        </Link>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 bg-background px-3 py-2 text-sm text-muted-foreground">
                        <span>{countLabel}</span>
                    </div>

                    <TabsContent value="brainstorm" className="mt-0 outline-none data-[state=inactive]:hidden">
                        <BrainstormWorkspace
                            schools={essay_schools}
                            recentRuns={essay_recent_runs}
                            defaultMentorTone={defaultMentorTone}
                        />
                    </TabsContent>
                    <TabsContent value="draft" className="mt-0 outline-none data-[state=inactive]:hidden">
                        <DraftWorkspace
                            schools={essay_schools}
                            recentRuns={essay_recent_runs}
                            defaultMentorTone={defaultMentorTone}
                        />
                    </TabsContent>
                    <TabsContent value="roast_score" className="mt-0 outline-none data-[state=inactive]:hidden">
                        <div className="overflow-hidden rounded-xl border border-border/60 bg-card/40 p-4">
                            <RoastScoreWorkspace recentRuns={essay_recent_runs} />
                        </div>
                    </TabsContent>
                    <TabsContent value="why_this_school" className="mt-0 outline-none data-[state=inactive]:hidden">
                        <div className="overflow-hidden rounded-xl border border-border/60 bg-card/40 p-4">
                            <WhyThisSchoolWorkspace
                                activeSchools={activeSchools}
                                whyDraftRows={essay_why_school_drafts}
                            />
                        </div>
                    </TabsContent>
                </Tabs>
                </div>

                {!hasPaidAccess ? (
                    <div className="absolute inset-0 z-30 flex items-center justify-center bg-black/55 p-4 supports-backdrop-filter:backdrop-blur-[2px]">
                        <Card className="pointer-events-auto w-full max-w-md border-border/80 shadow-lg">
                            <CardHeader>
                                <CardTitle>Essay suite requires an active plan</CardTitle>
                                <CardDescription>
                                    Unlock essay roasting, draft assistant, cliché detector and more
                                </CardDescription>
                            </CardHeader>
                            <CardFooter className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                                <Button
                                    type="button"
                                    className="w-full sm:w-auto"
                                    onClick={() => setPaywallOpen(true)}
                                >
                                    Unlock now
                                </Button>
                            </CardFooter>
                        </Card>
                    </div>
                ) : null}

                <PaywallModal open={paywallOpen} onOpenChange={setPaywallOpen} />
            </div>
        </>
    );
}

EssaySuite.layout = {
    breadcrumbs: [
        {
            title: 'Essays',
            href: essaySuite.url(),
        },
    ],
};
