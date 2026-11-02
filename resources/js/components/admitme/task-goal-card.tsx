import {
    CheckCircle,
    Circle,
    CircleNotch,
    Hourglass,
    PauseCircle,
    Prohibit,
    Sparkle,
    WarningCircle,
    XCircle,
} from '@phosphor-icons/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ProposalCard } from '@/components/admitme/proposal-card';
import { subagentLook } from '@/components/mentor/mentor-tools';
import { StatusBadge } from '@/components/page/kit';
import type { BadgeTone } from '@/components/page/kit';
import { Button } from '@/components/ui/button';
import {
    buildLaravelJsonHeaders,
    readCsrfTokenFromMeta,
} from '@/lib/laravelFetch';
import { cn } from '@/lib/utils';

type TaskStatus =
    | 'queued'
    | 'running'
    | 'awaiting_approval'
    | 'blocked'
    | 'paused'
    | 'completed'
    | 'failed'
    | 'cancelled';

/** Background specialists, named and marked the way the chat shows the mentor's sub-agents. */
const AGENT_SPECIALIST: Record<string, string> = {
    CollegeResearchAgent: 'college_scout',
    ResearcherAgent: 'researcher',
    ApplicationPlannerAgent: 'application_planner',
    WritingCoachAgent: 'writing_coach',
};

const AGENT_LABEL: Record<string, string> = {
    RequirementExtractorAgent: 'Requirements reader',
    DocumentFactExtractorAgent: 'Document reader',
};

/** Who worked on a step: one chip per specialist, with the model and outcome on hover. */
function SpecialistChips({ runs }: { runs: NonNullable<Step['runs']> }) {
    const byAgent = new Map<string, NonNullable<Step['runs']>[number]>();

    for (const run of runs) {
        byAgent.set(run.agent, run);
    }

    return (
        <ul aria-label="Specialists" className="mt-1.5 flex flex-wrap gap-1.5">
            {[...byAgent.values()].map((run) => {
                const look = subagentLook(AGENT_SPECIALIST[run.agent] ?? '');
                const label =
                    look?.label ??
                    AGENT_LABEL[run.agent] ??
                    run.agent
                        .replace(/Agent$/, '')
                        .replace(/([a-z])([A-Z])/g, '$1 $2');
                const AgentIcon = look?.icon ?? Sparkle;
                const failed = run.status === 'failed';

                return (
                    <li
                        key={run.agent}
                        title={`${label} · ${run.model ?? 'model'}${failed ? ' · failed' : ''}`}
                        className="inline-flex items-center gap-1.5 rounded-full border border-border bg-background py-0.5 pr-2 pl-0.5 text-[11.5px] text-muted-foreground"
                    >
                        <span
                            className={cn(
                                'flex size-4 items-center justify-center rounded-full',
                                look?.tile ?? 'bg-muted text-muted-foreground',
                            )}
                            aria-hidden
                        >
                            <AgentIcon className="size-2.5" weight="bold" />
                        </span>
                        {label}
                        {failed ? (
                            <span className="text-destructive">· failed</span>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

type Step = {
    id: string;
    key: string;
    type: string;
    title: string;
    status: TaskStatus;
    statusLabel: string;
    statusReason: string | null;
    summary: string | null;
    details: {
        suggested?: string[];
        proposed?: string[];
        excludedOverBudget?: string[];
        applied?: string[];
        changed?: string[];
        failed?: string[];
    };
    dependsOn: string[];
    attempts: number;
    maxAttempts: number;
    proposal: { id: string; status: string; title: string } | null;
    runs?: {
        agent: string;
        model: string | null;
        status: string;
        tokens: number | null;
    }[];
    events: { type: string; message: string; at: string | null }[];
};

export type TaskGoal = {
    id: string;
    title: string;
    status: TaskStatus;
    statusLabel: string;
    statusReason: string | null;
    createdBy: string;
    createdAt: string | null;
    active: boolean;
    controls: {
        canCancel: boolean;
        canPause: boolean;
        canResume: boolean;
        canRetry: boolean;
    };
    steps: Step[];
};

const TERMINAL: TaskStatus[] = ['completed', 'failed', 'cancelled'];

const GOAL_TONE: Record<TaskStatus, BadgeTone> = {
    queued: 'neutral',
    running: 'info',
    awaiting_approval: 'warning',
    blocked: 'warning',
    paused: 'neutral',
    completed: 'success',
    failed: 'danger',
    cancelled: 'neutral',
};

function StatusIcon({ status }: { status: TaskStatus }) {
    const className = 'size-[18px] shrink-0';

    switch (status) {
        case 'running':
            return (
                <CircleNotch
                    className={cn(className, 'animate-spin text-info')}
                    weight="bold"
                    aria-hidden
                />
            );
        case 'awaiting_approval':
            return (
                <Hourglass
                    className={cn(className, 'text-warning')}
                    weight="duotone"
                    aria-hidden
                />
            );
        case 'completed':
            return (
                <CheckCircle
                    className={cn(className, 'text-success')}
                    weight="fill"
                    aria-hidden
                />
            );
        case 'failed':
            return (
                <XCircle
                    className={cn(className, 'text-destructive')}
                    weight="fill"
                    aria-hidden
                />
            );
        case 'blocked':
            return (
                <WarningCircle
                    className={cn(className, 'text-warning')}
                    weight="fill"
                    aria-hidden
                />
            );
        case 'paused':
            return (
                <PauseCircle
                    className={cn(className, 'text-muted-foreground')}
                    aria-hidden
                />
            );
        case 'cancelled':
            return (
                <Prohibit
                    className={cn(className, 'text-muted-foreground')}
                    aria-hidden
                />
            );
        default:
            return (
                <Circle
                    className={cn(className, 'text-muted-foreground/60')}
                    aria-hidden
                />
            );
    }
}

function List({ label, items }: { label: string; items?: string[] }) {
    return items && items.length > 0 ? (
        <p className="mt-1 text-[12.5px] leading-snug text-muted-foreground">
            {label}: {items.join(', ')}
        </p>
    ) : null;
}

/**
 * A background plan and its steps, read from the stored task records (polled while work is moving), so it
 * survives refreshes and closed chats. Approvals happen inline; nothing is saved without them.
 */
export function TaskGoalCard({
    goalId,
    initial,
    onFinished,
}: {
    goalId: string;
    initial?: TaskGoal;
    onFinished?: (goal: TaskGoal) => void;
}) {
    const [goal, setGoal] = useState<TaskGoal | null>(initial ?? null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const reported = useRef(false);

    // Let the page refresh whatever the plan produced once it is finished (once per card).
    useEffect(() => {
        if (goal && TERMINAL.includes(goal.status) && !reported.current) {
            reported.current = true;
            onFinished?.(goal);
        }
    }, [goal, onFinished]);

    const load = useCallback(async () => {
        try {
            const response = await fetch(`/agent-tasks/${goalId}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (response.ok) {
                setGoal(((await response.json()) as { goal: TaskGoal }).goal);
                setError(null);
            } else if (response.status === 404) {
                setError('This task is not available.');
            }
        } catch {
            /* offline — try again on the next tick */
        }
    }, [goalId]);

    // Load at once when nothing is shown yet; then poll while steps can move on their own, more slowly while
    // waiting on the student (decisions may come from the Approvals page).
    useEffect(() => {
        if (goal && TERMINAL.includes(goal.status)) {
            return;
        }

        const timer = window.setTimeout(
            () => void load(),
            goal === null ? 0 : goal.active ? 2500 : 6000,
        );

        return () => window.clearTimeout(timer);
    }, [goal, load]);

    const act = async (action: 'cancel' | 'pause' | 'resume' | 'retry') => {
        setBusy(action);
        setError(null);

        try {
            const response = await fetch(`/agent-tasks/${goalId}/${action}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildLaravelJsonHeaders(readCsrfTokenFromMeta()),
            });
            const body = (await response.json().catch(() => ({}))) as {
                goal?: TaskGoal;
                message?: string;
            };

            if (response.ok && body.goal) {
                setGoal(body.goal);
            } else {
                setError(body.message ?? 'That did not work. Try again.');
            }
        } finally {
            setBusy(null);
            setConfirmCancel(false);
        }
    };

    if (!goal) {
        return (
            <p className="rounded-2xl border border-border bg-card px-4 py-3 text-[13px] text-muted-foreground not-first:mt-3">
                {error ?? 'Loading task…'}
            </p>
        );
    }

    const { controls } = goal;
    const hasControls =
        controls.canPause ||
        controls.canResume ||
        controls.canRetry ||
        controls.canCancel ||
        error !== null;
    const control = 'h-7 rounded-full px-3 text-[13px]';

    return (
        <section
            aria-label={`Task: ${goal.title}`}
            className="@container rounded-2xl border border-border bg-card text-left not-first:mt-3"
            data-task-goal={goal.id}
        >
            {/* Wide: title and status on one line. Narrow (chat, phones): the status drops beside the byline. */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 pt-4 pb-3 @md:px-5">
                <p className="order-1 w-full min-w-0 text-[14.5px] leading-snug font-semibold text-foreground @md:w-auto @md:flex-1">
                    {goal.title}
                </p>
                <span
                    data-testid="goal-status"
                    className="order-2 flex shrink-0"
                >
                    <StatusBadge tone={GOAL_TONE[goal.status] ?? 'neutral'}>
                        {goal.statusLabel}
                    </StatusBadge>
                </span>
                <p className="order-3 min-w-0 flex-1 basis-40 text-[12.5px] leading-snug text-muted-foreground @md:basis-full">
                    {goal.createdBy === 'mentor'
                        ? 'Started by your mentor'
                        : 'Started by you'}{' '}
                    · runs in the background — you can leave this page
                </p>
            </div>
            {goal.statusReason && goal.status !== 'awaiting_approval' ? (
                <p className="-mt-1 px-4 pb-3 text-[13px] leading-snug text-muted-foreground @md:px-5">
                    {goal.statusReason}
                </p>
            ) : null}

            <ol className="space-y-4 border-t border-border px-4 py-4 @md:px-5">
                {goal.steps.map((step) => (
                    <li
                        key={step.id}
                        className="flex gap-3"
                        data-step-key={step.key}
                        data-step-status={step.status}
                    >
                        <span className="mt-px">
                            <StatusIcon status={step.status} />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-[14px] leading-snug text-foreground">
                                <span className="font-medium">
                                    {step.title}
                                </span>
                                <span className="text-[12.5px] text-muted-foreground">
                                    {' · '}
                                    {step.statusLabel}
                                    {step.attempts > 1
                                        ? ` · attempt ${step.attempts} of ${step.maxAttempts}`
                                        : ''}
                                </span>
                            </p>
                            {step.status === 'queued' &&
                            step.dependsOn.length > 0 ? (
                                <p className="mt-0.5 text-[12.5px] text-muted-foreground">
                                    Starts after: {step.dependsOn.join(', ')}
                                </p>
                            ) : null}
                            {step.summary ? (
                                <p className="mt-1 text-[13px] leading-snug text-muted-foreground">
                                    {step.summary}
                                </p>
                            ) : step.statusReason ? (
                                <p className="mt-1 text-[13px] leading-snug text-muted-foreground">
                                    {step.statusReason}
                                </p>
                            ) : null}
                            {/* Suggested and approved names are already in the summary; these add what it leaves out. */}
                            <List
                                label="Left out (over budget)"
                                items={step.details.excludedOverBudget}
                            />
                            <List
                                label="Changed pages"
                                items={step.details.changed}
                            />
                            <List
                                label="Could not read"
                                items={step.details.failed}
                            />
                            {step.runs && step.runs.length > 0 ? (
                                <SpecialistChips runs={step.runs} />
                            ) : null}
                            {step.proposal &&
                            step.proposal.status === 'pending' ? (
                                <div className="mt-3">
                                    <ProposalCard
                                        proposalId={step.proposal.id}
                                        variant="inset"
                                    />
                                </div>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ol>

            {hasControls ? (
                <div className="flex flex-wrap items-center gap-1.5 border-t border-border px-4 py-3 @md:px-5">
                    {controls.canPause ? (
                        <Button
                            size="sm"
                            variant="outline"
                            className={control}
                            disabled={busy !== null}
                            onClick={() => void act('pause')}
                        >
                            Pause
                        </Button>
                    ) : null}
                    {controls.canResume ? (
                        <Button
                            size="sm"
                            variant="outline"
                            className={control}
                            disabled={busy !== null}
                            onClick={() => void act('resume')}
                        >
                            Resume
                        </Button>
                    ) : null}
                    {controls.canRetry ? (
                        <Button
                            size="sm"
                            variant="outline"
                            className={control}
                            disabled={busy !== null}
                            onClick={() => void act('retry')}
                        >
                            Retry stopped steps
                        </Button>
                    ) : null}
                    {controls.canCancel ? (
                        confirmCancel ? (
                            <span className="flex flex-wrap items-center gap-1.5">
                                <span className="text-[12.5px] text-muted-foreground">
                                    Stop the remaining steps? Finished steps
                                    keep their results.
                                </span>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    className={control}
                                    disabled={busy !== null}
                                    onClick={() => void act('cancel')}
                                >
                                    Yes, cancel
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className={control}
                                    onClick={() => setConfirmCancel(false)}
                                >
                                    Keep going
                                </Button>
                            </span>
                        ) : (
                            <Button
                                size="sm"
                                variant="ghost"
                                className={cn(control, 'text-muted-foreground')}
                                disabled={busy !== null}
                                onClick={() => setConfirmCancel(true)}
                            >
                                Cancel…
                            </Button>
                        )
                    ) : null}
                    {error ? (
                        <span
                            role="alert"
                            className="text-[12.5px] text-destructive"
                        >
                            {error}
                        </span>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
