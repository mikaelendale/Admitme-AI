'use client';

import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Buildings,
    CaretRight,
    FileText,
    HandHeart,
    Info,
    Lightning,
    Medal,
    Plus,
    TextAlignLeft,
    UsersThree,
} from '@phosphor-icons/react';
import type { Icon } from '@phosphor-icons/react';
import type { ChangeEvent } from 'react';
import { useCallback, useMemo, useState } from 'react';
import { EssayStrengthScores } from '@/components/essay/EssayStrengthScores';
import type { EssaySchoolOption } from '@/components/essay-suite/run-types';
import {
    Field,
    Optional,
    PlanOverlay,
    fieldClass,
    selectTriggerClass,
    textareaClass,
} from '@/components/essay-suite/ui';
import {
    EmptyState,
    IconTile,
    Page,
    PageHeader,
    Section,
    SegmentedTabs,
    StatusBadge,
} from '@/components/page/kit';
import type { BadgeTone, Tone } from '@/components/page/kit';
import { PaywallModal } from '@/components/paywall-modal';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useHydrated } from '@/hooks/use-hydrated';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';
import { essaySuite } from '@/routes';
import type {
    EssayRoastScores,
    EssayScoreOnlyPayload,
    SupplementalEssayRow,
} from '@/types/essay';

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

type Filter = 'all' | 'not_started' | 'in_progress' | 'drafted' | 'final';

const TYPE_LABEL: Record<string, string> = {
    why_school: 'Why school',
    activity: 'Activity',
    diversity: 'Diversity',
    community: 'Community',
    additional_info: 'Additional info',
    short_answer: 'Short answer',
    other: 'Other',
};

const TYPE_STYLE: Record<string, { icon: Icon; tone: Tone }> = {
    why_school: { icon: Buildings, tone: 'sky' },
    activity: { icon: Medal, tone: 'violet' },
    diversity: { icon: UsersThree, tone: 'pink' },
    community: { icon: HandHeart, tone: 'teal' },
    additional_info: { icon: Info, tone: 'slate' },
    short_answer: { icon: TextAlignLeft, tone: 'orange' },
    other: { icon: FileText, tone: 'slate' },
};

const STATUS_TONE: Record<string, BadgeTone> = {
    not_started: 'neutral',
    in_progress: 'warning',
    drafted: 'info',
    final: 'success',
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

function SupplementRow({
    row,
    hydrated,
    onOpen,
}: {
    row: SupplementalEssayRow;
    hydrated: boolean;
    onOpen: () => void;
}) {
    const style = TYPE_STYLE[row.essay_type] ?? TYPE_STYLE.other;
    // "Today" depends on the reader's clock, so days left only appear after hydration.
    const d = hydrated ? daysUntil(row.due_date) : null;
    const urgent = d != null && d >= 0 && d < 7;
    const due =
        row.due_date && hydrated
            ? new Date(row.due_date + 'T12:00:00').toLocaleDateString(
                  undefined,
                  { month: 'short', day: 'numeric', year: 'numeric' },
              )
            : row.due_date;

    return (
        <li>
            <button
                type="button"
                onClick={onOpen}
                className="flex w-full items-start gap-4 px-4 py-4 text-left transition-colors outline-none hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-inset sm:px-5"
            >
                <IconTile
                    icon={style.icon}
                    tone={style.tone}
                    variant="glyph"
                    className="mt-0.5"
                />
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="text-[15px] font-medium text-foreground">
                            {TYPE_LABEL[row.essay_type] ?? row.essay_type}
                        </span>
                        <StatusBadge
                            tone={STATUS_TONE[row.status] ?? 'neutral'}
                        >
                            {STATUS_LABEL[row.status] ?? row.status}
                        </StatusBadge>
                    </span>
                    <span className="mt-1 line-clamp-2 block text-[13.5px] leading-snug text-muted-foreground">
                        {row.prompt_text}
                    </span>
                    {row.word_limit != null || row.due_date ? (
                        <span className="nums mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[12.5px] text-muted-foreground">
                            {row.word_limit != null ? (
                                <span>Limit {row.word_limit} words</span>
                            ) : null}
                            {row.due_date ? (
                                <span
                                    className={cn(
                                        urgent &&
                                            'font-medium text-destructive',
                                    )}
                                >
                                    Due {due}
                                    {d != null && d >= 0
                                        ? ` · ${d} day${d === 1 ? '' : 's'} left`
                                        : ''}
                                </span>
                            ) : null}
                        </span>
                    ) : null}
                </span>
                <span className="mt-0.5 hidden shrink-0 text-[13px] font-medium text-muted-foreground sm:inline">
                    Edit
                </span>
                <CaretRight
                    className="mt-1 size-4 shrink-0 text-muted-foreground/60"
                    aria-hidden
                />
            </button>
        </li>
    );
}

export default function EssaySupplementsPage() {
    const {
        supplement_groups,
        supplements_summary,
        active_schools,
        supplements_nav_badge_count,
        isSubscribed = false,
    } = usePage<PageProps & { isSubscribed?: boolean }>().props;

    const hydrated = useHydrated();
    const [filter, setFilter] = useState<Filter>('all');
    const [editor, setEditor] = useState<SupplementalEssayRow | null>(null);
    const [editContent, setEditContent] = useState('');
    const [editStatus, setEditStatus] = useState('');
    const [editNotes, setEditNotes] = useState('');
    const [editWordLimit, setEditWordLimit] = useState<string>('');
    const [editDue, setEditDue] = useState('');
    const [saving, setSaving] = useState(false);
    const [scoreBusy, setScoreBusy] = useState(false);
    const [quickScore, setQuickScore] = useState<EssayScoreOnlyPayload | null>(
        null,
    );

    const [addOpen, setAddOpen] = useState(false);
    const [addSchoolId, setAddSchoolId] = useState<string>('');
    const [addSchoolName, setAddSchoolName] = useState('');
    const [addType, setAddType] = useState('why_school');
    const [addPrompt, setAddPrompt] = useState('');
    const [addLimit, setAddLimit] = useState('');
    const [addDue, setAddDue] = useState('');
    const [addStatus, setAddStatus] = useState('not_started');
    const [paywallOpen, setPaywallOpen] = useState(false);

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
                    word_limit:
                        editWordLimit === '' ? null : Number(editWordLimit),
                    due_date: editDue === '' ? null : editDue,
                }),
            });

            if (!res.ok) {
                return;
            }

            setEditor(null);
            router.reload({
                only: [
                    'supplement_groups',
                    'supplements_flat',
                    'supplements_summary',
                ],
            });
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
            const data = (await res.json()) as {
                payload?: EssayScoreOnlyPayload;
            };

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
                ? (active_schools.find((s) => s.id === addSchoolId)?.name ??
                  addSchoolName)
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
            router.reload({
                only: [
                    'supplement_groups',
                    'supplements_flat',
                    'supplements_summary',
                ],
            });
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
        limit != null && wc > limit
            ? 'text-destructive'
            : overPct >= 0.8
              ? 'text-amber-700 dark:text-amber-200'
              : 'text-muted-foreground';

    const empty = supplements_summary.total === 0;

    const filters: { value: Filter; label: string; count: number }[] = [
        { value: 'all', label: 'All', count: supplements_summary.total },
        {
            value: 'not_started',
            label: 'Not started',
            count: supplements_summary.not_started,
        },
        {
            value: 'in_progress',
            label: 'In progress',
            count: supplements_summary.in_progress,
        },
        {
            value: 'drafted',
            label: 'Drafted',
            count: supplements_summary.drafted,
        },
        { value: 'final', label: 'Final', count: supplements_summary.final },
    ];

    return (
        <>
            <Head title="Supplements" />

            <div className="relative">
                <div
                    className={cn(
                        !isSubscribed &&
                            'pointer-events-none blur-sm select-none',
                    )}
                    inert={!isSubscribed}
                >
                    <Page width="default">
                        <PageHeader
                            eyebrow={
                                <Link
                                    href={essaySuite.url()}
                                    className="inline-flex items-center gap-1 transition-colors hover:text-foreground"
                                >
                                    <ArrowLeft
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                    Essay suite
                                </Link>
                            }
                            title="Supplemental essays"
                            description="Track each school’s prompts, word limits, deadlines and drafts."
                            actions={
                                <>
                                    {supplements_nav_badge_count > 0 ? (
                                        <StatusBadge tone="warning">
                                            {supplements_nav_badge_count} due
                                            soon
                                        </StatusBadge>
                                    ) : null}
                                    <Button
                                        type="button"
                                        onClick={() => setAddOpen(true)}
                                        className="h-9 gap-2 rounded-full px-4"
                                    >
                                        <Plus
                                            className="size-4"
                                            weight="bold"
                                            aria-hidden
                                        />
                                        Add supplement
                                    </Button>
                                </>
                            }
                        />

                        {empty ? (
                            <EmptyState
                                icon={
                                    <IconTile
                                        icon={Buildings}
                                        tone="sky"
                                        variant="glyph"
                                        size="lg"
                                    />
                                }
                                title="No supplemental essays yet"
                                description="Add prompts from each school’s website to track your progress here."
                            />
                        ) : (
                            <>
                                <div className="-mx-5 mb-8 scrollbar-none overflow-x-auto px-5 sm:mx-0 sm:px-0">
                                    <SegmentedTabs
                                        label="Filter by status"
                                        items={filters}
                                        value={filter}
                                        onChange={setFilter}
                                    />
                                </div>

                                {filteredGroups.length === 0 ? (
                                    <p className="rounded-2xl border border-dashed border-border px-5 py-8 text-center text-[13.5px] text-muted-foreground">
                                        No supplements with this status.
                                    </p>
                                ) : (
                                    filteredGroups.map((g) => (
                                        <Section
                                            key={g.school_name}
                                            title={g.school_name}
                                            icon={Buildings}
                                        >
                                            <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                                {g.items.map((row) => (
                                                    <SupplementRow
                                                        key={row.id}
                                                        row={row}
                                                        hydrated={hydrated}
                                                        onOpen={() =>
                                                            openEditor(row)
                                                        }
                                                    />
                                                ))}
                                            </ul>
                                        </Section>
                                    ))
                                )}
                            </>
                        )}
                    </Page>
                </div>

                {!isSubscribed ? (
                    <PlanOverlay
                        title="Supplemental essay tracker requires an active plan"
                        description="Track all your school-specific essays, deadlines, word limits and statuses in one place."
                        onUnlock={() => setPaywallOpen(true)}
                    />
                ) : null}

                <PaywallModal
                    open={paywallOpen}
                    onOpenChange={setPaywallOpen}
                />
            </div>

            <Dialog open={addOpen} onOpenChange={setAddOpen}>
                <DialogContent className="max-h-[90vh] gap-5 overflow-y-auto rounded-2xl sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add supplement</DialogTitle>
                        <DialogDescription>
                            Copy the prompt from the school’s website.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4">
                        <Field
                            label={
                                <>
                                    School from your list
                                    <Optional />
                                </>
                            }
                            htmlFor="add-school"
                        >
                            <Select
                                value={addSchoolId || '__none'}
                                onValueChange={(v) =>
                                    setAddSchoolId(v === '__none' ? '' : v)
                                }
                            >
                                <SelectTrigger
                                    id="add-school"
                                    className={selectTriggerClass}
                                >
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none">
                                        Custom name below
                                    </SelectItem>
                                    {active_schools.map((s) => (
                                        <SelectItem key={s.id} value={s.id}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="School name"
                            htmlFor="add-name"
                            hint="Required if you don’t choose a school from your list."
                        >
                            <input
                                id="add-name"
                                value={addSchoolName}
                                onChange={(e) =>
                                    setAddSchoolName(e.target.value)
                                }
                                className={fieldClass}
                            />
                        </Field>
                        <Field label="Essay type" htmlFor="add-type">
                            <Select value={addType} onValueChange={setAddType}>
                                <SelectTrigger
                                    id="add-type"
                                    className={selectTriggerClass}
                                >
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
                        </Field>
                        <Field label="Prompt" htmlFor="add-prompt">
                            <textarea
                                id="add-prompt"
                                value={addPrompt}
                                onChange={(
                                    e: ChangeEvent<HTMLTextAreaElement>,
                                ) => setAddPrompt(e.target.value)}
                                rows={4}
                                className={cn(textareaClass, 'min-h-[96px]')}
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Word limit" htmlFor="add-limit">
                                <input
                                    id="add-limit"
                                    type="number"
                                    inputMode="numeric"
                                    value={addLimit}
                                    onChange={(e) =>
                                        setAddLimit(e.target.value)
                                    }
                                    className={fieldClass}
                                />
                            </Field>
                            <Field label="Due date" htmlFor="add-due">
                                <input
                                    id="add-due"
                                    type="date"
                                    value={addDue}
                                    onChange={(e) => setAddDue(e.target.value)}
                                    className={fieldClass}
                                />
                            </Field>
                        </div>
                        <Field label="Initial status" htmlFor="add-status">
                            <Select
                                value={addStatus}
                                onValueChange={setAddStatus}
                            >
                                <SelectTrigger
                                    id="add-status"
                                    className={selectTriggerClass}
                                >
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
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-xl"
                            onClick={() => setAddOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            className="rounded-xl"
                            onClick={() => void createSupplement()}
                        >
                            Save
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={editor !== null}
                onOpenChange={(o) => !o && setEditor(null)}
            >
                <DialogContent className="max-h-[92vh] gap-5 overflow-y-auto rounded-2xl sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit supplement</DialogTitle>
                        <DialogDescription>
                            {editor
                                ? `${editor.school_display_name || editor.school_name} · ${TYPE_LABEL[editor.essay_type] ?? editor.essay_type}`
                                : null}
                        </DialogDescription>
                    </DialogHeader>
                    {editor ? (
                        <div className="grid gap-5">
                            <div className="rounded-xl bg-muted/70 px-4 py-3">
                                <p className="text-[12.5px] font-medium text-muted-foreground">
                                    Prompt
                                </p>
                                <p className="mt-1 text-[14px] leading-relaxed text-foreground/90">
                                    {editor.prompt_text}
                                </p>
                            </div>
                            <div>
                                <div className="mb-1.5 flex items-baseline justify-between gap-3">
                                    <label
                                        htmlFor="edit-content"
                                        className="text-[13px] text-muted-foreground"
                                    >
                                        Your essay
                                    </label>
                                    <span
                                        className={cn(
                                            'nums text-[12.5px]',
                                            wcColor,
                                        )}
                                    >
                                        {wc}
                                        {limit != null
                                            ? ` / ${limit} words`
                                            : ' words'}
                                    </span>
                                </div>
                                <textarea
                                    id="edit-content"
                                    value={editContent}
                                    onChange={(
                                        e: ChangeEvent<HTMLTextAreaElement>,
                                    ) => setEditContent(e.target.value)}
                                    rows={10}
                                    className={cn(
                                        textareaClass,
                                        'min-h-[220px] text-[15px] leading-[1.75]',
                                    )}
                                />
                            </div>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="gap-1.5 rounded-lg"
                                    disabled={
                                        scoreBusy ||
                                        editContent.trim().length < 100
                                    }
                                    onClick={() => void quickScoreEssay()}
                                >
                                    <Lightning className="size-4" aria-hidden />
                                    {scoreBusy ? 'Scoring…' : 'Quick score'}
                                </Button>
                                <span className="text-[12.5px] leading-snug text-muted-foreground">
                                    {editContent.trim().length < 100
                                        ? 'Needs at least 100 characters.'
                                        : 'Sends this essay to the AI scorer. Scores are estimates.'}
                                </span>
                            </div>
                            {quickScore ? (
                                <div className="space-y-3">
                                    <EssayStrengthScores
                                        scores={
                                            quickScore.scores as EssayRoastScores
                                        }
                                    />
                                    <p className="rounded-xl bg-muted/70 px-4 py-3 text-[13.5px] leading-relaxed font-medium text-foreground">
                                        <span className="mb-0.5 block text-[12.5px] font-normal text-muted-foreground">
                                            Top priority fix
                                        </span>
                                        {quickScore.top_priority_fix}
                                    </p>
                                </div>
                            ) : null}
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Status" htmlFor="edit-status">
                                    <Select
                                        value={editStatus}
                                        onValueChange={setEditStatus}
                                    >
                                        <SelectTrigger
                                            id="edit-status"
                                            className={selectTriggerClass}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.keys(STATUS_LABEL).map(
                                                (k) => (
                                                    <SelectItem
                                                        key={k}
                                                        value={k}
                                                    >
                                                        {STATUS_LABEL[k]}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field label="Word limit" htmlFor="edit-limit">
                                    <input
                                        id="edit-limit"
                                        inputMode="numeric"
                                        value={editWordLimit}
                                        onChange={(e) =>
                                            setEditWordLimit(e.target.value)
                                        }
                                        className={fieldClass}
                                    />
                                </Field>
                                <Field label="Due date" htmlFor="edit-due">
                                    <input
                                        id="edit-due"
                                        type="date"
                                        value={editDue}
                                        onChange={(e) =>
                                            setEditDue(e.target.value)
                                        }
                                        className={fieldClass}
                                    />
                                </Field>
                            </div>
                            <Field label="Notes" htmlFor="edit-notes">
                                <textarea
                                    id="edit-notes"
                                    value={editNotes}
                                    onChange={(
                                        e: ChangeEvent<HTMLTextAreaElement>,
                                    ) => setEditNotes(e.target.value)}
                                    rows={2}
                                    className={cn(
                                        textareaClass,
                                        'min-h-[60px]',
                                    )}
                                />
                            </Field>
                        </div>
                    ) : null}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="rounded-xl"
                            onClick={() => setEditor(null)}
                        >
                            Close
                        </Button>
                        <Button
                            type="button"
                            className="rounded-xl"
                            disabled={saving}
                            onClick={() => void saveEditor()}
                        >
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
