/**
 * The AdmitMe side panel: link this browser, then either
 *  - let the mentor use this browser (shared/agent.ts): the mentor gets one tab; while the panel is open it
 *    polls AdmitMe for the mentor's next command and runs it in that tab — open, read, fill, click, press a
 *    key — one at a time, showing each in an activity list. Clicks on final controls (submit, pay, sign…)
 *    wait for the student's Allow; Stop ends control at once. Or
 *  - fill one form with approved values (shared/commands.ts): start a session on the form tab, observe the
 *    page, prepare and approve a fill, and run the approved steps. It never submits, pays or uploads.
 * State lives on the server, so closing and reopening the panel resumes.
 */
import { StrictMode, useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { createRoot } from 'react-dom/client';
import { COMMAND_CONTRACT_VERSION } from '../shared/commands';
import type {
    LeasedCommand,
    Observation,
    PerformResult,
} from '../shared/commands';
import { api, ApiError, currentTab, originOf } from './api';
import type { Link } from './api';
import { ControlView, firstEntry, startControl } from './control';
import type { Activity, AgentControl, StartedControl } from './control';
import './panel.css';

type ProposalItem = {
    key: string;
    label: string;
    before: string;
    after: string;
    reason: string | null;
};

type Proposal = {
    id: string;
    type: string;
    status: 'pending' | 'applied' | 'rejected' | 'expired' | 'stale' | 'failed';
    title: string;
    revision: number;
    payloadHash: string;
    items: ProposalItem[];
    decision: {
        decision: string;
        selectedKeys: string[];
        receipt: Record<string, string>;
    } | null;
};

type Command = {
    id: string;
    type: string;
    label: string | null;
    value: string | null;
    status: string;
    status_reason: string | null;
    observed: string | null;
};

type Session = {
    id: string;
    transport: string;
    simulated: boolean;
    origin: string;
    tab_id: number;
    application_id: string | null;
    status: string;
    snapshot: {
        id: string;
        url: string;
        page_hash: string;
        at: string | null;
    } | null;
    commands: Command[];
    proposals: Proposal[];
};

type Application = { id: string; college: string | null };

type Lease = { status: string; commands: LeasedCommand[]; message?: string };

const POLL_MS = 1500;

const PROPOSAL_STATUS: Record<Proposal['status'], string> = {
    pending: 'Waiting for your approval. Nothing has been typed.',
    applied: 'Approved and queued for this tab.',
    rejected: 'Rejected. Nothing will be typed.',
    expired: 'Expired. Nothing was typed.',
    stale: 'Not queued: the page changed after this was prepared. Observe the page again.',
    failed: 'Could not be queued. Nothing was typed.',
};

const COMMAND_STATUS: Record<string, string> = {
    pending: 'Waiting to type',
    leased: 'Typing…',
    completed: 'Typed and checked',
    failed: 'Not done',
    frozen: 'Stopped: check the page',
    stale: 'Not done: the page changed',
    cancelled: 'Cancelled',
};

async function observeTab(tabId: number): Promise<Observation> {
    try {
        const observation = (await chrome.tabs.sendMessage(tabId, {
            type: 'admitme.observe',
            version: COMMAND_CONTRACT_VERSION,
        })) as Observation | undefined;

        if (observation) {
            return observation;
        }
    } catch {
        /* no content script in this tab (opened before the extension was installed, or not a web page) */
    }

    throw new Error(
        'AdmitMe cannot see this page yet. Reload the form page, then try again.',
    );
}

async function performInTab(
    tabId: number,
    command: LeasedCommand,
): Promise<PerformResult> {
    try {
        const result = (await chrome.tabs.sendMessage(tabId, {
            type: 'admitme.perform',
            version: COMMAND_CONTRACT_VERSION,
            command,
        })) as PerformResult | undefined;

        return (
            result ?? {
                outcome: 'uncertain',
                observed_value: null,
                message: 'The page did not answer.',
            }
        );
    } catch {
        return {
            outcome: 'uncertain',
            observed_value: null,
            message: 'The page did not answer.',
        };
    }
}

/** The page's field structure (not its values): when it changes, the server must see a new observation. */
function structureOf(observation: Observation): string {
    return JSON.stringify(
        observation.fields.map((f) => [
            f.field_id,
            f.label,
            f.name,
            f.type,
            f.options,
        ]),
    );
}

async function acknowledge(
    link: Link,
    commandId: string,
    result: PerformResult,
): Promise<void> {
    for (let attempt = 1; attempt <= 3; attempt += 1) {
        try {
            await api(
                link.server,
                link.token,
                `/api/browser/commands/${commandId}/result`,
                {
                    method: 'POST',
                    body: {
                        outcome: result.outcome,
                        observed_value: result.observed_value,
                        message: result.message ?? null,
                    },
                },
            );

            return;
        } catch (e) {
            // Retry only what may be temporary; the server ignores repeated reports for a finished step.
            const temporary =
                e instanceof ApiError &&
                (e.status === 0 || e.status === 429 || e.status >= 500);

            if (!temporary || attempt === 3) {
                throw new Error(
                    'Could not confirm a step with AdmitMe. The field will be checked again before anything else is typed.',
                );
            }

            await new Promise((resolve) => setTimeout(resolve, 800 * attempt));
        }
    }
}

function App() {
    const [ready, setReady] = useState(false);
    const [link, setLink] = useState<Link | null>(null);
    const [sessionId, setSessionId] = useState<string | null>(null);
    const [control, setControl] = useState<AgentControl | null>(null);
    const [activity, setActivity] = useState<Activity[]>([]);
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        void chrome.storage.local
            .get(['link', 'sessionId', 'agentSession', 'agentActivity'])
            .then((stored) => {
                const agent = stored.agentSession as AgentControl | undefined;
                const saved = stored.agentActivity as
                    { session?: string; items?: Activity[] } | undefined;
                const resumed =
                    agent &&
                    typeof agent.id === 'string' &&
                    typeof agent.tabId === 'number'
                        ? agent
                        : null;

                setLink((stored.link as Link | undefined) ?? null);
                setSessionId((stored.sessionId as string | undefined) ?? null);
                setControl(resumed);
                setActivity(
                    resumed &&
                        saved?.session === resumed.id &&
                        Array.isArray(saved.items)
                        ? saved.items
                        : [],
                );
                setReady(true);
            });
    }, []);

    const forget = useCallback(async (message: string | null) => {
        await chrome.storage.local.remove([
            'link',
            'sessionId',
            'agentSession',
            'agentActivity',
        ]);
        setLink(null);
        setSessionId(null);
        setControl(null);
        setNotice(message);
    }, []);

    const saveControl = useCallback(
        async (next: AgentControl | null, first: Activity[] = []) => {
            if (next === null) {
                await chrome.storage.local.remove([
                    'agentSession',
                    'agentActivity',
                ]);
            } else {
                await chrome.storage.local.set({
                    agentSession: next,
                    agentActivity: { session: next.id, items: first },
                });
            }

            setActivity(first);
            setControl(next);
        },
        [],
    );

    const controlStarted = useCallback(
        async (started: StartedControl) => {
            setNotice(null);
            await saveControl(started.control, [firstEntry(started)]);
        },
        [saveControl],
    );

    const controlEnded = useCallback(
        (message: string | null) => {
            setNotice(message);
            void saveControl(null);
        },
        [saveControl],
    );

    const unauthorized = useCallback(() => {
        void forget(
            'This browser is no longer linked (the link expired or was revoked in AdmitMe). Link it again with a new code.',
        );
    }, [forget]);

    const saveSession = useCallback(async (id: string | null) => {
        if (id === null) {
            await chrome.storage.local.remove('sessionId');
        } else {
            await chrome.storage.local.set({ sessionId: id });
        }

        setSessionId(id);
    }, []);

    async function linked(next: Link) {
        await chrome.storage.local.set({ link: next });
        setNotice(null);
        setLink(next);
    }

    async function unlink() {
        for (const id of [sessionId, control?.id]) {
            if (link && id) {
                await api(
                    link.server,
                    link.token,
                    `/api/browser/sessions/${id}`,
                    { method: 'DELETE' },
                ).catch(() => undefined);
            }
        }

        await forget(
            'Unlinked here. To revoke this browser everywhere, open AdmitMe → Settings → Browser extension.',
        );
    }

    if (!ready) {
        return (
            <main className="panel">
                <p className="muted">Loading…</p>
            </main>
        );
    }

    return (
        <main className="panel">
            <header>
                <h1>AdmitMe assistant</h1>
                <p className="muted">
                    Your AdmitMe mentor works in one tab you choose, only while
                    this panel is open. It never reads or types passwords and
                    never picks files; anything final, like submitting or
                    paying, waits for your Allow.
                </p>
            </header>

            {notice ? (
                <p className="notice" data-testid="panel-notice">
                    {notice}
                </p>
            ) : null}

            {link === null ? (
                <LinkForm onLinked={linked} />
            ) : control !== null ? (
                <ControlView
                    key={control.id}
                    link={link}
                    control={control}
                    initialActivity={activity}
                    onMoved={controlStarted}
                    onEnded={controlEnded}
                    onUnauthorized={unauthorized}
                />
            ) : sessionId === null ? (
                <StartSession
                    link={link}
                    onStarted={saveSession}
                    onAgentStarted={controlStarted}
                    onUnauthorized={unauthorized}
                />
            ) : (
                <SessionView
                    link={link}
                    sessionId={sessionId}
                    onEnded={() => void saveSession(null)}
                    onUnauthorized={unauthorized}
                />
            )}

            {link ? (
                <footer className="muted">
                    Linked to {link.student || 'your'} AdmitMe account as “
                    {link.device}”.{' '}
                    <button
                        type="button"
                        className="text-button"
                        onClick={() => void unlink()}
                    >
                        Unlink this browser
                    </button>
                </footer>
            ) : null}
        </main>
    );
}

function LinkForm({ onLinked }: { onLinked: (link: Link) => Promise<void> }) {
    const [server, setServer] = useState('');
    const [code, setCode] = useState('');
    const [device, setDevice] = useState('Chrome');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const base = server.trim().replace(/\/+$/, '');
        const label = device.trim() || 'Browser';

        if (originOf(base) === null) {
            setError(
                'Enter the AdmitMe address, for example https://admitme.example.',
            );

            return;
        }

        setBusy(true);
        setError(null);

        try {
            const result = await api<{ token: string; student: string }>(
                base,
                null,
                '/api/browser/link',
                {
                    method: 'POST',
                    body: { code: code.trim(), device_label: label },
                },
            );
            await onLinked({
                server: base,
                token: result.token,
                student: result.student,
                device: label,
            });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Linking failed.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <form className="section" onSubmit={(event) => void submit(event)}>
            <h2>Link this browser</h2>
            <p className="muted">
                In AdmitMe, open Settings → Browser extension and create a link
                code. Codes work once, for 10 minutes.
            </p>
            <label className="field">
                AdmitMe address
                <input
                    name="server"
                    type="url"
                    required
                    placeholder="https://admitme.example"
                    value={server}
                    onChange={(e) => setServer(e.target.value)}
                />
            </label>
            <label className="field">
                Link code
                <input
                    name="code"
                    required
                    autoComplete="off"
                    placeholder="ABCD-EFGH"
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                />
            </label>
            <label className="field">
                Name this browser
                <input
                    name="device"
                    maxLength={120}
                    value={device}
                    onChange={(e) => setDevice(e.target.value)}
                />
            </label>
            <button type="submit" className="primary" disabled={busy}>
                {busy ? 'Linking…' : 'Link'}
            </button>
            {error ? (
                <p role="alert" className="error">
                    {error}
                </p>
            ) : null}
        </form>
    );
}

function StartSession({
    link,
    onStarted,
    onAgentStarted,
    onUnauthorized,
}: {
    link: Link;
    onStarted: (id: string) => Promise<void>;
    onAgentStarted: (started: StartedControl) => Promise<void>;
    onUnauthorized: () => void;
}) {
    const [applications, setApplications] = useState<Application[] | null>(
        null,
    );
    const [applicationId, setApplicationId] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [agentBusy, setAgentBusy] = useState(false);
    const [agentError, setAgentError] = useState<string | null>(null);

    async function startAgent() {
        setAgentBusy(true);
        setAgentError(null);

        try {
            await onAgentStarted(await startControl(link));
        } catch (e) {
            if (e instanceof ApiError && e.status === 401) {
                onUnauthorized();

                return;
            }

            setAgentError(
                e instanceof Error
                    ? e.message
                    : 'Could not start mentor control.',
            );
            setAgentBusy(false);
        }
    }

    useEffect(() => {
        let cancelled = false;

        api<{ applications: Application[] }>(
            link.server,
            link.token,
            '/api/browser/applications',
        )
            .then((data) => {
                if (!cancelled) {
                    setApplications(data.applications);
                    setApplicationId(
                        (current) =>
                            current || (data.applications[0]?.id ?? ''),
                    );
                }
            })
            .catch((e: unknown) => {
                if (cancelled) {
                    return;
                }

                if (e instanceof ApiError && e.status === 401) {
                    onUnauthorized();

                    return;
                }

                setError(
                    e instanceof Error
                        ? e.message
                        : 'Could not load your applications.',
                );
            });

        return () => {
            cancelled = true;
        };
    }, [link, onUnauthorized]);

    async function start() {
        setBusy(true);
        setError(null);

        try {
            const tab = await currentTab();
            const origin = originOf(tab.url);

            if (tab.id === undefined || origin === null) {
                throw new Error(
                    'Open the application form (a normal web page) in this tab first.',
                );
            }

            const data = await api<{ session: Session }>(
                link.server,
                link.token,
                '/api/browser/sessions',
                {
                    method: 'POST',
                    body: {
                        origin,
                        tab_id: tab.id,
                        application_id: applicationId || null,
                    },
                },
            );
            await onStarted(data.session.id);
        } catch (e) {
            if (e instanceof ApiError && e.status === 401) {
                onUnauthorized();

                return;
            }

            setError(e instanceof Error ? e.message : 'Could not start.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <>
            <section className="section mentor-start">
                <button
                    type="button"
                    className="primary wide"
                    data-testid="start-agent"
                    disabled={agentBusy}
                    onClick={() => void startAgent()}
                >
                    {agentBusy
                        ? 'Starting…'
                        : 'Let the mentor use this browser'}
                </button>
                <p className="muted">
                    The mentor gets this tab to open, read and fill pages while
                    this panel is open. You log in yourself and can stop it at
                    any time.
                </p>
                {agentError ? (
                    <p role="alert" className="error">
                        {agentError}
                    </p>
                ) : null}
            </section>

            <p className="divider">or fill one form with details you approve</p>

            <FormSessionStart
                applications={applications}
                applicationId={applicationId}
                setApplicationId={setApplicationId}
                busy={busy}
                error={error}
                onStart={() => void start()}
            />
        </>
    );
}

function FormSessionStart({
    applications,
    applicationId,
    setApplicationId,
    busy,
    error,
    onStart,
}: {
    applications: Application[] | null;
    applicationId: string;
    setApplicationId: (id: string) => void;
    busy: boolean;
    error: string | null;
    onStart: () => void;
}) {
    return (
        <section className="section">
            <h2>Start on the form tab</h2>
            <p className="muted">
                Open the college's application form in this window, choose which
                application it is, then press “Use this tab”.
            </p>
            {applications === null && error === null ? (
                <p className="muted">Loading your applications…</p>
            ) : null}
            {applications !== null && applications.length === 0 ? (
                <p className="muted">
                    You have no applications in AdmitMe yet. Start one from your
                    college list first.
                </p>
            ) : null}
            {applications !== null && applications.length > 0 ? (
                <label className="field">
                    Application
                    <select
                        name="application"
                        value={applicationId}
                        onChange={(e) => setApplicationId(e.target.value)}
                    >
                        {applications.map((application) => (
                            <option key={application.id} value={application.id}>
                                {application.college ?? 'Application'}
                            </option>
                        ))}
                    </select>
                </label>
            ) : null}
            <button
                type="button"
                className="secondary"
                disabled={busy || applicationId === ''}
                onClick={onStart}
            >
                Use this tab
            </button>
            {error ? (
                <p role="alert" className="error">
                    {error}
                </p>
            ) : null}
        </section>
    );
}

function SessionView({
    link,
    sessionId,
    onEnded,
    onUnauthorized,
}: {
    link: Link;
    sessionId: string;
    onEnded: () => void;
    onUnauthorized: () => void;
}) {
    const [session, setSession] = useState<Session | null>(null);
    const [waiting, setWaiting] = useState<string | null>(null);
    const [info, setInfo] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const pageHash = useRef<string | null>(null);
    const structure = useRef<string | null>(null);
    const running = useRef(false);

    const handleError = useCallback(
        (e: unknown) => {
            if (e instanceof ApiError && e.status === 401) {
                onUnauthorized();

                return;
            }

            if (e instanceof ApiError && e.status === 404) {
                onEnded();

                return;
            }

            setError(e instanceof Error ? e.message : 'Something went wrong.');
        },
        [onEnded, onUnauthorized],
    );

    const load = useCallback(async () => {
        const data = await api<{ session: Session }>(
            link.server,
            link.token,
            `/api/browser/sessions/${sessionId}`,
        );
        setSession(data.session);

        return data.session;
    }, [link, sessionId]);

    const record = useCallback(
        async (current: Session, observation: Observation) => {
            const data = await api<{
                snapshot: { page_hash: string; fields: number };
                session: Session;
            }>(
                link.server,
                link.token,
                `/api/browser/sessions/${current.id}/snapshots`,
                {
                    method: 'POST',
                    body: { url: observation.url, fields: observation.fields },
                },
            );
            pageHash.current = data.snapshot.page_hash;
            structure.current = structureOf(observation);
            setSession(data.session);

            return data.snapshot;
        },
        [link],
    );

    /** One pass of the loop: refresh state; if approved steps wait and the form tab is in front, run them. */
    const tick = useCallback(async () => {
        if (running.current) {
            return;
        }

        running.current = true;

        try {
            const current = await load();

            if (current.status === 'closed') {
                setWaiting('This session has ended.');

                return;
            }

            if (
                !current.commands.some(
                    (c) => c.status === 'pending' || c.status === 'leased',
                )
            ) {
                setWaiting(null);

                return;
            }

            const tab = await chrome.tabs.get(current.tab_id).catch(() => null);

            if (tab === null) {
                setWaiting(
                    'The form tab for this session was closed. End this session and start again on the form.',
                );

                return;
            }

            if (!tab.active) {
                setWaiting(
                    'Approved steps are waiting. Switch to the form tab: AdmitMe only types into the page you are looking at.',
                );

                return;
            }

            const origin = originOf(tab.url) ?? 'about:blank';

            if (origin === current.origin) {
                const observation = await observeTab(current.tab_id);

                // A changed page is reported first, so steps approved for the old page are not run on the new one.
                if (
                    pageHash.current === null ||
                    structureOf(observation) !== structure.current
                ) {
                    await record(current, observation);
                }
            }

            // The server decides whether this tab, origin and page may run the steps.
            const query = new URLSearchParams({
                tab_id: String(current.tab_id),
                origin,
                page_hash: pageHash.current ?? '0'.repeat(64),
            });
            const lease = await api<Lease>(
                link.server,
                link.token,
                `/api/browser/sessions/${current.id}/commands?${query.toString()}`,
                { okStatuses: [409] },
            );

            if (lease.status !== 'ok') {
                setWaiting(lease.message ?? 'Waiting for the right page.');

                return;
            }

            for (const command of lease.commands) {
                await acknowledge(
                    link,
                    command.id,
                    await performInTab(current.tab_id, command),
                );
            }

            setWaiting(null);

            if (lease.commands.length > 0) {
                await load();
            }
        } catch (e) {
            handleError(e);
        } finally {
            running.current = false;
        }
    }, [handleError, link, load, record]);

    useEffect(() => {
        const first = setTimeout(() => void tick(), 0);
        const timer = setInterval(() => void tick(), POLL_MS);

        return () => {
            clearTimeout(first);
            clearInterval(timer);
        };
    }, [tick]);

    async function run(action: () => Promise<void>) {
        setBusy(true);
        setError(null);
        setInfo(null);

        try {
            await action();
        } catch (e) {
            handleError(e);
        } finally {
            setBusy(false);
        }
    }

    const observe = () =>
        run(async () => {
            const current = session ?? (await load());
            const tab = await chrome.tabs.get(current.tab_id).catch(() => null);

            if (tab === null) {
                throw new Error(
                    'The form tab for this session was closed. End this session and start again on the form.',
                );
            }

            if (originOf(tab.url) !== current.origin) {
                throw new Error(
                    `This session is for ${current.origin}. Go back to that page, or end this session and start again.`,
                );
            }

            const snapshot = await record(
                current,
                await observeTab(current.tab_id),
            );
            setInfo(
                `Saw ${snapshot.fields} form field(s). Passwords, hidden fields and file uploads are never read.`,
            );
        });

    const prepare = () =>
        run(async () => {
            await api(
                link.server,
                link.token,
                `/api/browser/sessions/${sessionId}/fill-proposal`,
                { method: 'POST' },
            );
            await load();
        });

    const decide = (
        proposal: Proposal,
        decision: 'approve' | 'reject',
        selectedKeys: string[],
    ) =>
        run(async () => {
            await api(
                link.server,
                link.token,
                `/api/browser/approvals/${proposal.id}/decision`,
                {
                    method: 'POST',
                    body: {
                        decision,
                        selected_keys: selectedKeys,
                        proposal_revision: proposal.revision,
                        payload_hash: proposal.payloadHash,
                        idempotency_key: `${proposal.id}:${proposal.revision}:${decision}`,
                    },
                },
            );
            await load();
        });

    const end = () =>
        run(async () => {
            await api(
                link.server,
                link.token,
                `/api/browser/sessions/${sessionId}`,
                { method: 'DELETE' },
            ).catch(() => undefined);
            onEnded();
        });

    if (session === null) {
        return (
            <section className="section">
                <p className="muted">Connecting to AdmitMe…</p>
                {error ? (
                    <p role="alert" className="error">
                        {error}
                    </p>
                ) : null}
            </section>
        );
    }

    const closed = session.status === 'closed';

    return (
        <section className="section" data-session={session.id}>
            <h2>Session on {session.origin}</h2>
            <p className="muted">
                Tab {session.tab_id} ·{' '}
                {closed
                    ? 'Ended'
                    : session.status === 'waiting_for_browser'
                      ? 'Reconnecting'
                      : 'Active'}
            </p>
            {session.simulated ? (
                <p className="notice">
                    Simulated session (test transport): nothing is typed into a
                    real page.
                </p>
            ) : null}

            {!closed ? (
                <div className="row">
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void observe()}
                    >
                        Observe this page
                    </button>
                    <button
                        type="button"
                        className="primary"
                        disabled={busy || session.snapshot === null}
                        onClick={() => void prepare()}
                    >
                        Prepare fill
                    </button>
                </div>
            ) : null}

            {waiting ? (
                <p className="notice" data-testid="waiting">
                    {waiting}
                </p>
            ) : null}
            {info ? <p className="muted">{info}</p> : null}
            {error ? (
                <p role="alert" className="error">
                    {error}
                </p>
            ) : null}

            {session.proposals.map((proposal) => (
                <ProposalBox
                    key={`${proposal.id}:${proposal.revision}:${proposal.status}`}
                    proposal={proposal}
                    busy={busy}
                    onDecide={decide}
                />
            ))}

            {session.commands.length > 0 ? (
                <div className="card">
                    <p className="title">Steps</p>
                    <ul className="steps">
                        {session.commands.map((command) => (
                            <li key={command.id} data-status={command.status}>
                                <span>
                                    {command.label ?? command.type}:{' '}
                                    <strong>{command.value}</strong>
                                </span>
                                <span className={`badge ${command.status}`}>
                                    {COMMAND_STATUS[command.status] ??
                                        command.status}
                                </span>
                                {command.status_reason ? (
                                    <small>{command.status_reason}</small>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <p className="muted">
                When the fields are filled, review the whole form yourself.
                Submitting, paying and uploading documents stay with you.
            </p>

            <button
                type="button"
                className="text-button"
                disabled={busy}
                onClick={() => void end()}
            >
                {closed ? 'Start a new session' : 'End this session'}
            </button>
        </section>
    );
}

function ProposalBox({
    proposal,
    busy,
    onDecide,
}: {
    proposal: Proposal;
    busy: boolean;
    onDecide: (
        proposal: Proposal,
        decision: 'approve' | 'reject',
        keys: string[],
    ) => void;
}) {
    const [selected, setSelected] = useState<Set<string>>(
        () => new Set(proposal.items.map((item) => item.key)),
    );
    const pending = proposal.status === 'pending';

    function toggle(key: string, on: boolean) {
        setSelected((previous) => {
            const next = new Set(previous);

            if (on) {
                next.add(key);
            } else {
                next.delete(key);
            }

            return next;
        });
    }

    return (
        <div
            className="card"
            data-proposal={proposal.id}
            data-status={proposal.status}
        >
            <p className="title">{proposal.title}</p>
            <p className={`status ${proposal.status}`}>
                {PROPOSAL_STATUS[proposal.status]}
            </p>
            <ul className="items">
                {proposal.items.map((item) => (
                    <li key={item.key}>
                        {pending ? (
                            <input
                                type="checkbox"
                                aria-label={`Include ${item.label}`}
                                checked={selected.has(item.key)}
                                onChange={(e) =>
                                    toggle(item.key, e.target.checked)
                                }
                            />
                        ) : null}
                        <div>
                            <strong>{item.label}</strong>
                            <div>
                                {item.before !== '—' ? (
                                    <s>{item.before}</s>
                                ) : null}
                                {item.before !== '—' ? ' → ' : null}
                                {item.after}
                            </div>
                            {item.reason ? <small>{item.reason}</small> : null}
                            {proposal.decision?.receipt[item.key] ===
                            'applied' ? (
                                <small>Queued for this tab</small>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ul>
            {pending ? (
                <div className="row">
                    <button
                        type="button"
                        className="primary"
                        disabled={busy || selected.size === 0}
                        onClick={() =>
                            onDecide(proposal, 'approve', Array.from(selected))
                        }
                    >
                        {selected.size === proposal.items.length
                            ? 'Approve'
                            : `Approve ${selected.size} selected`}
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => onDecide(proposal, 'reject', [])}
                    >
                        Reject
                    </button>
                </div>
            ) : null}
        </div>
    );
}

createRoot(document.getElementById('root') as HTMLElement).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
