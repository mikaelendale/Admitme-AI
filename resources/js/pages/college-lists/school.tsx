'use client';

import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarBlank,
    Coin,
    LinkSimple,
    MapPin,
} from '@phosphor-icons/react';
import { Button } from '@/components/ui/button';
import {
    type CollegeRow,
    fitScoreStyles,
    tierLabel,
    tierStyles,
} from '@/lib/college-list-display';
import { cn } from '@/lib/utils';
import { collegeLists } from '@/routes';

type PageProps = {
    school: CollegeRow;
};

export default function CollegeListSchoolPage() {
    const { school } = usePage<PageProps>().props;

    return (
        <>
            <Head title={school.name} />

            <div className="page-shell">
                <div className="mb-4">
                    <Button variant="ghost" size="sm" className="h-8 gap-1.5 px-2 text-xs" asChild>
                        <Link href={collegeLists.url()}>
                            <ArrowLeft className="size-3.5" aria-hidden />
                            Back to list
                        </Link>
                    </Button>
                </div>

                <header className="mb-6 space-y-2 border-b border-border/50 pb-4">
                    <h1 className="page-title text-balance">{school.name}</h1>
                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <MapPin className="size-4 shrink-0" aria-hidden />
                        {school.location}
                    </p>
                </header>

                <div className="mx-auto max-w-2xl space-y-0 divide-y divide-border/40">
                    <section className="py-4">
                        <h2 className="mb-3 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            Fit breakdown
                        </h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <span
                                className={cn(
                                    'rounded-md border border-border/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                    tierStyles(school.tier),
                                )}
                            >
                                {tierLabel(school.tier)}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Overall fit{' '}
                                <strong
                                    className={cn(
                                        'text-base font-semibold text-foreground',
                                        fitScoreStyles(school.fitScore),
                                    )}
                                >
                                    {school.fitScore}
                                </strong>
                                <span className="text-muted-foreground">/100</span>
                            </span>
                        </div>
                    </section>
                    <section className="py-4">
                        <h2 className="mb-3 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            Why this school
                        </h2>
                        <p className="text-sm leading-relaxed text-foreground">{school.whyFit}</p>
                    </section>
                    <section className="py-4">
                        <h2 className="mb-3 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            <CalendarBlank className="size-3" aria-hidden />
                            Deadlines
                        </h2>
                        <p className="whitespace-pre-line text-sm leading-snug text-foreground">
                            {school.deadlinesDisplay?.trim() || school.deadline || '—'}
                        </p>
                        {school.admissionsCycleLabel ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Cycle:{' '}
                                <span className="font-medium text-foreground">
                                    {school.admissionsCycleLabel}
                                </span>
                            </p>
                        ) : null}
                    </section>
                    <section className="py-4">
                        <h2 className="mb-3 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                            <LinkSimple className="size-3" aria-hidden />
                            Application
                        </h2>
                        {school.applicationPortalUrl ? (
                            <a
                                href={school.applicationPortalUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm text-primary underline-offset-2 hover:underline"
                            >
                                Open official portal
                            </a>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No verified portal link saved — confirm on the school’s admissions site.
                            </p>
                        )}
                    </section>
                    {school.netCostEstimate === '—' && school.aidEstimate === '—' ? (
                        <p className="text-sm text-muted-foreground">
                            Financial aid estimate not yet available for this school.
                        </p>
                    ) : (
                        <section className="py-4">
                            <h2 className="mb-3 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-[0.15em] text-muted-foreground">
                                <Coin className="size-3" aria-hidden />
                                Financial aid estimator
                            </h2>
                            <p className="text-xs text-muted-foreground">Net cost line (rough band)</p>
                            <p className="mt-1 text-sm font-medium text-foreground">
                                {school.netCostEstimate}
                            </p>
                            <p className="mt-3 text-xs text-muted-foreground">Aid outlook</p>
                            <p className="mt-0.5 text-sm leading-snug text-foreground">
                                {school.aidEstimate}
                            </p>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

CollegeListSchoolPage.layout = {
    breadcrumbs: [{ title: 'College lists', href: collegeLists.url() }],
};
