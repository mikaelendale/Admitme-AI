import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle,
    CircleNotch,
    Hourglass,
    Kanban,
    ListChecks,
    Sparkle,
    XCircle,
} from '@phosphor-icons/react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    fieldClass,
    formatMoment,
} from '@/components/admitme/application-parts';
import { TaskGoalCard } from '@/components/admitme/task-goal-card';
import type { TaskGoal } from '@/components/admitme/task-goal-card';
import {
    EmptyState,
    IconTile,
    ListCard,
    Page,
    PageHeader,
    Section,
    StatusBadge,
} from '@/components/page/kit';
import type { BadgeTone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import { useHydrated } from '@/hooks/use-hydrated';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';

type TaskRow = {
    id: number;
    title: string;
    type: string;
    status: 'pending' | 'running' | 'completed' | 'failed' | string;
    startedAt: string | null;
    completedAt: string | null;
    failedAt: string | null;
    error: string | null;
    href: string | null;
    /** What the task produced that still needs the student, e.g. suggestions awaiting review. */
    note?: string | null;
};

const JOB_STATUS: Record<string, { tone: BadgeTone; icon: ReactNode }> = {
    pending: {
        tone: 'neutral',
        icon: (
            <Hourglass
                weight="duotone"
                className="size-[22px] shrink-0 text-muted-foreground"
                aria-hidden
            />
        ),
    },
    running: {
        tone: 'info',
        icon: (
            <CircleNotch
                weight="bold"
                className="size-[22px] shrink-0 animate-spin text-info"
                aria-hidden
            />
        ),
    },
    completed: {
        tone: 'success',
        icon: (
            <CheckCircle
                weight="fill"
                className="size-[22px] shrink-0 text-success"
                aria-hidden
            />
        ),
    },
    failed: {
        tone: 'danger',
        icon: (
            <XCircle
                weight="fill"
                className="size-[22px] shrink-0 text-destructive"
                aria-hidden
            />
        ),
    },
};

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random()}`;
}

/**
 * Start the ready-made "affordable shortlist" plan. One key per submission, so a double click starts it once.
 */
function StartShortlistPlan() {
    const [criteria, setCriteria] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const key = useRef(newKey());

    const start = async () => {
        setBusy(true);
        setError(null);

        try {
            const response = await fetch(
                '/agent-tasks/playbooks/affordable-shortlist',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
                    body: JSON.stringify({
                        criteria: criteria.trim() || null,
                        idempotency_key: key.current,
                    }),
                },
            );

            if (!response.ok) {
                const body = (await response.json().catch(() => ({}))) as {
                    message?: string;
                };
                setError(body.message ?? 'Could not start the plan.');

                return;
            }

            key.current = newKey();
            setCriteria('');
            router.reload({ only: ['goals'] });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="rounded-2xl border border-border bg-card p-4 sm:p-5">
            <div className="flex items-start gap-4">
                <IconTile
                    icon={Sparkle}
                    tone="violet"
                    variant="gradient"
                    className="hidden sm:flex"
                />
                <div className="min-w-0 flex-1">
                    <p className="text-[15px] leading-snug font-semibold text-foreground">
                        Research affordable options and prepare a shortlist
                    </p>
                    <p className="mt-1 text-[13.5px] leading-relaxed text-muted-foreground">
                        Runs in the background: finds candidates, checks them
                        against your budget and the sources on file, then asks
                        you to approve a shortlist. Nothing is added to your
                        list without your approval.
                    </p>
                    <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                        <input
                            value={criteria}
                            onChange={(e) => setCriteria(e.target.value)}
                            placeholder="Optional: what to look for (e.g. economics, strong aid, US or UK)"
                            aria-label="What to look for"
                            className={fieldClass}
                        />
                        <Button
                            className="h-9 shrink-0 rounded-full px-5"
                            disabled={busy}
                            onClick={() => void start()}
                        >
                            {busy ? 'Starting…' : 'Start'}
                        </Button>
                    </div>
                    {error ? (
                        <p role="alert" className="error-pill mt-3">
                            {error}
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function JobRow({ task, hydrated }: { task: TaskRow; hydrated: boolean }) {
    const look = JOB_STATUS[task.status] ?? JOB_STATUS.pending;
    const started = hydrated ? formatMoment(task.startedAt) : null;
    const finished = hydrated
        ? formatMoment(task.completedAt ?? task.failedAt)
        : null;
    const body = (
        <>
            <span className="mt-px flex shrink-0">{look.icon}</span>
            <span className="min-w-0 flex-1">
                <span className="block text-[15px] leading-snug font-medium text-foreground">
                    {task.title}
                </span>
                <span className="nums mt-0.5 block min-h-5 text-[13px] text-muted-foreground">
                    {hydrated
                        ? started
                            ? `Started ${started}`
                            : 'Not started'
                        : null}
                    {finished ? ` · Finished ${finished}` : ''}
                </span>
                {task.error ? (
                    <span className="mt-1 block text-[13px] leading-snug text-destructive">
                        {task.error}
                    </span>
                ) : null}
                {task.note ? (
                    <span className="mt-1 block text-[13px] leading-snug font-medium text-foreground">
                        {task.note}
                    </span>
                ) : null}
            </span>
            <StatusBadge tone={look.tone} className="capitalize">
                {task.status}
            </StatusBadge>
        </>
    );
    const row = 'flex w-full items-start gap-4 px-5 py-4 text-left';

    return (
        <li>
            {task.href ? (
                <Link
                    href={task.href}
                    className={cn(row, 'transition-colors hover:bg-muted/60')}
                >
                    {body}
                </Link>
            ) : (
                <div className={row}>{body}</div>
            )}
        </li>
    );
}

export default function TasksPage({
    tasks,
    goals = [],
}: {
    tasks: TaskRow[];
    goals?: TaskGoal[];
}) {
    const hydrated = useHydrated();

    return (
        <>
            <Head title="Tasks" />

            <Page width="narrow">
                <PageHeader
                    title="Tasks"
                    description="Plans and background work AdmitMe runs for you, with their real status. A step only shows as done once the work actually finished, and anything that would change your saved data waits for your approval."
                />

                <Section>
                    <StartShortlistPlan />
                </Section>

                <Section
                    title={
                        goals.length > 0 ? `Plans · ${goals.length}` : 'Plans'
                    }
                    icon={ListChecks}
                >
                    {goals.length === 0 ? (
                        <p className="rounded-2xl border border-dashed border-border px-5 py-4 text-[13.5px] text-muted-foreground">
                            No plans yet. Start one above, or ask the mentor in
                            Agent mode.
                        </p>
                    ) : (
                        <div className="space-y-4">
                            {goals.map((goal) => (
                                <TaskGoalCard
                                    key={goal.id}
                                    goalId={goal.id}
                                    initial={goal}
                                />
                            ))}
                        </div>
                    )}
                </Section>

                <Section
                    title={
                        tasks.length > 0
                            ? `Background jobs · ${tasks.length}`
                            : 'Background jobs'
                    }
                    icon={Kanban}
                >
                    {tasks.length === 0 ? (
                        <EmptyState
                            icon={
                                <IconTile
                                    icon={Kanban}
                                    tone="slate"
                                    variant="glyph"
                                    size="lg"
                                />
                            }
                            title="No background jobs yet"
                            description="Jobs appear here when you build a college list or start an essay run."
                        />
                    ) : (
                        <ListCard>
                            {tasks.map((task) => (
                                <JobRow
                                    key={task.id}
                                    task={task}
                                    hydrated={hydrated}
                                />
                            ))}
                        </ListCard>
                    )}
                </Section>
            </Page>
        </>
    );
}
