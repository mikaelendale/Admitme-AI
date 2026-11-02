import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowsClockwise,
    CalendarBlank,
    CaretLeft,
    CaretRight,
    ListBullets,
    PencilSimple,
    Sparkle,
    WarningCircle,
} from '@phosphor-icons/react';
import { useEffect, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { useOptionalMentor } from '@/components/mentor/mentor-context';
import {
    EmptyState,
    Page,
    PageHeader,
    Section,
    SegmentedTabs,
    StatusBadge,
} from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';

type Kind = 'ea' | 'ed' | 'rd' | 'step';

type DeadlineEvent = {
    id: string;
    collegeId: string;
    college: string;
    kind: Kind;
    label: string;
    date: string;
    source: 'list' | 'official';
    href?: string;
};

type College = {
    id: string;
    name: string;
    tier: string | null;
    choice: string;
    ea: string | null;
    ed: string | null;
    rd: string | null;
    rolling: boolean;
    needsDeadline: boolean;
    revision: number;
    applicationId: string | null;
    href: string;
};

type Props = {
    today: string;
    colleges: College[];
    events: DeadlineEvent[];
};

/** How each kind of deadline looks: the round's short name, a chip and a dot in its colour. */
const KIND: Record<
    Kind,
    { short: string; long: string; chip: string; dot: string }
> = {
    ea: {
        short: 'EA',
        long: 'Early Action',
        chip: 'bg-blue-500/12 text-blue-700 dark:text-blue-300',
        dot: 'bg-blue-500',
    },
    ed: {
        short: 'ED',
        long: 'Early Decision',
        chip: 'bg-violet-500/12 text-violet-700 dark:text-violet-300',
        dot: 'bg-violet-500',
    },
    rd: {
        short: 'RD',
        long: 'Regular Decision',
        chip: 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
        dot: 'bg-emerald-500',
    },
    step: {
        short: 'Due',
        long: 'Application step',
        chip: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        dot: 'bg-amber-500',
    },
};

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** Dates are plain YYYY-MM-DD and handled in UTC, so no time zone ever moves a deadline by a day. */
function parse(iso: string): Date {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d));
}

function iso(date: Date): string {
    return date.toISOString().slice(0, 10);
}

function daysBetween(from: string, to: string): number {
    return Math.round((parse(to).getTime() - parse(from).getTime()) / 86400000);
}

function format(isoDate: string, options: Intl.DateTimeFormatOptions): string {
    return parse(isoDate).toLocaleDateString('en-US', {
        ...options,
        timeZone: 'UTC',
    });
}

function relative(today: string, date: string): string {
    const days = daysBetween(today, date);

    if (days === 0) {
        return 'Today';
    }

    if (days === 1) {
        return 'Tomorrow';
    }

    if (days === -1) {
        return 'Yesterday';
    }

    return days > 0 ? `in ${days} days` : `${-days} days ago`;
}

/** The 6 weeks shown for a month, starting on the Sunday on or before the 1st. */
function monthGrid(
    year: number,
    month: number,
): { date: string; inMonth: boolean }[] {
    const first = new Date(Date.UTC(year, month, 1));
    const start = new Date(first);
    start.setUTCDate(1 - first.getUTCDay());

    return Array.from({ length: 42 }, (_, i) => {
        const day = new Date(start);
        day.setUTCDate(start.getUTCDate() + i);

        return { date: iso(day), inMonth: day.getUTCMonth() === month };
    });
}

function KindChip({ kind, className }: { kind: Kind; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex h-5 shrink-0 items-center rounded-md px-1.5 text-[11px] font-semibold',
                KIND[kind].chip,
                className,
            )}
        >
            {KIND[kind].short}
        </span>
    );
}

function SourceBadge({ source }: { source: DeadlineEvent['source'] }) {
    return source === 'official' ? (
        <StatusBadge tone="success">From an official page</StatusBadge>
    ) : (
        <StatusBadge>Not verified</StatusBadge>
    );
}

/** One deadline as a row: the date, the college and round, how far away it is, and where it came from. */
function DeadlineRow({
    event,
    today,
    onEdit,
}: {
    event: DeadlineEvent;
    today: string;
    onEdit: () => void;
}) {
    const days = daysBetween(today, event.date);
    const soon = days >= 0 && days <= 7;

    return (
        <li className="flex items-center gap-3 px-4 py-3 sm:gap-4">
            <span className="flex w-11 shrink-0 flex-col items-center rounded-xl bg-muted py-1.5 leading-none">
                <span className="text-[10.5px] font-medium text-muted-foreground uppercase">
                    {format(event.date, { month: 'short' })}
                </span>
                <span className="mt-1 text-[17px] font-semibold text-foreground tabular-nums">
                    {format(event.date, { day: 'numeric' })}
                </span>
            </span>
            <div className="min-w-0 flex-1">
                <p className="flex min-w-0 items-center gap-2">
                    <KindChip kind={event.kind} />
                    <Link
                        href={event.href ?? `/college-lists/${event.collegeId}`}
                        className="truncate text-[14px] font-medium text-foreground hover:underline"
                    >
                        {event.college}
                    </Link>
                </p>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[12.5px] text-muted-foreground">
                    <span>
                        {event.kind === 'step'
                            ? event.label
                            : KIND[event.kind].long}
                    </span>
                    <span aria-hidden>·</span>
                    <span
                        className={cn(
                            'tabular-nums',
                            soon &&
                                'font-medium text-amber-700 dark:text-amber-300',
                            days < 0 &&
                                'line-through decoration-muted-foreground/50',
                        )}
                    >
                        {relative(today, event.date)}
                    </span>
                </p>
            </div>
            <span className="hidden sm:inline-flex">
                <SourceBadge source={event.source} />
            </span>
            {event.source === 'list' ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0 rounded-lg text-muted-foreground"
                    onClick={onEdit}
                    aria-label={`Edit deadlines for ${event.college}`}
                >
                    <PencilSimple className="size-4" />
                </Button>
            ) : null}
        </li>
    );
}

function Calendar({
    today,
    events,
    selected,
    onSelect,
}: {
    today: string;
    events: DeadlineEvent[];
    selected: string | null;
    onSelect: (date: string) => void;
}) {
    const start = parse(today);
    const [cursor, setCursor] = useState({
        year: start.getUTCFullYear(),
        month: start.getUTCMonth(),
    });
    const days = monthGrid(cursor.year, cursor.month);
    const byDate = useMemo(() => {
        const map = new Map<string, DeadlineEvent[]>();

        for (const event of events) {
            map.set(event.date, [...(map.get(event.date) ?? []), event]);
        }

        return map;
    }, [events]);

    const move = (delta: number) =>
        setCursor(({ year, month }) => {
            const next = new Date(Date.UTC(year, month + delta, 1));

            return { year: next.getUTCFullYear(), month: next.getUTCMonth() };
        });
    const monthLabel = new Date(
        Date.UTC(cursor.year, cursor.month, 1),
    ).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
    const inMonth = events.filter((e) => {
        const d = parse(e.date);

        return (
            d.getUTCFullYear() === cursor.year &&
            d.getUTCMonth() === cursor.month
        );
    }).length;

    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="flex items-center gap-2 border-b border-border px-3 py-2.5 sm:px-4">
                <h2 className="min-w-0 flex-1 text-[15px] font-semibold text-foreground">
                    {monthLabel}
                    <span className="ml-2 text-[13px] font-normal text-muted-foreground">
                        {inMonth === 0
                            ? 'No deadlines'
                            : `${inMonth} ${inMonth === 1 ? 'deadline' : 'deadlines'}`}
                    </span>
                </h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 rounded-full px-3 text-[12.5px]"
                    onClick={() =>
                        setCursor({
                            year: start.getUTCFullYear(),
                            month: start.getUTCMonth(),
                        })
                    }
                >
                    Today
                </Button>
                <div className="flex items-center">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 rounded-lg"
                        onClick={() => move(-1)}
                        aria-label="Previous month"
                    >
                        <CaretLeft className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8 rounded-lg"
                        onClick={() => move(1)}
                        aria-label="Next month"
                    >
                        <CaretRight className="size-4" />
                    </Button>
                </div>
            </div>
            <div className="grid grid-cols-7 border-b border-border bg-muted/40 text-center text-[11.5px] font-medium text-muted-foreground">
                {WEEKDAYS.map((day) => (
                    <div key={day} className="py-1.5">
                        <span className="sm:hidden">{day.slice(0, 1)}</span>
                        <span className="hidden sm:inline">{day}</span>
                    </div>
                ))}
            </div>
            <div className="grid grid-cols-7">
                {days.map(({ date, inMonth: current }, index) => {
                    const dayEvents = byDate.get(date) ?? [];
                    const isToday = date === today;
                    const isSelected = date === selected;
                    const past = date < today;

                    return (
                        <button
                            key={date}
                            type="button"
                            onClick={() => onSelect(date)}
                            aria-label={`${format(date, { weekday: 'long', month: 'long', day: 'numeric' })}: ${dayEvents.length === 0 ? 'no deadlines' : `${dayEvents.length} ${dayEvents.length === 1 ? 'deadline' : 'deadlines'}`}`}
                            aria-pressed={isSelected}
                            className={cn(
                                'relative flex min-h-14 flex-col items-stretch gap-1 border-border p-1 text-left transition-colors outline-none hover:bg-muted/60 focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring/50 sm:min-h-24 sm:p-1.5',
                                index % 7 !== 6 && 'border-r',
                                index < 35 && 'border-b',
                                !current && 'bg-muted/25',
                                isSelected && 'bg-muted',
                            )}
                        >
                            <span
                                className={cn(
                                    'inline-flex size-6 items-center justify-center self-center rounded-full text-[12px] tabular-nums sm:self-start',
                                    current
                                        ? 'text-foreground'
                                        : 'text-muted-foreground/60',
                                    past && current && 'text-muted-foreground',
                                    isToday &&
                                        'bg-foreground font-semibold text-background',
                                )}
                            >
                                {parse(date).getUTCDate()}
                            </span>
                            {/* Phones: a dot per deadline. Wider: the round and the college. */}
                            {dayEvents.length > 0 ? (
                                <span
                                    className="flex justify-center gap-0.5 sm:hidden"
                                    aria-hidden
                                >
                                    {dayEvents.slice(0, 3).map((event) => (
                                        <span
                                            key={event.id}
                                            className={cn(
                                                'size-1.5 rounded-full',
                                                KIND[event.kind].dot,
                                            )}
                                        />
                                    ))}
                                </span>
                            ) : null}
                            <span className="hidden min-w-0 flex-col gap-0.5 sm:flex">
                                {dayEvents.slice(0, 2).map((event) => (
                                    <span
                                        key={event.id}
                                        className={cn(
                                            'flex min-w-0 items-center gap-1 rounded-md px-1 py-0.5 text-[11px] leading-tight',
                                            KIND[event.kind].chip,
                                            past && 'opacity-60',
                                        )}
                                        title={`${event.college} · ${event.kind === 'step' ? event.label : KIND[event.kind].long}`}
                                    >
                                        <span className="font-semibold">
                                            {KIND[event.kind].short}
                                        </span>
                                        <span className="truncate">
                                            {event.college}
                                        </span>
                                    </span>
                                ))}
                                {dayEvents.length > 2 ? (
                                    <span className="px-1 text-[11px] text-muted-foreground">
                                        +{dayEvents.length - 2} more
                                    </span>
                                ) : null}
                            </span>
                        </button>
                    );
                })}
            </div>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-border px-4 py-2.5 text-[12px] text-muted-foreground">
                {(Object.keys(KIND) as Kind[]).map((kind) => (
                    <span
                        key={kind}
                        className="inline-flex items-center gap-1.5"
                    >
                        <span
                            className={cn(
                                'size-2 rounded-full',
                                KIND[kind].dot,
                            )}
                            aria-hidden
                        />
                        {kind === 'step'
                            ? 'From an application checklist'
                            : KIND[kind].long}
                    </span>
                ))}
            </div>
        </div>
    );
}

/** Edit one college's deadlines. A college always needs one: a date in some round, or rolling admission. */
function DeadlineEditor({
    college,
    onClose,
}: {
    college: College | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={college !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {college ? (
                    <DeadlineForm
                        key={`${college.id}:${college.revision}`}
                        college={college}
                        onClose={onClose}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DeadlineForm({
    college,
    onClose,
}: {
    college: College;
    onClose: () => void;
}) {
    const [values, setValues] = useState({
        ea: college.ea ?? '',
        ed: college.ed ?? '',
        rd: college.rd ?? '',
        rolling: college.rolling,
    });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const hasOne =
        values.rolling ||
        values.ea !== '' ||
        values.ed !== '' ||
        values.rd !== '';

    async function save(event: FormEvent) {
        event.preventDefault();

        if (!hasOne) {
            setError(
                'Add at least one deadline, or turn on rolling admission if the college decides as applications arrive.',
            );

            return;
        }

        setSaving(true);
        setError(null);

        try {
            const res = await fetch(`/college-lists/${college.id}`, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                body: JSON.stringify({
                    ea_deadline: values.ea || null,
                    ed_deadline: values.ed || null,
                    rd_deadline: values.rd || null,
                    rolling_admission: values.rolling,
                    expected_revision: college.revision,
                }),
            });

            if (!res.ok) {
                const body = (await res.json().catch(() => ({}))) as {
                    message?: string;
                };
                setError(
                    res.status === 409
                        ? 'This college changed since you opened it. Close and open it again to see the latest.'
                        : (body.message ??
                              'The deadlines were not saved. Try again.'),
                );

                return;
            }

            onClose();
            router.reload({ only: ['colleges', 'events'] });
        } catch {
            setError(
                'The deadlines were not saved. Check your connection and try again.',
            );
        } finally {
            setSaving(false);
        }
    }

    const field = (key: 'ea' | 'ed' | 'rd', kind: Kind) => (
        <div className="grid grid-cols-[1fr_auto] items-center gap-3">
            <Label
                htmlFor={`deadline-${key}`}
                className="flex items-center gap-2 text-[13.5px] font-medium"
            >
                <KindChip kind={kind} />
                {KIND[kind].long}
            </Label>
            <div className="flex items-center gap-1">
                <Input
                    id={`deadline-${key}`}
                    type="date"
                    value={values[key]}
                    onChange={(e) =>
                        setValues((v) => ({ ...v, [key]: e.target.value }))
                    }
                    className="h-9 w-[10.5rem]"
                />
                {values[key] !== '' ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-9 px-2 text-[12px] text-muted-foreground"
                        onClick={() => setValues((v) => ({ ...v, [key]: '' }))}
                    >
                        Clear
                    </Button>
                ) : null}
            </div>
        </div>
    );

    return (
        <form onSubmit={(e) => void save(e)}>
            <DialogHeader>
                <DialogTitle>Deadlines for {college.name}</DialogTitle>
                <DialogDescription>
                    Use the dates on the college&apos;s own admissions page.
                    Every college needs at least one — or rolling admission.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-5 space-y-3.5">
                {field('ea', 'ea')}
                {field('ed', 'ed')}
                {field('rd', 'rd')}
                <div className="flex items-start justify-between gap-4 rounded-xl bg-muted/60 px-3 py-2.5">
                    <div>
                        <Label
                            htmlFor="deadline-rolling"
                            className="text-[13.5px] font-medium"
                        >
                            Rolling admission
                        </Label>
                        <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                            Decisions as applications arrive — apply early.
                        </p>
                    </div>
                    <Switch
                        id="deadline-rolling"
                        checked={values.rolling}
                        onCheckedChange={(checked) =>
                            setValues((v) => ({
                                ...v,
                                rolling: checked === true,
                            }))
                        }
                    />
                </div>
            </div>
            {error ? (
                <p role="alert" className="error-pill mt-4">
                    <WarningCircle
                        weight="fill"
                        className="size-3.5 shrink-0"
                        aria-hidden
                    />
                    <span>{error}</span>
                </p>
            ) : null}
            <DialogFooter className="mt-6">
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={saving || !hasOne}>
                    {saving ? 'Saving…' : 'Save deadlines'}
                </Button>
            </DialogFooter>
        </form>
    );
}

function CollegeDeadlines({
    college,
    onEdit,
}: {
    college: College;
    onEdit: () => void;
}) {
    const rounds = (['ea', 'ed', 'rd'] as const).filter(
        (key) => college[key] !== null,
    );

    return (
        <li className="flex items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <Link
                    href={college.href}
                    className="truncate text-[14px] font-medium text-foreground hover:underline"
                >
                    {college.name}
                </Link>
                <p className="mt-1 flex flex-wrap items-center gap-1.5 text-[12.5px] text-muted-foreground">
                    {rounds.map((key) => (
                        <span
                            key={key}
                            className="inline-flex items-center gap-1"
                        >
                            <KindChip kind={key} />
                            {format(college[key] as string, {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                            })}
                        </span>
                    ))}
                    {college.rolling ? (
                        <span className="inline-flex items-center gap-1">
                            <span className="inline-flex h-5 items-center rounded-md bg-muted px-1.5 text-[11px] font-semibold text-foreground/80">
                                Rolling
                            </span>
                            apply early
                        </span>
                    ) : null}
                    {college.needsDeadline ? (
                        <span className="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-300">
                            <WarningCircle className="size-3.5" weight="fill" />
                            Needs a deadline
                        </span>
                    ) : null}
                </p>
            </div>
            <Button
                type="button"
                variant={college.needsDeadline ? 'default' : 'outline'}
                size="sm"
                className="h-8 shrink-0 rounded-full px-3 text-[12.5px]"
                onClick={onEdit}
            >
                {college.needsDeadline ? 'Add deadline' : 'Edit'}
            </Button>
        </li>
    );
}

function Card({ children }: { children: ReactNode }) {
    return (
        <ul className="divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
            {children}
        </ul>
    );
}

export default function Deadlines({ today, colleges, events }: Props) {
    const mentor = useOptionalMentor();
    const [view, setView] = useState<'calendar' | 'list'>('calendar');
    const [selected, setSelected] = useState<string | null>(null);
    const [editing, setEditing] = useState<College | null>(null);

    const needs = colleges.filter((c) => c.needsDeadline);
    const rolling = colleges.filter((c) => c.rolling);
    const upcoming = events.filter((e) => e.date >= today);
    const past = events.filter((e) => e.date < today);
    const collegeById = new Map(colleges.map((c) => [c.id, c]));
    const edit = (collegeId: string) =>
        setEditing(collegeById.get(collegeId) ?? null);
    const dayEvents = selected ? events.filter((e) => e.date === selected) : [];
    const next = upcoming[0];

    // Approving deadline changes in the mentor's chat updates the calendar in place (no page reload).
    useEffect(() => {
        const refresh = () => router.reload({ only: ['colleges', 'events'] });
        window.addEventListener('admitme:data-changed', refresh);

        return () =>
            window.removeEventListener('admitme:data-changed', refresh);
    }, []);

    // Proposing the dates needs Agent mode; the button is the student asking for exactly that.
    const askMentor = () => {
        if (!mentor) {
            return;
        }

        const names = new Intl.ListFormat('en', {
            style: 'long',
            type: 'conjunction',
        }).format(needs.map((c) => c.name));
        mentor.setMode('agent');
        mentor.compose(
            `Find the official deadlines (Early Action, Early Decision, Regular Decision, or rolling admission) for ${names}, and propose them for my list.`,
        );
    };

    // Upcoming deadlines grouped by month for the list view.
    const months = (() => {
        const groups = new Map<string, DeadlineEvent[]>();

        for (const event of upcoming) {
            const key = event.date.slice(0, 7);
            groups.set(key, [...(groups.get(key) ?? []), event]);
        }

        return [...groups.entries()];
    })();

    return (
        <>
            <Head title="Deadlines" />
            <Page width="wide">
                <PageHeader
                    title="Deadlines"
                    description={
                        next
                            ? `Next: ${next.college} ${next.kind === 'step' ? next.label : KIND[next.kind].long}, ${format(next.date, { month: 'long', day: 'numeric' })} (${relative(today, next.date).toLowerCase()}). Dates you or the mentor saved are unverified until you check the college's site.`
                            : 'Early Action, Early Decision, Regular Decision and rolling admission for every college on your list.'
                    }
                    actions={
                        <SegmentedTabs
                            label="Deadline view"
                            value={view}
                            onChange={setView}
                            items={[
                                {
                                    value: 'calendar',
                                    label: (
                                        <span className="inline-flex items-center gap-1.5">
                                            <CalendarBlank className="size-4" />
                                            Calendar
                                        </span>
                                    ),
                                },
                                {
                                    value: 'list',
                                    label: (
                                        <span className="inline-flex items-center gap-1.5">
                                            <ListBullets className="size-4" />
                                            List
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    }
                />

                {colleges.length === 0 ? (
                    <EmptyState
                        icon={<CalendarBlank className="size-6" />}
                        title="No colleges on your list yet"
                        description="Shortlist colleges and their deadlines will appear here."
                        action={
                            <Button asChild className="rounded-full">
                                <Link href="/college-lists">
                                    Open College lists
                                </Link>
                            </Button>
                        }
                    />
                ) : null}

                {needs.length > 0 ? (
                    <section
                        aria-labelledby="needs-deadline"
                        className="mb-8 overflow-hidden rounded-2xl border border-amber-500/30 bg-amber-500/[0.06]"
                    >
                        <div className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center">
                            <div className="flex min-w-0 flex-1 items-start gap-2.5">
                                <WarningCircle
                                    className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
                                    weight="fill"
                                />
                                <div>
                                    <h2
                                        id="needs-deadline"
                                        className="text-[14.5px] font-semibold text-foreground"
                                    >
                                        {needs.length}{' '}
                                        {needs.length === 1
                                            ? 'college needs a deadline'
                                            : 'colleges need a deadline'}
                                    </h2>
                                    <p className="mt-0.5 text-[13px] text-muted-foreground">
                                        Every college needs at least one: Early
                                        Action, Early Decision, Regular Decision
                                        — or rolling admission.
                                    </p>
                                </div>
                            </div>
                            {mentor ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="h-8 shrink-0 gap-1.5 rounded-full px-3 text-[12.5px]"
                                    onClick={askMentor}
                                >
                                    <Sparkle
                                        className="size-3.5"
                                        weight="fill"
                                    />
                                    Ask the mentor to find them
                                </Button>
                            ) : null}
                        </div>
                        <ul className="divide-y divide-amber-500/15 border-t border-amber-500/20 bg-background/60">
                            {needs.map((college) => (
                                <li
                                    key={college.id}
                                    className="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <Link
                                        href={college.href}
                                        className="min-w-0 flex-1 truncate text-[13.5px] font-medium text-foreground hover:underline"
                                    >
                                        {college.name}
                                    </Link>
                                    <Button
                                        type="button"
                                        size="sm"
                                        className="h-8 rounded-full px-3 text-[12.5px]"
                                        onClick={() => setEditing(college)}
                                    >
                                        Add deadline
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {colleges.length > 0 && view === 'calendar' ? (
                    <div className="mb-8 space-y-4">
                        <Calendar
                            today={today}
                            events={events}
                            selected={selected}
                            onSelect={(date) =>
                                setSelected(date === selected ? null : date)
                            }
                        />
                        {selected ? (
                            <Section
                                title={format(selected, {
                                    weekday: 'long',
                                    month: 'long',
                                    day: 'numeric',
                                    year: 'numeric',
                                })}
                                icon={CalendarBlank}
                            >
                                {dayEvents.length === 0 ? (
                                    <p className="rounded-2xl border border-dashed border-border px-4 py-5 text-center text-[13px] text-muted-foreground">
                                        No deadlines this day.
                                    </p>
                                ) : (
                                    <Card>
                                        {dayEvents.map((event) => (
                                            <DeadlineRow
                                                key={event.id}
                                                event={event}
                                                today={today}
                                                onEdit={() =>
                                                    edit(event.collegeId)
                                                }
                                            />
                                        ))}
                                    </Card>
                                )}
                            </Section>
                        ) : null}
                    </div>
                ) : null}

                {colleges.length > 0 && view === 'list' ? (
                    <div className="mb-8 space-y-6">
                        {months.length === 0 ? (
                            <p className="rounded-2xl border border-dashed border-border px-4 py-6 text-center text-[13.5px] text-muted-foreground">
                                No upcoming deadlines. Add them below, or ask
                                the mentor to find them.
                            </p>
                        ) : (
                            months.map(([key, monthEvents]) => (
                                <Section
                                    key={key}
                                    title={format(`${key}-01`, {
                                        month: 'long',
                                        year: 'numeric',
                                    })}
                                >
                                    <Card>
                                        {monthEvents.map((event) => (
                                            <DeadlineRow
                                                key={event.id}
                                                event={event}
                                                today={today}
                                                onEdit={() =>
                                                    edit(event.collegeId)
                                                }
                                            />
                                        ))}
                                    </Card>
                                </Section>
                            ))
                        )}
                        {past.length > 0 ? (
                            <details className="group">
                                <summary className="flex cursor-pointer list-none items-center gap-1.5 text-[13px] text-muted-foreground hover:text-foreground [&::-webkit-details-marker]:hidden">
                                    <CaretRight className="size-3.5 transition-transform group-open:rotate-90" />
                                    {past.length} past{' '}
                                    {past.length === 1
                                        ? 'deadline'
                                        : 'deadlines'}
                                </summary>
                                <div className="mt-3">
                                    <Card>
                                        {past.map((event) => (
                                            <DeadlineRow
                                                key={event.id}
                                                event={event}
                                                today={today}
                                                onEdit={() =>
                                                    edit(event.collegeId)
                                                }
                                            />
                                        ))}
                                    </Card>
                                </div>
                            </details>
                        ) : null}
                    </div>
                ) : null}

                {rolling.length > 0 ? (
                    <Section
                        title="Rolling admission"
                        icon={ArrowsClockwise}
                        description="No fixed deadline: these colleges decide as applications arrive, so earlier is better."
                        className="mb-8"
                    >
                        <Card>
                            {rolling.map((college) => (
                                <CollegeDeadlines
                                    key={college.id}
                                    college={college}
                                    onEdit={() => setEditing(college)}
                                />
                            ))}
                        </Card>
                    </Section>
                ) : null}

                {colleges.length > 0 ? (
                    <Section
                        title="All colleges"
                        icon={ListBullets}
                        description="The rounds saved for each college on your list."
                    >
                        <Card>
                            {colleges.map((college) => (
                                <CollegeDeadlines
                                    key={college.id}
                                    college={college}
                                    onEdit={() => setEditing(college)}
                                />
                            ))}
                        </Card>
                    </Section>
                ) : null}
            </Page>

            <DeadlineEditor
                college={editing}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

Deadlines.layout = {
    breadcrumbs: [{ title: 'Deadlines', href: '/deadlines' }],
};
