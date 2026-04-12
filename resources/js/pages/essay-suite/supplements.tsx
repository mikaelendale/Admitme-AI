'use client';

import type { ChangeEvent } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from '@phosphor-icons/react';
import { useCallback, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import type { EssaySchoolOption } from '@/components/essay-suite/run-types';
import type { SupplementalEssayRow } from '@/types/essay';
import { essaySuite } from '@/routes';
import { EssayStrengthScores } from '@/components/essay/EssayStrengthScores';
import type { EssayRoastScores, EssayScoreOnlyPayload } from '@/types/essay';
import { cn } from '@/lib/utils';

type Group = {
    school_name: string;
    items: SupplementalEssayRow[];
};

type Summary = {
    total: number;
    not_started: number;
    in_progress: number;
    drafted: number;
    final: number;
};

type PageProps = {
    supplement_groups: Group[];
    supplements_flat: SupplementalEssayRow[];
    supplements_summary: Summary;
    active_schools: EssaySchoolOption[];
    supplements_nav_badge_count: number;
};

const TYPE_LABEL: Record<string, string> = {
    why_school: 'Why school',
    activity: 'Activity',
    diversity: 'Diversity',
    community: 'Community',
    additional_info: 'Additional info',
    short_answer: 'Short answer',
    other: 'Other',
};

const TYPE_CLASS: Record<string, string> = {
    why_school: 'border-sky-500/40 bg-sky-500/10 text-sky-950 dark:text-sky-100',
    activity: 'border-violet-500/40 bg-violet-500/10 text-violet-950 dark:text-violet-100',
    diversity: 'border-fuchsia-500/40 bg-fuchsia-500/10 text-fuchsia-950 dark:text-fuchsia-100',
    community: 'border-teal-500/40 bg-teal-500/10 text-teal-950 dark:text-teal-100',
    additional_info: 'border-slate-500/40 bg-slate-500/10 text-slate-900 dark:text-slate-100',
    short_answer: 'border-orange-500/40 bg-orange-500/10 text-orange-950 dark:text-orange-100',
    other: 'border-border/60 bg-muted/40 text-muted-foreground',
};

const STATUS_CLASS: Record<string, string> = {
    not_started: 'border-border/60 bg-muted/40 text-muted-foreground',
    in_progress: 'border-amber-500/40 bg-amber-500/10 text-amber-950 dark:text-amber-100',
    drafted: 'border-sky-600/40 bg-sky-600/10 text-sky-950 dark:text-sky-100',
    final: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-950 dark:text-emerald-100',
};

const STATUS_LABEL: Record<string, string> = {
    not_started: 'Not started',
    in_progress: 'In progress',
    drafted: 'Drafted',
    final: 'Final',
};

function wordCount(s: string): number {
    const t = s.trim();

    return t ? t.split(/\s+/).filter(Boolean).length : 0;
}

function daysUntil(dateStr: string | null): number | null {
    if (!dateStr) {
        return null;
    }
    const d = new Date(dateStr + 'T12:00:00');
    if (Number.isNaN(d.getTime())) {
        return null;
    }
    const now = new Date();
    now.setHours(12, 0, 0, 0);

    return Math.ceil((d.getTime() - now.getTime()) / 86400000);
}

export default function EssaySupplementsPage() {
    const {
        supplement_groups,
        supplements_summary,
        active_schools,
        supplements_nav_badge_count,
    } = usePage<PageProps>().props;

    const [filter, setFilter] = useState<string>('all');
    const [editor, setEditor] = useState<SupplementalEssayRow | null>(null);
    const [editContent, setEditContent] = useState('');
    const [editStatus, setEditStatus] = useState('');
    const [editNotes, setEditNotes] = useState('');
    const [editWordLimit, setEditWordLimit] = useState<string>('');
    const [editDue, setEditDue] = useState('');
    const [saving, setSaving] = useState(false);
    const [scoreBusy, setScoreBusy] = useState(false);
    const [quickScore, setQuickScore] = useState<EssayScoreOnlyPayload | null>(null);

    const [addOpen, setAddOpen] = useState(false);
    const [addSchoolId, setAddSchoolId] = useState<string>('');
    const [addSchoolName, setAddSchoolName] = useState('');
    const [addType, setAddType] = useState('why_school');
    const [addPrompt, setAddPrompt] = useState('');
    const [addLimit, setAddLimit] = useState('');
    const [addDue, setAddDue] = useState('');
    const [addStatus, setAddStatus] = useState('not_started');

    const filteredGroups = useMemo(() => {
        if (filter === 'all') {
            return supplement_groups;
        }

        return supplement_groups
            .map((g) => ({
                ...g,
                items: g.items.filter((i) => i.status === filter),
            }))
            .filter((g) => g.items.length > 0);
    }, [filter, supplement_groups]);

    const openEditor = useCallback((row: SupplementalEssayRow) => {
        setEditor(row);
        setEditContent(row.content ?? '');
        setEditStatus(row.status);
        setEditNotes(row.notes ?? '');
        setEditWordLimit(row.word_limit != null ? String(row.word_limit) : '');
        setEditDue(row.due_date ?? '');
        setQuickScore(null);
    }, []);

    const saveEditor = useCallback(async () => {
        if (!editor) {
            return;
        }
        setSaving(true);
        try {
            const res = await fetch(`/essay-suite/supplements/${editor.id}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({
                    content: editContent,
                    status: editStatus,
                    notes: editNotes,
                    word_limit: editWordLimit === '' ? null : Number(editWordLimit),
                    due_date: editDue === '' ? null : editDue,
                }),
            });
            if (!res.ok) {
                return;
            }
            setEditor(null);
            router.reload({ only: ['supplement_groups', 'supplements_flat', 'supplements_summary'] });
        } finally {
            setSaving(false);
        }
    }, [editContent, editDue, editNotes, editStatus, editWordLimit, editor]);

    const quickScoreEssay = useCallback(async () => {
        if (!editContent.trim() || editContent.trim().length < 100) {
            return;
        }
        setScoreBusy(true);
        setQuickScore(null);
        try {
            const res = await fetch('/essay-suite/score-only', {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({ essay_text: editContent }),
            });
            const data = (await res.json()) as { payload?: EssayScoreOnlyPayload };
            if (res.ok && data.payload) {
                setQuickScore(data.payload);
            }
        } finally {
            setScoreBusy(false);
        }
    }, [editContent]);

    const createSupplement = useCallback(async () => {
        const name =
            addSchoolId !== ''
                ? (active_schools.find((s) => s.id === addSchoolId)?.name ?? addSchoolName)
                : addSchoolName.trim();
        if (name === '' || addPrompt.trim().length < 10) {
            return;
        }
        const res = await fetch('/essay-suite/supplements', {
            method: 'POST',
            credentials: 'same-origin',
            headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
            body: JSON.stringify({
                school_name: name,
                college_list_school_id: addSchoolId || null,
                prompt_text: addPrompt.trim(),
                word_limit: addLimit === '' ? null : Number(addLimit),
                essay_type: addType,
                status: addStatus,
                due_date: addDue === '' ? null : addDue,
            }),
        });
        if (res.ok) {
            setAddOpen(false);
            setAddPrompt('');
            setAddDue('');
            setAddLimit('');
            router.reload({ only: ['supplement_groups', 'supplements_flat', 'supplements_summary'] });
        }
    }, [
        active_schools,
        addDue,
        addLimit,
        addPrompt,
        addSchoolId,
        addSchoolName,
        addStatus,
        addType,
    ]);

    const wc = wordCount(editContent);
    const limit = editWordLimit === '' ? null : Number(editWordLimit);
    const overPct = limit != null && limit > 0 ? wc / limit : 0;
    const wcColor =
        limit != null && wc > limit ? 'text-destructive' : overPct >= 0.8 ? 'text-amber-700 dark:text-amber-200' : '';

    const empty = supplements_summary.total === 0;

    return (
        <>
            <Head title="Supplements" />

            <div className="page-shell">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-foreground">
                            Supplemental essays
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Track prompts, deadlines, and drafts.{' '}
                            <Link href={essaySuite.url()} className="text-primary underline-offset-4 hover:underline">
                                Back to Essay suite
                            </Link>
                        </p>
                    </div>
                    {supplements_nav_badge_count > 0 ? (
                        <span className="rounded-full bg-amber-500/20 px-2 py-0.5 text-xs font-semibold text-amber-950 dark:text-amber-100">
                            {supplements_nav_badge_count} due soon
                        </span>
                    ) : null}
                </div>

                <div className="mb-4 flex flex-wrap gap-2 text-sm">
                    {(
                        [
                            ['all', 'Total', supplements_summary.total],
                            ['not_started', 'Not started', supplements_summary.not_started],
                            ['in_progress', 'In progress', supplements_summary.in_progress],
                            ['drafted', 'Drafted', supplements_summary.drafted],
                            ['final', 'Final', supplements_summary.final],
                        ] as const
                    ).map(([key, label, n]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setFilter(key)}
                            className={`rounded-full border px-3 py-1 transition-colors ${
                                filter === key
                                    ? 'border-primary/50 bg-primary/10 text-foreground'
                                    : 'border-border/60 bg-card/40 text-muted-foreground hover:bg-muted/40'
                            }`}
                        >
                            {label}: {n}
                        </button>
                    ))}
                </div>

                {empty ? (
                    <div className="rounded-xl border border-dashed border-border/70 bg-muted/10 p-8 text-center">
                        <p className="text-sm text-muted-foreground">
                            No supplemental essays added yet. Add prompts from each school&apos;s website to track
                            your progress here.
                        </p>
                        <Button type="button" className="mt-4" onClick={() => setAddOpen(true)}>
                            <Plus className="mr-1 size-4" weight="bold" />
                            Add supplement
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-8">
                        {filteredGroups.map((g) => (
                            <section key={g.school_name}>
                                <h2 className="border-b border-border/50 pb-2 text-lg font-semibold text-foreground">
                                    {g.school_name}
                                </h2>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    {g.items.map((row) => {
                                        const d = daysUntil(row.due_date);
                                        const urgent = d != null && d >= 0 && d < 7;

                                        return (
                                            <div
                                                key={row.id}
                                                className="rounded-xl border border-border/60 bg-card/40 p-4 text-sm shadow-xs"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span
                                                        className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${TYPE_CLASS[row.essay_type] ?? TYPE_CLASS.other}`}
                                                    >
                                                        {TYPE_LABEL[row.essay_type] ?? row.essay_type}
                                                    </span>
                                                    <span
                                                        className={`rounded-md border px-2 py-0.5 text-xs font-semibold uppercase ${STATUS_CLASS[row.status] ?? ''}`}
                                                    >
                                                        {STATUS_LABEL[row.status] ?? row.status}
                                                    </span>
                                                    {row.word_limit != null ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            Limit {row.word_limit}w
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="mt-2 line-clamp-2 text-muted-foreground">
                                                    {row.prompt_text}
                                                </p>
                                                {row.due_date ? (
                                                    <p
                                                        className={`mt-2 text-xs font-medium ${urgent ? 'text-destructive' : 'text-muted-foreground'}`}
                                                    >
                                                        Due {row.due_date}
                                                        {d != null && d >= 0 ? ` · ${d} day${d === 1 ? '' : 's'} left` : ''}
                                                    </p>
                                                ) : null}
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="mt-3"
                                                    onClick={() => openEditor(row)}
                                                >
                                                    Edit
                                                </Button>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        ))}
                    </div>
                )}

                <div className="mt-8 flex justify-center">
                    <Button type="button" variant="secondary" onClick={() => setAddOpen(true)}>
                        <Plus className="mr-1 size-4" weight="bold" />
                        Add supplement
                    </Button>
                </div>
            </div>

            <Dialog open={addOpen} onOpenChange={setAddOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Add supplement</DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-3 py-2">
                        <div>
                            <Label>School from list (optional)</Label>
                            <Select
                                value={addSchoolId || '__none'}
                                onValueChange={(v) => setAddSchoolId(v === '__none' ? '' : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none">Custom name below</SelectItem>
                                    {active_schools.map((s) => (
                                        <SelectItem key={s.id} value={s.id}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="add-name">School name</Label>
                            <Input
                                id="add-name"
                                value={addSchoolName}
                                onChange={(e) => setAddSchoolName(e.target.value)}
                                placeholder="Required if not choosing from list"
                            />
                        </div>
                        <div>
                            <Label>Essay type</Label>
                            <Select value={addType} onValueChange={setAddType}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.keys(TYPE_LABEL).map((k) => (
                                        <SelectItem key={k} value={k}>
                                            {TYPE_LABEL[k]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="add-prompt">Prompt</Label>
                            <textarea
                                id="add-prompt"
                                value={addPrompt}
                                onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setAddPrompt(e.target.value)}
                                rows={4}
                                className={cn(
                                    'flex min-h-[80px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <Label htmlFor="add-limit">Word limit</Label>
                                <Input
                                    id="add-limit"
                                    type="number"
                                    value={addLimit}
                                    onChange={(e) => setAddLimit(e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="add-due">Due date</Label>
                                <Input
                                    id="add-due"
                                    type="date"
                                    value={addDue}
                                    onChange={(e) => setAddDue(e.target.value)}
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Initial status</Label>
                            <Select value={addStatus} onValueChange={setAddStatus}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.keys(STATUS_LABEL).map((k) => (
                                        <SelectItem key={k} value={k}>
                                            {STATUS_LABEL[k]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="button" onClick={() => void createSupplement()}>
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={editor !== null} onOpenChange={(o) => !o && setEditor(null)}>
                <DialogContent className="max-h-[92vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Edit supplement</DialogTitle>
                    </DialogHeader>
                    {editor ? (
                        <div className="grid gap-3 py-2">
                            <div>
                                <Label>Prompt</Label>
                                <p className="mt-1 rounded-md border border-border/50 bg-muted/20 p-2 text-sm text-muted-foreground">
                                    {editor.prompt_text}
                                </p>
                            </div>
                            <div>
                                <Label htmlFor="edit-content">Your essay</Label>
                                <textarea
                                    id="edit-content"
                                    value={editContent}
                                    onChange={(e: ChangeEvent<HTMLTextAreaElement>) =>
                                        setEditContent(e.target.value)
                                    }
                                    rows={10}
                                    className={cn(
                                        'flex min-h-[200px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    )}
                                />
                                <p className={`mt-1 text-xs tabular-nums ${wcColor}`}>
                                    {wc}
                                    {limit != null ? ` / ${limit} words` : ' words'}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={scoreBusy || editContent.trim().length < 100}
                                    onClick={() => void quickScoreEssay()}
                                >
                                    {scoreBusy ? 'Scoring…' : 'Quick score'}
                                </Button>
                            </div>
                            {quickScore ? (
                                <div className="rounded-lg border border-border/60 bg-muted/10 p-3">
                                    <EssayStrengthScores scores={quickScore.scores as EssayRoastScores} />
                                    <p className="mt-2 text-xs font-medium text-foreground">{quickScore.top_priority_fix}</p>
                                </div>
                            ) : null}
                            <div>
                                <Label>Status</Label>
                                <Select value={editStatus} onValueChange={setEditStatus}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.keys(STATUS_LABEL).map((k) => (
                                            <SelectItem key={k} value={k}>
                                                {STATUS_LABEL[k]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="edit-notes">Notes</Label>
                                <textarea
                                    id="edit-notes"
                                    value={editNotes}
                                    onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setEditNotes(e.target.value)}
                                    rows={2}
                                    className={cn(
                                        'flex min-h-[60px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    )}
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <Label htmlFor="edit-limit">Word limit</Label>
                                    <Input
                                        id="edit-limit"
                                        value={editWordLimit}
                                        onChange={(e) => setEditWordLimit(e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="edit-due">Due date</Label>
                                    <Input
                                        id="edit-due"
                                        type="date"
                                        value={editDue}
                                        onChange={(e) => setEditDue(e.target.value)}
                                    />
                                </div>
                            </div>
                        </div>
                    ) : null}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setEditor(null)}>
                            Close
                        </Button>
                        <Button type="button" disabled={saving} onClick={() => void saveEditor()}>
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

EssaySupplementsPage.layout = {
    breadcrumbs: [
        { title: 'Essays', href: essaySuite.url() },
        { title: 'Supplements', href: '/essay-suite/supplements' },
    ],
};
