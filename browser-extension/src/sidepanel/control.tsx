/**
 * Mentor browser control in the side panel (contract: shared/agent.ts). The student gives the mentor one tab;
 * while the panel is open it polls AdmitMe for the mentor's next command (the poll is also the heartbeat),
 * runs it in that tab — open, read, fill, click, press a key — strictly one at a time, reports the result and
 * shows each action in an activity list. Clicks on final controls (submit, pay, sign…) wait for the student's
 * Allow; Stop ends control at once.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { AGENT_CONTRACT_VERSION, AGENT_POLL_MS } from '../shared/agent';
import type {
    AgentCommand,
    AgentContentMessage,
    AgentKey,
    AgentResult,
    AgentResultData,
    AgentSession,
    ClickAnswer,
    FillAnswer,
    KeyAnswer,
    PageModel,
} from '../shared/agent';
import {
    api,
    ApiError,
    apiStatus,
    currentTab,
    originOf,
    PINNED_TAB,
} from './api';
import type { Link } from './api';

/** Mentor control of one tab, kept in storage so a reopened panel resumes it. */
export type AgentControl = { id: string; tabId: number };

type Tone = 'info' | 'ok' | 'warn' | 'error';

export type Activity = { id: number; at: number; text: string; tone: Tone };

/** GET …/agent-sessions/{id}/next: 200 { status: 'ok', command, session }, 409 wrong_tab, 410 closed. */
type NextAnswer = {
    status?: string;
    command?: AgentCommand | null;
    session?: AgentSession;
    message?: string;
};

/** How a consequential click's Allow card ended. */
type Decision = 'allow' | 'deny' | 'timeout' | 'stopped' | 'ended';

type Confirmation = { label: string; host: string; deadline: number };

/** The student has 3 minutes to Allow a final click; after that it is declined. */
const CONFIRM_MS = 3 * 60 * 1000;
/** While the Allow card is up, the poll keeps going as a heartbeat (the server hands out nothing meanwhile). */
const HEARTBEAT_MS = 10000;
const LOAD_TIMEOUT_MS = 20000;
const SETTLE_AFTER_LOAD_MS = 600;
const SETTLE_AFTER_CLICK_MS = 700;
const SETTLE_AFTER_KEY_MS = 500;
const ACTIVITY_LIMIT = 30;
const TAB_CLOSED = 'The mentor’s tab was closed.';

const KEY_NAMES: Record<AgentKey, string> = {
    Enter: 'Enter',
    Tab: 'Tab',
    Escape: 'Escape',
    ArrowDown: 'Arrow Down',
    ArrowUp: 'Arrow Up',
    Space: 'Space',
    Backspace: 'Backspace',
};

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function clipText(text: string, max = 60): string {
    const flat = text.replace(/\s+/g, ' ').trim();

    return flat.length > max ? flat.slice(0, max - 1).trimEnd() + '…' : flat;
}

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

/** "lakeside.edu" for the panel's header and the Allow card. */
function hostOf(url: string | undefined): string {
    if (url === undefined) {
        return 'this tab';
    }

    if (originOf(url) === null) {
        return url === '' ||
            url === 'about:blank' ||
            url.startsWith('chrome://newtab')
            ? 'a new tab'
            : 'a browser page';
    }

    return new URL(url).host.replace(/^www\./, '');
}

/** "lakeside.edu/apply" for the activity list. */
function shortUrl(url: string | undefined): string {
    if (originOf(url) === null) {
        return hostOf(url);
    }

    const parsed = new URL(url as string);
    const path = parsed.pathname.replace(/\/+$/, '');

    return clipText(parsed.host.replace(/^www\./, '') + path, 48);
}

function sameAddress(a: string | undefined, b: string): boolean {
    try {
        return new URL(a ?? '').href === new URL(b).href;
    } catch {
        return false;
    }
}

function formatTime(at: number): string {
    return new Date(at).toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
    });
}

class TabClosedError extends Error {
    constructor() {
        super(TAB_CLOSED);
    }
}

function tabOrNull(tabId: number): Promise<chrome.tabs.Tab | null> {
    return chrome.tabs.get(tabId).catch(() => null);
}

/** The tab the mentor gets: the current one, or a new tab beside it when the current one is AdmitMe itself. */
async function tabForMentor(server: string): Promise<chrome.tabs.Tab> {
    const tab = await currentTab();

    if (
        PINNED_TAB === null &&
        originOf(tab.url) !== null &&
        originOf(tab.url) === originOf(server)
    ) {
        return chrome.tabs.create({
            url: 'about:blank',
            active: false,
            index: (tab.index ?? 0) + 1,
        });
    }

    return tab;
}

export type StartedControl = { control: AgentControl; url: string | undefined };

/** POST /api/browser/agent-sessions for the mentor's tab. */
export async function startControl(link: Link): Promise<StartedControl> {
    const tab = await tabForMentor(link.server);

    if (tab.id === undefined) {
        throw new Error('Open a tab for the mentor first.');
    }

    const data = await api<{ session: AgentSession }>(
        link.server,
        link.token,
        '/api/browser/agent-sessions',
        {
            method: 'POST',
            body: {
                tab_id: tab.id,
                ...(originOf(tab.url) !== null ? { url: tab.url } : {}),
            },
        },
    ).catch((e: unknown) => {
        // An AdmitMe server from before mentor control has no such endpoint.
        if (e instanceof ApiError && (e.status === 404 || e.status === 405)) {
            throw new Error(
                'This AdmitMe server does not offer mentor control yet. Update AdmitMe, then try again.',
            );
        }

        throw e;
    });

    return {
        control: { id: data.session.id, tabId: data.session.tab_id ?? tab.id },
        url: tab.url,
    };
}

export function firstEntry(started: StartedControl): Activity {
    return {
        id: 1,
        at: Date.now(),
        text:
            originOf(started.url) !== null
                ? `Control started on ${shortUrl(started.url)}`
                : 'Control started in a new tab',
        tone: 'info',
    };
}

function cannotWorkHere(url: string | undefined): string {
    if (!url || url === 'about:blank' || url.startsWith('chrome://newtab')) {
        return 'This tab has no page yet: open one first.';
    }

    if (url.startsWith('http:')) {
        return 'AdmitMe works on https pages (and local test sites), not on this http page.';
    }

    return url.startsWith('https:')
        ? 'AdmitMe cannot work on this page (it may be a PDF or a protected browser page).'
        : 'AdmitMe cannot work on browser, extension or file pages.';
}

function noReceiver(error: unknown): boolean {
    return (
        error instanceof Error &&
        /Receiving end does not exist|Could not establish connection/i.test(
            error.message,
        )
    );
}

/**
 * Sends a message to the content script in the mentor's tab. A tab opened before the extension was installed
 * (or reloaded) has none: then content.js is injected and the message sent once more. An action is re-sent only
 * when it surely never reached the page (page actions are not idempotent); a read is always safe to repeat.
 */
async function toPage<T>(
    tabId: number,
    message: AgentContentMessage,
): Promise<T> {
    const repeatable = message.type === 'admitme.agent.read';
    let reached = false;

    try {
        const answer: unknown = await chrome.tabs.sendMessage(tabId, message);

        if (answer !== undefined && answer !== null) {
            return answer as T;
        }

        reached = true;
    } catch (error) {
        reached = !noReceiver(error);
    }

    const tab = await tabOrNull(tabId);

    if (tab === null) {
        throw new TabClosedError();
    }

    if (reached && !repeatable) {
        throw new Error(
            'The page did not answer (it may have reloaded), so the result is unknown. Read the page to check.',
        );
    }

    try {
        await chrome.scripting.executeScript({
            target: { tabId },
            files: ['content.js'],
        });
    } catch {
        throw new Error(cannotWorkHere(tab.url));
    }

    const answer: unknown = await chrome.tabs
        .sendMessage(tabId, message)
        .catch(() => undefined);

    if (answer === undefined || answer === null) {
        throw new Error('The page did not answer. Try again in a moment.');
    }

    return answer as T;
}

/** Counts the tab's page loads while an action runs (an opened address, a click or key that navigates). */
function watchLoads(tabId: number): {
    readonly count: number;
    stop: () => void;
} {
    let count = 0;
    const listener: chrome.tabs.TabUpdatedListener = (id, change) => {
        if (id === tabId && change.status === 'loading') {
            count += 1;
        }
    };
    chrome.tabs.onUpdated.addListener(listener);

    return {
        get count() {
            return count;
        },
        stop: () => chrome.tabs.onUpdated.removeListener(listener),
    };
}

async function waitForLoaded(tabId: number): Promise<chrome.tabs.Tab> {
    const deadline = Date.now() + LOAD_TIMEOUT_MS;

    for (;;) {
        const tab = await tabOrNull(tabId);

        if (tab === null) {
            throw new TabClosedError();
        }

        if (tab.status !== 'loading' || Date.now() >= deadline) {
            return tab;
        }

        await sleep(250);
    }
}

/** What a command run needs from the panel. */
type Runner = {
    tabId: number;
    log: (text: string, tone?: Tone) => void;
    /** Shows what a fresh read says (login or CAPTCHA waiting) and remembers element labels. */
    saw: (page: PageModel) => void;
    label: (id: string) => string;
    confirm: (label: string, url: string | undefined) => Promise<Decision>;
    tabClosed: () => void;
};

function done(data: AgentResultData): AgentResult {
    return { outcome: 'done', data };
}

function failed(message: string): AgentResult {
    return { outcome: 'failed', message };
}

function fieldCount(page: PageModel): number {
    return page.elements.filter(
        (element) => element.kind !== 'button' && element.kind !== 'link',
    ).length;
}

async function readPage(run: Runner, text: boolean): Promise<PageModel> {
    const answer = await toPage<PageModel | { error?: string }>(run.tabId, {
        type: 'admitme.agent.read',
        version: AGENT_CONTRACT_VERSION,
        text,
    });

    if (!('elements' in answer) || !Array.isArray(answer.elements)) {
        throw new Error(
            ('error' in answer ? answer.error : undefined) ??
                'The page could not be read.',
        );
    }

    return answer;
}

/** After a click or key: let the page react, wait out a page load it started, then read the page. */
async function settleAndRead(
    run: Runner,
    settleMs: number,
): Promise<PageModel> {
    await sleep(settleMs);
    const tab = await tabOrNull(run.tabId);

    if (tab === null) {
        throw new TabClosedError();
    }

    if (tab.status === 'loading') {
        await waitForLoaded(run.tabId);
        await sleep(SETTLE_AFTER_LOAD_MS);
    }

    return readPage(run, true);
}

async function openAddress(run: Runner, url: unknown): Promise<AgentResult> {
    if (typeof url !== 'string' || originOf(url) === null) {
        run.log(
            `Didn’t open ${typeof url === 'string' ? clipText(url, 48) : 'that'}: only web addresses (http or https)`,
            'warn',
        );

        return failed('Only http and https addresses can be opened.');
    }

    const loads = watchLoads(run.tabId);

    try {
        await chrome.tabs.update(run.tabId, { url });

        for (
            let waited = 0;
            loads.count === 0 && waited < 3000;
            waited += 100
        ) {
            await sleep(100);
        }

        const tab = await waitForLoaded(run.tabId);

        if (loads.count === 0 && !sameAddress(tab.url, url)) {
            run.log(
                `Couldn’t open ${shortUrl(url)}: the tab stayed where it was`,
                'warn',
            );

            return failed(
                'The tab did not navigate. The page may be asking the student to confirm leaving it.',
            );
        }

        await sleep(SETTLE_AFTER_LOAD_MS);
        const page = await readPage(run, true);
        run.log(`Opened ${shortUrl(page.url)}`, 'ok');
        run.saw(page);

        return done({ page });
    } finally {
        loads.stop();
    }
}

/** Fields the content script leaves to the student, by the start of its (mentor-facing) error. */
const LEFT_FOR_YOU: Record<string, string> = {
    "This needs the student's confirmation": 'to allow',
    'Passwords are typed by the student': '(passwords are yours to type)',
    'Files are chosen by the student': '(you choose files)',
    'CAPTCHAs are left to the student': '(CAPTCHA)',
    'Signatures are typed by the student': 'to sign',
};

async function fillFields(run: Runner, raw: unknown): Promise<AgentResult> {
    const fields = (Array.isArray(raw) ? raw : [])
        .filter(
            (field): field is { id: string; value: unknown } =>
                typeof field === 'object' &&
                field !== null &&
                typeof (field as { id?: unknown }).id === 'string',
        )
        .map((field) => ({
            id: field.id,
            value:
                typeof field.value === 'number' ||
                typeof field.value === 'boolean'
                    ? String(field.value)
                    : (field.value as string),
        }));

    if (fields.length === 0) {
        return failed('There were no fields to fill.');
    }

    const answer = await toPage<FillAnswer | { error?: string }>(run.tabId, {
        type: 'admitme.agent.fill',
        version: AGENT_CONTRACT_VERSION,
        fields,
    });

    if (!('fields' in answer) || !Array.isArray(answer.fields)) {
        throw new Error(
            ('error' in answer ? answer.error : undefined) ??
                'The page did not report the fields.',
        );
    }

    const filled = answer.fields.filter((field) => field.ok).length;

    if (filled > 0) {
        run.log(`Filled ${plural(filled, 'field')}`, 'ok');
    }

    for (const field of answer.fields.filter((item) => !item.ok)) {
        const label = clipText(run.label(field.id), 48);
        const leftForStudent = Object.entries(LEFT_FOR_YOU).find(([prefix]) =>
            (field.error ?? '').startsWith(prefix),
        );

        run.log(
            leftForStudent
                ? `Left “${label}” for you ${leftForStudent[1]}`
                : `Couldn’t fill “${label}”: ${field.error ?? 'it shows another value'}`,
            'warn',
        );
    }

    return done({ fields: answer.fields, alerts: answer.alerts ?? [] });
}

const DECLINED: Record<
    Exclude<Decision, 'allow'>,
    { message: string; log: (label: string) => string | null }
> = {
    deny: {
        message: 'The student pressed Deny.',
        log: (label) => `You said no to “${label}”`,
    },
    timeout: {
        message: 'The student did not answer within 3 minutes.',
        log: (label) => `No answer in 3 minutes, so “${label}” was not clicked`,
    },
    stopped: {
        message: 'The student stopped mentor control.',
        log: () => null,
    },
    ended: {
        message: 'Mentor control was turned off.',
        log: () => null,
    },
};

async function clickControl(run: Runner, id: unknown): Promise<AgentResult> {
    if (typeof id !== 'string' || id === '') {
        return failed('No element was given to click.');
    }

    const before = (await tabOrNull(run.tabId))?.url;
    const ask = (confirmed: boolean) =>
        toPage<ClickAnswer>(run.tabId, {
            type: 'admitme.agent.click',
            version: AGENT_CONTRACT_VERSION,
            id,
            confirmed,
        });
    let loads = watchLoads(run.tabId);

    try {
        let answer = await ask(false);

        if (!answer.ok && 'needs_confirmation' in answer) {
            const label = clipText(answer.label, 80);
            run.log(`Asked you before clicking “${label}”`, 'warn');
            const decision = await run.confirm(
                answer.label,
                (await tabOrNull(run.tabId))?.url ?? before,
            );

            if (decision !== 'allow') {
                const outcome = DECLINED[decision];
                const line = outcome.log(label);

                if (line !== null) {
                    run.log(line, decision === 'deny' ? 'info' : 'warn');
                }

                return { outcome: 'declined', message: outcome.message };
            }

            run.log(`You allowed “${label}”`, 'ok');
            loads.stop();
            loads = watchLoads(run.tabId);

            // Click only the control the student allowed: ask again, and confirm only if it is still that one.
            const again = await ask(false);

            if (!again.ok && 'needs_confirmation' in again) {
                if (again.label !== answer.label) {
                    run.log(
                        `Didn’t click: the page changed after you allowed “${label}”`,
                        'warn',
                    );

                    return failed(
                        'The control changed after the student allowed it. Read the page and ask again.',
                    );
                }

                answer = await ask(true);
            } else {
                answer = again;
            }
        }

        if (!answer.ok) {
            const message =
                'error' in answer ? answer.error : 'It was not clicked.';
            run.log(
                `Couldn’t click “${clipText(run.label(id), 48)}”: ${message}`,
                'warn',
            );

            return failed(message);
        }

        run.log(`Clicked “${clipText(answer.clicked, 60)}”`, 'ok');
        const page = await settleAndRead(run, SETTLE_AFTER_CLICK_MS);
        run.saw(page);

        return done({
            clicked: answer.clicked,
            navigated: loads.count > 0 || page.url !== before,
            page,
        });
    } finally {
        loads.stop();
    }
}

async function pressKey(
    run: Runner,
    payload: { key?: unknown; id?: unknown },
): Promise<AgentResult> {
    const key = payload.key as AgentKey;

    if (typeof key !== 'string' || !(key in KEY_NAMES)) {
        return failed('That key is not supported.');
    }

    const id =
        typeof payload.id === 'string' && payload.id !== ''
            ? payload.id
            : undefined;
    const answer = await toPage<KeyAnswer>(run.tabId, {
        type: 'admitme.agent.key',
        version: AGENT_CONTRACT_VERSION,
        key,
        ...(id !== undefined ? { id } : {}),
    });

    if (!answer.ok) {
        run.log(`Couldn’t press ${KEY_NAMES[key]}: ${answer.error}`, 'warn');

        return failed(answer.error);
    }

    run.log(
        id !== undefined
            ? `Pressed ${KEY_NAMES[key]} on “${clipText(run.label(id), 48)}”`
            : `Pressed ${KEY_NAMES[key]}`,
        'ok',
    );
    const page = await settleAndRead(run, SETTLE_AFTER_KEY_MS);
    run.saw(page);

    return done({ page });
}

/** One line for the panel while a command runs. */
function describeWork(
    command: AgentCommand,
    label: (id: string) => string,
): string {
    switch (command.type) {
        case 'agent_open': {
            const url = String(command.payload?.url ?? '');

            return `Opening ${originOf(url) !== null ? shortUrl(url) : 'a page'}…`;
        }
        case 'agent_read':
            return 'Reading the page…';
        case 'agent_fill': {
            const count = Array.isArray(command.payload?.fields)
                ? command.payload.fields.length
                : 0;

            return `Filling ${plural(count, 'field')}…`;
        }
        case 'agent_click':
            return `Clicking “${clipText(label(String(command.payload?.id ?? '')), 40)}”…`;
        case 'agent_key':
            return `Pressing ${KEY_NAMES[command.payload?.key] ?? 'a key'}…`;
        default:
            return 'Working…';
    }
}

/** Runs one command in the mentor's tab. Never throws: every problem becomes a failed (or declined) result. */
async function runCommand(
    run: Runner,
    command: AgentCommand,
): Promise<AgentResult> {
    try {
        if ((await tabOrNull(run.tabId)) === null) {
            throw new TabClosedError();
        }

        switch (command.type) {
            case 'agent_open':
                return await openAddress(run, command.payload?.url);
            case 'agent_read': {
                const page = await readPage(
                    run,
                    command.payload?.text !== false,
                );
                const fields = fieldCount(page);
                run.log(
                    fields > 0
                        ? `Read the page · ${plural(fields, 'field')}`
                        : 'Read the page',
                );
                run.saw(page);

                return done({ page });
            }
            case 'agent_fill':
                return await fillFields(run, command.payload?.fields);
            case 'agent_click':
                return await clickControl(run, command.payload?.id);
            case 'agent_key':
                return await pressKey(run, command.payload ?? {});
            default:
                run.log(
                    'The mentor asked for something this version cannot do',
                    'warn',
                );

                return failed(
                    'This version of the AdmitMe extension cannot do that. Update the extension.',
                );
        }
    } catch (error) {
        if (
            error instanceof TabClosedError ||
            (await tabOrNull(run.tabId)) === null
        ) {
            run.tabClosed();

            return failed(TAB_CLOSED);
        }

        const message =
            error instanceof Error
                ? error.message
                : 'Something went wrong in the browser.';
        run.log(`Something didn’t work: ${message}`, 'error');

        return failed(message);
    }
}

/**
 * Mentor control of one tab. Polls AdmitMe for the mentor's next command (the poll is also the heartbeat), runs
 * it in the tab and reports the result — strictly one command at a time — and shows each action as it happens.
 */
export function ControlView({
    link,
    control,
    initialActivity,
    onMoved,
    onEnded,
    onUnauthorized,
}: {
    link: Link;
    control: AgentControl;
    initialActivity: Activity[];
    onMoved: (started: StartedControl) => Promise<void>;
    onEnded: (notice: string | null) => void;
    onUnauthorized: () => void;
}) {
    const [activity, setActivity] = useState<Activity[]>(initialActivity);
    const [url, setUrl] = useState<string | undefined>(undefined);
    const [working, setWorking] = useState<string | null>(null);
    const [waiting, setWaiting] = useState({ login: false, captcha: false });
    const [tabClosed, setTabClosed] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const stopped = useRef(false);
    const inflight = useRef<Promise<unknown> | null>(null);
    const decide = useRef<((decision: Decision) => void) | null>(null);
    const labels = useRef(new Map<string, string>());
    const seen = useRef({ login: false, captcha: false });
    const nextId = useRef(
        initialActivity.reduce((max, entry) => Math.max(max, entry.id), 0) + 1,
    );

    const log = useCallback((text: string, tone: Tone = 'info') => {
        const entry: Activity = {
            id: nextId.current,
            at: Date.now(),
            text,
            tone,
        };
        nextId.current += 1;
        setActivity((previous) =>
            [entry, ...previous].slice(0, ACTIVITY_LIMIT),
        );
    }, []);

    const saw = useCallback(
        (page: PageModel) => {
            for (const element of page.elements) {
                labels.current.set(element.id, element.label || element.id);
            }

            setUrl(page.url);
            const now = { login: page.login_required, captcha: page.captcha };

            if (now.login && !seen.current.login) {
                log('Waiting for you to log in', 'warn');
            }

            if (now.captcha && !seen.current.captcha) {
                log('Waiting for you to finish the CAPTCHA', 'warn');
            }

            seen.current = now;
            setWaiting(now);
        },
        [log],
    );

    // A reopened panel shows the activity so far.
    useEffect(() => {
        void chrome.storage.local.set({
            agentActivity: { session: control.id, items: activity },
        });
    }, [activity, control.id]);

    useEffect(() => {
        let alive = true;
        let stash: AgentCommand | null = null;
        const live = () => alive && !stopped.current;
        const base = `/api/browser/agent-sessions/${control.id}`;

        const poll = async () => {
            const tab = await tabOrNull(control.tabId);
            const query = new URLSearchParams({
                tab_id: String(control.tabId),
            });

            if (tab && originOf(tab.url) !== null) {
                query.set('url', tab.url as string);
            }

            const answer = await apiStatus<NextAnswer | null>(
                link.server,
                link.token,
                `${base}/next?${query.toString()}`,
                { okStatuses: [409, 410] },
            );

            return { tab, ...answer };
        };

        const isClosed = (status: number, data: NextAnswer | null) =>
            status === 410 ||
            data?.status === 'closed' ||
            data?.session?.status === 'closed';

        // Ended by AdmitMe (not by Stop here, which ends the view itself).
        const finish = (notice: string) => {
            if (!live()) {
                return;
            }

            alive = false;
            decide.current?.('ended');
            onEnded(notice);
        };

        const confirm = async (
            label: string,
            pageUrl: string | undefined,
        ): Promise<Decision> => {
            // The poll goes on as a heartbeat while the card is up (the server hands out nothing while a command
            // is leased), so AdmitMe still sees this browser, and a Stop in the web app takes the card away.
            const beat = window.setInterval(() => {
                void poll()
                    .then((answer) => {
                        if (isClosed(answer.status, answer.data)) {
                            decide.current?.('ended');
                        } else if (answer.data?.command && stash === null) {
                            stash = answer.data.command; // not expected; run it next
                        }
                    })
                    .catch(() => undefined);
            }, HEARTBEAT_MS);

            try {
                return await new Promise<Decision>((resolve) => {
                    let timer = 0;
                    const settle = (decision: Decision) => {
                        if (decide.current !== settle) {
                            return;
                        }

                        decide.current = null;
                        window.clearTimeout(timer);
                        setConfirmation(null);
                        resolve(decision);
                    };

                    decide.current = settle;
                    timer = window.setTimeout(
                        () => settle('timeout'),
                        CONFIRM_MS,
                    );
                    setConfirmation({
                        label,
                        host: hostOf(pageUrl),
                        deadline: Date.now() + CONFIRM_MS,
                    });
                });
            } finally {
                window.clearInterval(beat);
            }
        };

        const runner: Runner = {
            tabId: control.tabId,
            log,
            saw,
            label: (id) => labels.current.get(id) ?? id,
            confirm,
            tabClosed: () => {
                setTabClosed(true);
                log('The mentor’s tab was closed', 'error');
            },
        };

        const report = async (commandId: string, result: AgentResult) => {
            for (let attempt = 1; attempt <= 3; attempt += 1) {
                try {
                    await api(
                        link.server,
                        link.token,
                        `/api/browser/agent-commands/${commandId}/result`,
                        { method: 'POST', body: result },
                    );

                    return;
                } catch (e) {
                    const temporary =
                        e instanceof ApiError &&
                        (e.status === 0 || e.status === 429 || e.status >= 500);

                    if (!temporary || attempt === 3) {
                        throw e;
                    }

                    await sleep(800 * attempt);
                }
            }
        };

        void (async () => {
            let failures = 0;
            let delay = 0;

            while (live()) {
                if (delay > 0) {
                    await sleep(delay);

                    if (!live()) {
                        return;
                    }
                }

                let command: AgentCommand | null = stash;
                stash = null;

                if (command === null) {
                    let answer: Awaited<ReturnType<typeof poll>>;

                    try {
                        answer = await poll();
                    } catch (e) {
                        if (!live()) {
                            return;
                        }

                        if (e instanceof ApiError && e.status === 401) {
                            alive = false;
                            onUnauthorized();

                            return;
                        }

                        if (e instanceof ApiError && e.status === 404) {
                            finish(
                                'This mentor session is no longer available. Start it again when you need it.',
                            );

                            return;
                        }

                        failures += 1;

                        if (failures >= 2) {
                            setProblem(
                                e instanceof ApiError && e.status !== 0
                                    ? `AdmitMe answered with an error: ${e.message} Retrying…`
                                    : 'Can’t reach AdmitMe. Retrying…',
                            );
                        }

                        delay = Math.min(10000, 1000 * 2 ** (failures - 1));
                        continue;
                    }

                    if (!live()) {
                        return;
                    }

                    failures = 0;
                    setUrl(answer.tab?.url);
                    setTabClosed(answer.tab === null);

                    if (isClosed(answer.status, answer.data)) {
                        finish('Mentor control was turned off.');

                        return;
                    }

                    if (answer.status === 409) {
                        setProblem(
                            'AdmitMe expects another tab for this session. Stop, then start again in the tab you want the mentor to use.',
                        );
                        delay = 3000;
                        continue;
                    }

                    setProblem(null);
                    command = answer.data?.command ?? null;

                    if (command === null) {
                        delay = AGENT_POLL_MS;
                        continue;
                    }
                }

                const current = command;
                setWorking(describeWork(current, runner.label));
                // Kept in a ref so Stop can let a command waiting for Allow report "declined" first.
                const step = (async (): Promise<unknown> => {
                    const result = await runCommand(runner, current);
                    setWorking(null);

                    try {
                        await report(current.id, result);

                        return null;
                    } catch (e) {
                        return e ?? new Error('Report failed');
                    }
                })();
                inflight.current = step;
                const problemReporting = await step;
                inflight.current = null;

                if (problemReporting !== null) {
                    const e = problemReporting;

                    if (!live()) {
                        return;
                    }

                    if (e instanceof ApiError && e.status === 401) {
                        alive = false;
                        onUnauthorized();

                        return;
                    }

                    if (e instanceof ApiError && e.status === 410) {
                        finish('Mentor control was turned off.');

                        return;
                    }

                    log('Couldn’t send that result to AdmitMe', 'error');
                }

                delay = 0;
            }
        })();

        return () => {
            alive = false;
            decide.current?.('stopped');
        };
    }, [
        control.id,
        control.tabId,
        link.server,
        link.token,
        log,
        saw,
        onEnded,
        onUnauthorized,
    ]);

    async function stop() {
        setBusy(true);
        stopped.current = true;
        decide.current?.('stopped');

        // A click that was waiting for Allow reports "declined" before the session closes (briefly; never long).
        if (inflight.current) {
            await Promise.race([inflight.current, sleep(2000)]);
        }

        await api(
            link.server,
            link.token,
            `/api/browser/sessions/${control.id}`,
            {
                method: 'DELETE',
            },
        ).catch(() => undefined);
        onEnded(
            'Mentor control is off. Turn it on again whenever you want the mentor’s help in the browser.',
        );
    }

    async function moveToCurrentTab() {
        setBusy(true);
        setError(null);

        try {
            await onMoved(await startControl(link));
        } catch (e) {
            if (e instanceof ApiError && e.status === 401) {
                onUnauthorized();

                return;
            }

            setError(
                e instanceof Error ? e.message : 'Could not move to this tab.',
            );
            setBusy(false);
        }
    }

    function showTab() {
        void tabOrNull(control.tabId).then((tab) => {
            if (tab?.id === undefined) {
                return;
            }

            void chrome.tabs
                .update(tab.id, { active: true })
                .catch(() => undefined);

            if (tab.windowId !== undefined) {
                void chrome.windows
                    .update(tab.windowId, { focused: true })
                    .catch(() => undefined);
            }
        });
    }

    return (
        <section className="section control" data-agent-session={control.id}>
            <div className="control-head">
                <span className="live-dot" aria-hidden="true" />
                <div className="grow">
                    <h2>Mentor control is on</h2>
                    <p className="host" title={url}>
                        {tabClosed ? 'Its tab was closed' : hostOf(url)}
                        {tabClosed ? null : (
                            <>
                                {' · '}
                                <button
                                    type="button"
                                    className="text-button"
                                    onClick={showTab}
                                >
                                    Show tab
                                </button>
                            </>
                        )}
                    </p>
                </div>
                <button
                    type="button"
                    className="stop"
                    data-testid="stop-agent"
                    disabled={busy}
                    onClick={() => void stop()}
                >
                    {busy ? 'Stopping…' : 'Stop'}
                </button>
            </div>

            <p className="muted">
                The mentor can open pages, read them and fill them in this tab.
                It never types passwords — you log in yourself. It asks before
                anything final like submitting or paying.
            </p>

            {confirmation ? (
                <ConfirmCard
                    confirmation={confirmation}
                    onAllow={() => decide.current?.('allow')}
                    onDeny={() => decide.current?.('deny')}
                    onShowTab={showTab}
                />
            ) : null}

            {tabClosed ? (
                <div
                    className="notice row-between"
                    data-testid="agent-tab-closed"
                >
                    <span>The mentor’s tab was closed.</span>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void moveToCurrentTab()}
                    >
                        Use the current tab
                    </button>
                </div>
            ) : null}
            {waiting.login ? (
                <p className="notice wait" data-testid="agent-wait-login">
                    Log in on the page yourself — the mentor never sees or types
                    passwords. Then tell the mentor you’re in.
                </p>
            ) : null}
            {waiting.captcha ? (
                <p className="notice wait" data-testid="agent-wait-captcha">
                    Finish the CAPTCHA on the page yourself, then tell the
                    mentor.
                </p>
            ) : null}
            {problem ? (
                <p className="notice" role="status" data-testid="agent-problem">
                    {problem}
                </p>
            ) : null}
            {error ? (
                <p role="alert" className="error">
                    {error}
                </p>
            ) : null}

            <div className="card activity-card">
                <div className="activity-head">
                    <p className="title">Activity</p>
                    <p className="muted" data-testid="agent-working">
                        {working ?? 'Waiting for the mentor'}
                    </p>
                </div>
                {activity.length === 0 ? (
                    <p className="muted">
                        Nothing yet. Ask the mentor in AdmitMe to fill in your
                        application.
                    </p>
                ) : (
                    <ol className="activity" data-testid="agent-activity">
                        {activity.map((entry) => (
                            <li key={entry.id} className={entry.tone}>
                                <time
                                    dateTime={new Date(entry.at).toISOString()}
                                >
                                    {formatTime(entry.at)}
                                </time>
                                <span>{entry.text}</span>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </section>
    );
}

/** "The mentor wants to click “Submit application” on lakeside.edu." Allow / Deny, declined after 3 minutes. */
function ConfirmCard({
    confirmation,
    onAllow,
    onDeny,
    onShowTab,
}: {
    confirmation: Confirmation;
    onAllow: () => void;
    onDeny: () => void;
    onShowTab: () => void;
}) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const left = Math.max(0, confirmation.deadline - now);
    const minutes = Math.floor(left / 60000);
    const seconds = Math.floor((left % 60000) / 1000);

    return (
        <div
            className="card confirm"
            role="alertdialog"
            aria-labelledby="confirm-title"
            data-testid="agent-confirm"
        >
            <p className="title" id="confirm-title">
                The mentor wants to click “{confirmation.label}” on{' '}
                {confirmation.host}.
            </p>
            <p className="muted">
                Nothing is clicked unless you press Allow. Check the page first
                if you like.
            </p>
            <div className="row">
                <button type="button" className="primary" onClick={onAllow}>
                    Allow
                </button>
                <button type="button" onClick={onDeny}>
                    Deny
                </button>
                <button
                    type="button"
                    className="text-button"
                    onClick={onShowTab}
                >
                    Show the page
                </button>
            </div>
            <small>
                Not clicked if there’s no answer in {minutes}:
                {String(seconds).padStart(2, '0')}.
            </small>
        </div>
    );
}
