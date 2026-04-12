'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import type { EssaySchoolOption } from '@/components/essay-suite/run-types';
import type { WhySchoolDraftRow, WhyThisSchoolPayload } from '@/types/essay';
import { CaretDown } from '@phosphor-icons/react';

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
    const [schoolId, setSchoolId] = useState<string>(() => activeSchools[0]?.id ?? '');
    const [busy, setBusy] = useState(false);
    const [stageIdx, setStageIdx] = useState(0);
    const [payload, setPayload] = useState<WhyThisSchoolPayload | null>(null);
    const [draftText, setDraftText] = useState('');
    const [error, setError] = useState<string | null>(null);

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
            let data: { payload?: WhyThisSchoolPayload; message?: string; errors?: Record<string, string[]> } =
                {};
            try {
                data = JSON.parse(raw) as typeof data;
            } catch {
                /* ignore */
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
        } catch {
            /* ignore */
        }
    }, [draftText]);

    if (activeSchools.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Add schools to your college list to generate a Why This School draft.
            </p>
        );
    }

    return (
        <div className="space-y-5">
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {activeSchools.map((s) => {
                    const on = s.id === schoolId;

                    return (
                        <button
                            key={s.id}
                            type="button"
                            onClick={() => setSchoolId(s.id)}
                            className={`rounded-lg border p-3 text-left text-sm transition-colors ${
                                on
                                    ? 'border-primary/40 bg-primary/5 ring-1 ring-primary/15'
                                    : 'border-border/60 bg-background/80 hover:bg-muted/30'
                            }`}
                        >
                            <span className="font-medium text-foreground">{s.name}</span>
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                {s.tier} · fit {s.fit_score ?? '—'}
                            </span>
                        </button>
                    );
                })}
            </div>

            <Button type="button" disabled={busy || !schoolId} onClick={() => void generate()}>
                Generate draft
            </Button>

            {busy ? (
                <div className="rounded-xl border border-primary/30 bg-primary/5 p-4" aria-live="polite">
                    <p className="text-sm font-medium text-foreground">
                        Writing your Why {selected?.name ?? 'this school'} draft…
                    </p>
                    <p className="mt-2 text-sm text-primary">{STAGES[stageIdx]}</p>
                    <p className="mt-2 text-xs text-muted-foreground">Usually 6–10 seconds</p>
                </div>
            ) : null}

            {error ? <p className="text-sm text-destructive">{error}</p> : null}

            {payload ? (
                <div className="space-y-4">
                    <textarea
                        value={draftText}
                        onChange={(e) => setDraftText(e.target.value)}
                        rows={12}
                        className="w-full resize-y rounded-lg border border-border/60 bg-background p-3 text-sm leading-relaxed"
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="secondary" onClick={() => void copyDraft()}>
                            Copy to clipboard
                        </Button>
                        <Button type="button" variant="outline" onClick={() => void generate()}>
                            Regenerate
                        </Button>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            Hooks used
                        </p>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {payload.hooks_used.map((h) => (
                                <span
                                    key={h}
                                    className="rounded-full border border-border/60 bg-muted/30 px-2 py-0.5 text-xs text-foreground"
                                >
                                    {h}
                                </span>
                            ))}
                        </div>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wider text-amber-900 dark:text-amber-100">
                            Suggested improvements (personalize these)
                        </p>
                        <ol className="mt-2 list-decimal space-y-1 border-l-2 border-amber-500/50 pl-4 text-sm text-muted-foreground">
                            {payload.suggested_improvements.map((s, i) => (
                                <li key={i} className="text-amber-950 dark:text-amber-50">
                                    {s}
                                </li>
                            ))}
                        </ol>
                    </div>
                </div>
            ) : null}

            {schoolId && (draftsBySchool.get(schoolId)?.length ?? 0) > 0 ? (
                <Collapsible defaultOpen={false} className="rounded-lg border border-border/60">
                    <CollapsibleTrigger className="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium text-foreground hover:bg-muted/30">
                        Previous drafts
                        <CaretDown className="size-4" />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="border-t border-border/50 px-3 py-2 text-sm text-muted-foreground">
                        <ul className="space-y-2">
                            {(draftsBySchool.get(schoolId) ?? []).map((d) => (
                                <li key={d.id} className="rounded-md bg-muted/20 px-2 py-1.5">
                                    <span className="text-xs text-muted-foreground">
                                        {d.created_at ? new Date(d.created_at).toLocaleString() : '—'}
                                    </span>
                                    <p className="mt-0.5 text-foreground">{d.draft_preview}</p>
                                </li>
                            ))}
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            ) : null}
        </div>
    );
}
