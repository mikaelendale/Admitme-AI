'use client';

import { Head, Link, usePage } from '@inertiajs/react';
import {
    Buildings,
    Fire,
    Lightbulb,
    ListBullets,
    TextT,
} from '@phosphor-icons/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { BrainstormWorkspace } from '@/components/essay-suite/brainstorm-workspace';
import { DraftWorkspace } from '@/components/essay-suite/draft-workspace';
import { RoastScoreWorkspace } from '@/components/essay-suite/roast-score-workspace';
import type {
    EssayRecentRunRow,
    EssaySchoolOption,
} from '@/components/essay-suite/run-types';
import { PlanOverlay } from '@/components/essay-suite/ui';
import { WhyThisSchoolWorkspace } from '@/components/essay-suite/why-this-school-workspace';
import { Page, PageHeader, SegmentedTabs } from '@/components/page/kit';
import { PaywallModal } from '@/components/paywall-modal';
import { Button } from '@/components/ui/button';
import { coerceMentorTone } from '@/lib/essaySuiteRequirements';
import { cn } from '@/lib/utils';
import { essaySuite } from '@/routes';
import type { WhySchoolDraftRow } from '@/types/essay';

type SuiteMode = 'brainstorm' | 'draft' | 'roast_score' | 'why_this_school';

type EssaySuitePageProps = {
    essay_schools?: EssaySchoolOption[];
    essay_active_schools?: EssaySchoolOption[];
    essay_recent_runs?: EssayRecentRunRow[];
    essay_why_school_drafts?: WhySchoolDraftRow[];
    supplements_nav_badge_count?: number;
    essay_defaults?: { mentor_tone?: string };
};

/** One line under the switcher says what the current tool does (replaces the old explainer grid). */
const MODE_SUMMARY: Record<SuiteMode, string> = {
    brainstorm:
        'Essay angles for one school and prompt, built from your saved profile. Runs in the background; every run is saved.',
    draft: 'A first draft from your outline or rough paragraph and the official prompt. Runs in the background; every draft is saved.',
    roast_score:
        'A blunt verdict, clichés, five scores and the single most useful fix — or just a quick score. Scores are AI estimates.',
    why_this_school:
        'A first draft grounded in this school’s row on your college list and your profile.',
};

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

    const brainstormCount = essay_recent_runs.filter(
        (r) => r.run_type === 'brainstorm',
    ).length;
    const draftCount = essay_recent_runs.filter(
        (r) => r.run_type === 'draft',
    ).length;

    const tabs: { value: SuiteMode; label: ReactNode; count?: number }[] = [
        {
            value: 'brainstorm',
            label: (
                <>
                    <Lightbulb className="size-4" aria-hidden />
                    Brainstorm
                </>
            ),
            count: brainstormCount,
        },
        {
            value: 'draft',
            label: (
                <>
                    <TextT className="size-4" aria-hidden />
                    Draft
                </>
            ),
            count: draftCount,
        },
        {
            value: 'roast_score',
            label: (
                <>
                    <Fire className="size-4" aria-hidden />
                    Roast + score
                </>
            ),
        },
        {
            value: 'why_this_school',
            label: (
                <>
                    <Buildings className="size-4" aria-hidden />
                    Why this school
                </>
            ),
        },
    ];

    return (
        <>
            <Head title="Essay suite" />

            <div className="relative">
                <div
                    className={cn(
                        !hasPaidAccess &&
                            'pointer-events-none blur-sm select-none',
                    )}
                    inert={!hasPaidAccess}
                >
                    <Page width="default">
                        <PageHeader
                            title="Essay suite"
                            description="Brainstorm angles, turn notes into a first draft, get a roast and five scores, or start a Why-this-school draft."
                            actions={
                                <Button
                                    variant="outline"
                                    asChild
                                    className="h-9 gap-2 rounded-full px-4"
                                >
                                    <Link href="/essay-suite/supplements">
                                        <ListBullets
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Supplements
                                        {supplements_nav_badge_count > 0 ? (
                                            <span className="nums inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-amber-500/20 px-1.5 text-[11.5px] font-semibold text-amber-800 dark:text-amber-200">
                                                <span className="sr-only">
                                                    due soon:{' '}
                                                </span>
                                                {supplements_nav_badge_count}
                                            </span>
                                        ) : null}
                                    </Link>
                                </Button>
                            }
                        />

                        <SegmentedTabs
                            label="Essay tools"
                            items={tabs}
                            value={mode}
                            onChange={setMode}
                            className="grid w-full grid-cols-2 *:justify-center sm:inline-flex sm:w-auto"
                        />
                        <p className="mt-3 mb-7 max-w-2xl text-[13.5px] leading-relaxed text-muted-foreground">
                            {MODE_SUMMARY[mode]}
                        </p>

                        <div role="tabpanel" aria-label="Essay tool">
                            {mode === 'brainstorm' ? (
                                <BrainstormWorkspace
                                    schools={essay_schools}
                                    recentRuns={essay_recent_runs}
                                    defaultMentorTone={defaultMentorTone}
                                />
                            ) : null}
                            {mode === 'draft' ? (
                                <DraftWorkspace
                                    schools={essay_schools}
                                    recentRuns={essay_recent_runs}
                                    defaultMentorTone={defaultMentorTone}
                                />
                            ) : null}
                            {mode === 'roast_score' ? (
                                <RoastScoreWorkspace
                                    recentRuns={essay_recent_runs}
                                />
                            ) : null}
                            {mode === 'why_this_school' ? (
                                <WhyThisSchoolWorkspace
                                    activeSchools={activeSchools}
                                    whyDraftRows={essay_why_school_drafts}
                                />
                            ) : null}
                        </div>
                    </Page>
                </div>

                {!hasPaidAccess ? (
                    <PlanOverlay
                        title="Essay suite requires an active plan"
                        description="Unlock essay roasting, draft assistant, cliché detector and more."
                        onUnlock={() => setPaywallOpen(true)}
                    />
                ) : null}

                <PaywallModal
                    open={paywallOpen}
                    onOpenChange={setPaywallOpen}
                />
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
