'use client';

import { Link } from '@inertiajs/react';
import {
    Buildings,
    CaretRight,
    Check,
    ClockCounterClockwise,
    Copy,
    FileText,
    Lightbulb,
    Tag,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { EssaySchoolOption } from '@/components/essay-suite/run-types';
import { EmptyState, IconTile, Section } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useHydrated } from '@/hooks/use-hydrated';
import { buildEssayRateLimitMessage } from '@/lib/essayRateLimitMessage';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import { collegeLists } from '@/routes';
import type { WhySchoolDraftRow, WhyThisSchoolPayload } from '@/types/essay';
import { Notice, Working, sentenceCase, toolCardClass } from './ui';

const STAGES = [
    'Pulling school program data…',
    'Matching to your profile…',
    'Drafting…',
] as const;

type Props = {
    activeSchools: EssaySchoolOption[];
    whyDraftRows: WhySchoolDraftRow[];
};

export function WhyThisSchoolWorkspace({ activeSchools, whyDraftRows }: Props) {
    const [schoolId, setSchoolId] = useState<string>(
        () => activeSchools[0]?.id ?? '',
    );
    const [busy, setBusy] = useState(false);
    const [stageIdx, setStageIdx] = useState(0);
    const [payload, setPayload] = useState<WhyThisSchoolPayload | null>(null);
    const [draftText, setDraftText] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [rateLimitMessage, setRateLimitMessage] = useState<string | null>(
        null,
    );
    const [copied, setCopied] = useState(false);
    const hydrated = useHydrated();

    const selected = activeSchools.find((s) => s.id === schoolId);

    const draftsBySchool = useMemo(() => {
        const m = new Map<string, WhySchoolDraftRow[]>();

        for (const row of whyDraftRows) {
            const k = row.college_list_school_id;
            const list = m.get(k) ?? [];
            list.push(row);
            m.set(k, list);
        }

        return m;
    }, [whyDraftRows]);

    useEffect(() => {
        if (!busy) {
            return;
        }

        const id = window.setInterval(() => {
            setStageIdx((i) => (i + 1) % STAGES.length);
        }, 2800);

        return () => window.clearInterval(id);
    }, [busy]);

    const generate = useCallback(async () => {
        if (!schoolId) {
            return;
        }

        setError(null);
        setRateLimitMessage(null);
        setBusy(true);
        setStageIdx(0);

        try {
            const res = await fetch('/essay-suite/why-this-school', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({ college_list_school_id: schoolId }),
            });
            const raw = await res.text();
            let data: {
                payload?: WhyThisSchoolPayload;
                message?: string;
                errors?: Record<string, string[]>;
            } = {};

            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* ignore */
            }

            if (res.status === 429) {
                setRateLimitMessage(
                    buildEssayRateLimitMessage('whySchool', res),
                );

                return;
            }

            if (res.status === 422) {
                setError(
                    data.errors?.analysis?.[0] ??
                        data.errors?.college_list_school_id?.[0] ??
                        data.message ??
                        'Could not generate.',
                );

                return;
            }

            if (!res.ok) {
                setError(data.message ?? 'Request failed.');

                return;
            }

            if (data.payload) {
                setPayload(data.payload);
                setDraftText(data.payload.draft);
            }
        } catch {
            setError('Network error.');
        } finally {
            setBusy(false);
        }
    }, [schoolId]);

    const copyDraft = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(draftText);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        } catch {
            /* ignore */
        }
    }, [draftText]);

    if (activeSchools.length === 0) {
        return (
            <EmptyState
                icon={
                    <IconTile
                        icon={Buildings}
                        tone="green"
                        variant="glyph"
                        size="lg"
                    />
                }
                title="No schools to write about yet"
                description="Add schools to your college list to generate a Why This School draft."
                action={
                    <Button variant="outline" asChild className="rounded-full">
                        <Link href={collegeLists.url()}>
                            Open college lists
                        </Link>
                    </Button>
                }
            />
        );
    }

    const previous = schoolId ? (draftsBySchool.get(schoolId) ?? []) : [];

    return (
        <div className="space-y-9">
            <div className={toolCardClass}>
                <p
                    id="why-school-label"
                    className="mb-3 text-[13px] text-muted-foreground"
                >
                    School
                </p>
                <div className="@container">
                    <div
                        role="radiogroup"
                        aria-labelledby="why-school-label"
                        className="grid gap-2 @lg:grid-cols-2 @3xl:grid-cols-3"
                    >
                        {activeSchools.map((s) => {
                            const on = s.id === schoolId;

                            return (
                                <button
                                    key={s.id}
                                    type="button"
                                    role="radio"
                                    aria-checked={on}
                                    onClick={() => setSchoolId(s.id)}
                                    className={cn(
                                        'flex min-h-12 items-center gap-3 rounded-xl px-3.5 py-2.5 text-left transition-[background-color,box-shadow] duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
                                        on
                                            ? 'bg-card shadow-raised ring-1 ring-foreground/15'
                                            : 'bg-muted hover:bg-foreground/[0.07]',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-4 shrink-0 items-center justify-center rounded-full border transition-colors',
                                            on
                                                ? 'border-foreground bg-foreground'
                                                : 'border-foreground/25 bg-card',
                                        )}
                                    >
                                        {on ? (
                                            <span className="size-1.5 rounded-full bg-background" />
                                        ) : null}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate text-[13.5px] font-medium text-foreground">
                                            {s.name}
                                        </span>
                                        <span className="nums block text-[12.5px] text-muted-foreground">
                                            {sentenceCase(s.tier)} · fit{' '}
                                            {s.fit_score ?? '—'}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <Button
                        type="button"
                        className="h-10 rounded-xl px-4"
                        disabled={busy || !schoolId}
                        onClick={() => void generate()}
                    >
                        Generate draft
                    </Button>
                    <span className="text-[12.5px] leading-snug text-muted-foreground">
                        Uses this school’s details on your list and your
                        profile.
                    </span>
                </div>
            </div>

            {busy ? (
                <Working
                    title={`Writing your Why ${selected?.name ?? 'this school'} draft…`}
                    detail={STAGES[stageIdx]}
                    hint="Usually 6–10 seconds"
                />
            ) : null}

            {rateLimitMessage ? (
                <Notice tone="warning" role="status">
                    {rateLimitMessage}
                </Notice>
            ) : null}
            {error ? (
                <Notice tone="danger" role="alert">
                    {error}
                </Notice>
            ) : null}

            {payload ? (
                <>
                    <Section
                        title="First draft"
                        icon={FileText}
                        actions={
                            <>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="gap-1.5 rounded-lg"
                                    onClick={() => void generate()}
                                    disabled={busy}
                                >
                                    Regenerate
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5 rounded-lg"
                                    onClick={() => void copyDraft()}
                                >
                                    {copied ? (
                                        <Check className="size-4" aria-hidden />
                                    ) : (
                                        <Copy className="size-4" aria-hidden />
                                    )}
                                    {copied ? 'Copied' : 'Copy to clipboard'}
                                </Button>
                            </>
                        }
                    >
                        <textarea
                            aria-label="Why this school draft"
                            value={draftText}
                            onChange={(e) => setDraftText(e.target.value)}
                            rows={12}
                            className="block w-full resize-y rounded-2xl border border-border bg-card px-5 py-4 text-[15px] leading-[1.75] text-foreground transition-[border-color,box-shadow] outline-none focus-visible:border-foreground/25 focus-visible:ring-3 focus-visible:ring-ring/20 sm:px-6"
                        />
                    </Section>

                    {payload.hooks_used.length > 0 ? (
                        <Section title="Hooks used" icon={Tag}>
                            <div className="flex flex-wrap gap-1.5">
                                {payload.hooks_used.map((h) => (
                                    <span
                                        key={h}
                                        className="inline-flex min-h-7 items-center rounded-lg bg-muted px-2.5 py-1 text-[13px] text-foreground"
                                    >
                                        {h}
                                    </span>
                                ))}
                            </div>
                        </Section>
                    ) : null}

                    {payload.suggested_improvements.length > 0 ? (
                        <Section
                            title="Suggested improvements"
                            icon={Lightbulb}
                            description="Personalize these before you use the draft."
                        >
                            <ol className="space-y-2.5">
                                {payload.suggested_improvements.map((s, i) => (
                                    <li
                                        key={i}
                                        className="flex gap-3 text-[14px] leading-relaxed text-foreground/90"
                                    >
                                        <span className="nums flex size-5 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-[11.5px] font-semibold text-amber-800 dark:text-amber-200">
                                            {i + 1}
                                        </span>
                                        <span className="min-w-0">{s}</span>
                                    </li>
                                ))}
                            </ol>
                        </Section>
                    ) : null}
                </>
            ) : null}

            {previous.length > 0 ? (
                <Collapsible defaultOpen={false} className="group/prev">
                    <CollapsibleTrigger className="inline-flex items-center gap-1.5 rounded-md text-[13px] font-medium text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50">
                        <ClockCounterClockwise className="size-4" aria-hidden />
                        Previous drafts for {selected?.name ?? 'this school'}
                        <span className="nums text-muted-foreground/70">
                            {previous.length}
                        </span>
                        <CaretRight
                            className="size-3.5 transition-transform group-data-[state=open]/prev:rotate-90"
                            aria-hidden
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <ul className="mt-3 divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                            {previous.map((d) => (
                                <li key={d.id} className="px-5 py-3.5">
                                    <span className="nums block text-[12.5px] text-muted-foreground">
                                        {hydrated && d.created_at
                                            ? new Date(
                                                  d.created_at,
                                              ).toLocaleString(undefined, {
                                                  month: 'short',
                                                  day: 'numeric',
                                                  hour: 'numeric',
                                                  minute: '2-digit',
                                              })
                                            : '—'}
                                    </span>
                                    <p className="mt-1 text-[13.5px] leading-relaxed text-foreground">
                                        {d.draft_preview}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            ) : null}
        </div>
    );
}
