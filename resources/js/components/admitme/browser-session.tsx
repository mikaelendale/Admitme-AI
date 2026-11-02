import { CheckCircle, Hourglass, WarningCircle } from '@phosphor-icons/react';
import { formatMoment } from '@/components/admitme/application-parts';
import { ProposalCard } from '@/components/admitme/proposal-card';
import type { ProposalData } from '@/components/admitme/proposal-card';
import { useHydrated } from '@/hooks/use-hydrated';
import { cn } from '@/lib/utils';

export type BrowserCommandData = {
    id: string;
    type: string;
    label: string | null;
    value: string | null;
    status:
        | 'pending'
        | 'leased'
        | 'completed'
        | 'failed'
        | 'frozen'
        | 'stale'
        | 'cancelled'
        | 'declined';
    status_reason: string | null;
    observed: string | null;
};

export type BrowserSessionData = {
    id: string;
    /** 'agent': the student gave the mentor control of the tab; 'fill': approved fills on one form. */
    mode?: 'fill' | 'agent';
    current_url?: string | null;
    current_title?: string | null;
    transport: string;
    simulated: boolean;
    origin: string;
    tab_id: number;
    application_id: string | null;
    status: 'active' | 'waiting_for_browser' | 'closed';
    last_seen_at: string | null;
    snapshot: {
        id: string;
        url: string;
        page_hash: string;
        fields: number;
        at: string | null;
    } | null;
    commands: BrowserCommandData[];
    proposals: ProposalData[];
};

const SESSION_STATUS: Record<
    BrowserSessionData['status'],
    { label: string; dot: string }
> = {
    active: { label: 'Extension connected', dot: 'bg-success' },
    waiting_for_browser: {
        label: 'Waiting for the browser (side panel closed or offline)',
        dot: 'bg-warning',
    },
    closed: { label: 'Session ended', dot: 'bg-muted-foreground/50' },
};

const AGENT_SESSION_STATUS: Record<
    BrowserSessionData['status'],
    { label: string; dot: string }
> = {
    active: { label: 'The mentor can use this tab', dot: 'bg-success' },
    waiting_for_browser: {
        label: 'Paused: the side panel is closed or offline',
        dot: 'bg-warning',
    },
    closed: { label: 'Control ended', dot: 'bg-muted-foreground/50' },
};

const COMMAND_STATUS: Record<BrowserCommandData['status'], string> = {
    pending: 'Waiting for the form tab',
    leased: 'Typing…',
    completed: 'Typed and checked',
    failed: 'Not done',
    frozen: 'Stopped: check the page',
    stale: 'Not done: the page changed',
    cancelled: 'Cancelled',
    declined: 'You declined',
};

/** The same steps when the mentor works in the tab itself (open, read, fill, click). */
const AGENT_COMMAND_STATUS: Record<BrowserCommandData['status'], string> = {
    pending: 'Waiting',
    leased: 'Working…',
    completed: 'Done',
    failed: 'Not done',
    frozen: 'Stopped: check the page',
    stale: 'Not done: the page changed',
    cancelled: 'Cancelled',
    declined: 'You declined',
};

function hostOf(url: string | null | undefined): string | null {
    try {
        return url ? new URL(url).host : null;
    } catch {
        return null;
    }
}

const PROBLEM: BrowserCommandData['status'][] = ['failed', 'frozen', 'stale'];

/**
 * One browser-extension session as the web app shows it: the page it observed, fills waiting for approval
 * (the same proposals the side panel shows) and every step with what the extension read back afterwards.
 */
export function BrowserSessionCard({
    session,
}: {
    session: BrowserSessionData;
}) {
    const hydrated = useHydrated();
    const agent = session.mode === 'agent';
    const status = agent
        ? (AGENT_SESSION_STATUS[session.status] ?? AGENT_SESSION_STATUS.closed)
        : (SESSION_STATUS[session.status] ?? SESSION_STATUS.closed);
    const commandStatus = agent ? AGENT_COMMAND_STATUS : COMMAND_STATUS;
    const observedAt =
        hydrated && session.snapshot?.at
            ? formatMoment(session.snapshot.at)
            : null;

    return (
        <div
            className="overflow-hidden rounded-2xl border border-border bg-card"
            data-browser-session={session.id}
        >
            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 px-5 py-4">
                <div className="min-w-0">
                    <p className="truncate text-[14.5px] font-medium text-foreground">
                        {agent
                            ? `Mentor control${hostOf(session.current_url) ? ` · ${hostOf(session.current_url)}` : ''}`
                            : session.origin}
                    </p>
                    <p className="mt-1 flex items-start gap-2 text-[13px] leading-snug text-muted-foreground">
                        <span
                            aria-hidden
                            className={cn(
                                'mt-[5px] size-2 shrink-0 rounded-full',
                                status.dot,
                            )}
                        />
                        {status.label}
                    </p>
                    {session.simulated ? (
                        <p className="mt-1 text-[12.5px] text-muted-foreground">
                            Simulated session (test transport): nothing is typed
                            into a real page.
                        </p>
                    ) : null}
                </div>
                <p className="max-w-full text-[12.5px] leading-relaxed text-muted-foreground sm:max-w-[55%] sm:text-right">
                    {agent ? (
                        session.current_url ? (
                            <>
                                {session.current_title
                                    ? `${session.current_title} · `
                                    : ''}
                                <span className="break-all">
                                    {session.current_url}
                                </span>
                            </>
                        ) : (
                            'No page opened yet.'
                        )
                    ) : session.snapshot ? (
                        <>
                            Last observed {session.snapshot.fields} field
                            {session.snapshot.fields === 1 ? '' : 's'} on{' '}
                            <span className="break-all">
                                {session.snapshot.url}
                            </span>
                            {observedAt ? ` · ${observedAt}` : ''}
                        </>
                    ) : (
                        'No page observed yet.'
                    )}
                </p>
            </div>

            {session.proposals.length > 0 ? (
                <div className="space-y-3 border-t border-border px-4 py-4 sm:px-5">
                    {session.proposals.map((proposal) => (
                        <ProposalCard
                            key={`${proposal.id}:${proposal.revision}:${proposal.status}`}
                            initial={proposal}
                            variant="inset"
                        />
                    ))}
                </div>
            ) : null}

            {session.commands.length > 0 ? (
                <ul className="divide-y divide-border border-t border-border text-[13px]">
                    {session.commands.map((command) => {
                        const problem = PROBLEM.includes(command.status);

                        return (
                            <li
                                key={command.id}
                                className="flex flex-wrap items-start justify-between gap-x-4 gap-y-0.5 px-5 py-2.5"
                                data-command-status={command.status}
                            >
                                <span className="min-w-0 break-words text-muted-foreground">
                                    {command.label ?? command.type}
                                    {command.value !== null ? (
                                        <>
                                            :{' '}
                                            <span className="font-medium text-foreground">
                                                {command.value}
                                            </span>
                                        </>
                                    ) : null}
                                </span>
                                <span
                                    className={cn(
                                        'flex shrink-0 items-center gap-1.5',
                                        problem
                                            ? 'text-destructive'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {command.status === 'completed' ? (
                                        <CheckCircle
                                            weight="fill"
                                            className="size-3.5 text-success"
                                            aria-hidden
                                        />
                                    ) : problem ? (
                                        <WarningCircle
                                            weight="fill"
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                    ) : (
                                        <Hourglass
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                    )}
                                    {commandStatus[command.status] ??
                                        command.status}
                                </span>
                                {command.status_reason ? (
                                    <span className="basis-full text-[12.5px] text-muted-foreground">
                                        {command.status_reason}
                                    </span>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </div>
    );
}
