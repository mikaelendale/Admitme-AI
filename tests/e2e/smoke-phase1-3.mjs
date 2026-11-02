// End-to-end smoke for Phases 1–3 against the e2e server. Run: node smoke-phase1-3.mjs
import { clickByText, inertiaPage, launch, login, report, settle, shot, teeChat, visit, waitForText } from './lib.mjs';

const EMAIL = 'synthetic.student@example.test';
const results = [];

function record(step, ok, detail = {}) {
    results.push({ step, ok, ...detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${detail.note ? ' — ' + detail.note : ''}`);
}

async function step(name, fn) {
    try {
        const detail = (await fn()) ?? {};
        record(name, detail.ok ?? true, detail);
    } catch (error) {
        record(name, false, { note: String(error?.message ?? error).slice(0, 300) });
    }
}

/** Parse a Vercel UI message stream into text, tool calls and errors. */
function parseStream(raw) {
    const parts = raw
        .split('\n')
        .filter((line) => line.startsWith('data: ') && !line.includes('[DONE]'))
        .map((line) => {
            try {
                return JSON.parse(line.slice(6));
            } catch {
                return null;
            }
        })
        .filter(Boolean);

    return {
        text: parts.filter((p) => p.type === 'text-delta').map((p) => p.delta).join(''),
        tools: parts.filter((p) => p.type === 'tool-input-available').map((p) => p.toolName),
        toolOutputs: parts.filter((p) => p.type?.startsWith('tool-output')).map((p) => ({ type: p.type, output: typeof p.output === 'string' ? p.output.slice(0, 300) : p.output })),
        errors: parts.filter((p) => p.type === 'error').map((p) => p.errorText),
        finished: parts.some((p) => p.type === 'finish'),
    };
}

async function sendChat(page, message, timeout = 150000) {
    const before = await page.evaluate(() => (window.__chatStreams ?? []).length);
    await page.click('textarea');
    await page.type('textarea', message);
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => (window.__chatStreams ?? []).length > n && window.__chatStreams.at(-1).done, { timeout, polling: 500 }, before);
    const raw = await page.evaluate(() => window.__chatStreams.at(-1).text);
    await settle(page, 1200);

    return parseStream(raw);
}

const { browser, page, issues } = await launch();
// Mentor replies run as background turns; record each one in window.__chatStreams.
await teeChat(page);

// Tee the chat stream so the test sees exactly what the server sent.
await page.evaluateOnNewDocument(() => {
    window.__chatStreams = [];
    const original = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const response = await original(...args);
        const url = typeof args[0] === 'string' ? args[0] : args[0]?.url ?? '';
        if (/\/dashboard\/chat(\?|$)/.test(url) && response.body) {
            const [forPage, forTest] = response.body.tee();
            const entry = { url, done: false, text: '' };
            window.__chatStreams.push(entry);
            (async () => {
                const reader = forTest.getReader();
                const decoder = new TextDecoder();
                for (;;) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    entry.text += decoder.decode(value, { stream: true });
                }
                entry.done = true;
            })();

            return new Response(forPage, { status: response.status, statusText: response.statusText, headers: response.headers });
        }

        return response;
    };
});

await step('login as the synthetic student', async () => {
    await login(page, EMAIL);

    return { note: `landed on ${new URL(page.url()).pathname}` };
});

for (const [name, path] of [
    ['dashboard', '/dashboard'],
    ['college-lists', '/college-lists'],
    ['applications', '/applications'],
    ['tasks', '/tasks'],
    ['approvals', '/approvals'],
    ['profile-overview', '/profile-overview'],
    ['essay-suite', '/essay-suite'],
    ['gap-fix', '/gap-fix'],
    ['settings-profile', '/settings/profile'],
]) {
    await step(`page renders: ${path}`, async () => {
        const status = await visit(page, path);
        const heading = await page.evaluate(() => document.querySelector('h1')?.innerText ?? '');
        const crashed = await page.evaluate(() => /Whoops|Server Error|Fatal error|Warning:|Parse error|Uncaught/i.test(document.body.innerText));
        const inertia = await inertiaPage(page);
        await shot(page, `p13-${name}`);

        // A real page is an Inertia page that rendered into the DOM, not just an HTTP 200...
        const rendered = inertia?.component && (await page.evaluate(() => document.body.innerText.trim().length > 40));

        return { ok: status === 200 && !crashed && Boolean(rendered), note: `HTTP ${status}, component=${inertia?.component ?? 'none'}, h1="${heading.slice(0, 60)}"` };
    });
}

let schoolId = null;
await step('college detail: start an application from a shortlisted college', async () => {
    await visit(page, '/college-lists');
    const props = (await inertiaPage(page))?.props;
    const school = props.colleges.find((c) => c.name === 'Synthetic Lakeside University');
    schoolId = school.id;
    await visit(page, `/college-lists/${schoolId}`);
    await shot(page, 'p13-school-before');
    const clicked = await clickByText(page, 'button', 'Start application');
    await waitForText(page, 'Open application');
    await shot(page, 'p13-school-after');

    return { ok: clicked, note: `school ${school.name} now applying` };
});

/** Click Save inside the form that owns this application's status select. */
async function saveApplication(id) {
    await page.$eval(`#status-${id}`, (el) => el.closest('form').querySelector('button[type="submit"]').click());
}

/**
 * Wait until the server shows the application in the expected state. Reloads each time: the embedded
 * page JSON only reflects the initial load, not later Inertia client-side visits.
 */
async function waitForApplication(id, predicate, timeout = 15000) {
    const started = Date.now();
    await settle(page, 800);
    for (;;) {
        await page.reload({ waitUntil: 'domcontentloaded' });
        await settle(page, 300);
        const app = (await inertiaPage(page))?.props?.applications?.find((a) => a.id === id);
        if (app && predicate(app)) return app;
        if (Date.now() - started > timeout) return app;
    }
}

await step('applications: move to ready-to-submit, then submitted with evidence', async () => {
    await visit(page, '/applications');
    const app = (await inertiaPage(page)).props.applications.find((a) => a.college.id === schoolId);
    await page.select(`#status-${app.id}`, 'ready_to_submit');
    await saveApplication(app.id);
    const ready = await waitForApplication(app.id, (a) => a.status === 'ready_to_submit');
    if (ready?.status !== 'ready_to_submit') throw new Error(`expected ready_to_submit, got ${ready?.status}`);
    await settle(page);

    await page.select(`#status-${app.id}`, 'submitted');
    await page.waitForSelector(`#evidence-${app.id}`, { timeout: 5000 });
    await page.select(`#evidence-${app.id}`, 'student_confirmation');
    await page.type(`#evidence-note-${app.id}`, 'Confirmation email received (synthetic)');
    await saveApplication(app.id);
    const after = await waitForApplication(app.id, (a) => a.status === 'submitted');
    await shot(page, 'p13-applications-submitted');

    return { ok: after.status === 'submitted' && after.submissionEvidenceType === 'student_confirmation', note: `status=${after.status}` };
});

await step('applications: submitted without evidence is refused', async () => {
    await visit(page, '/college-lists');
    const props = (await inertiaPage(page)).props;
    const other = props.colleges.find((c) => c.name === 'Synthetic Northfield College');
    await visit(page, `/college-lists/${other.id}`);
    await clickByText(page, 'button', 'Start application');
    await waitForText(page, 'Open application');
    await visit(page, '/applications');
    const app = (await inertiaPage(page)).props.applications.find((a) => a.college.id === other.id);
    await page.select(`#status-${app.id}`, 'ready_to_submit');
    await saveApplication(app.id);
    await waitForApplication(app.id, (a) => a.status === 'ready_to_submit');
    await settle(page);
    await page.select(`#status-${app.id}`, 'submitted');
    await saveApplication(app.id);
    await settle(page, 1500);
    const text = await page.evaluate(() => document.body.innerText);
    const after = (await inertiaPage(page)).props.applications.find((a) => a.id === app.id);
    await shot(page, 'p13-applications-evidence-required');

    return { ok: after.status !== 'submitted' && /Record how you know it was submitted/.test(text), note: `status=${after.status}` };
});

await step('approvals: approve only one field of a profile proposal', async () => {
    await visit(page, '/approvals');
    await shot(page, 'p13-approvals-before');
    const props = (await inertiaPage(page)).props;
    const profileProposal = props.pending.find((p) => p.type === 'profile.update');
    await page.click('[aria-label="Include Budget band"]');
    const clicked = await clickByText(page, 'button', 'Approve 1 selected');
    await waitForText(page, 'Approved and applied');
    await visit(page, '/profile-overview');
    const profile = (await inertiaPage(page)).props.profile;

    return {
        ok: clicked && profile.demographics.graduation_year === 2028,
        note: `proposal ${profileProposal.id}: graduation_year=${profile.demographics.graduation_year}`,
    };
});

await step('approvals: approve 2 of 3 proposed colleges', async () => {
    await visit(page, '/approvals');
    await page.click('[aria-label="Include Synthetic Birch Institute"]');
    const clicked = await clickByText(page, 'button', 'Approve 2 selected');
    await waitForText(page, 'Approved and applied');
    await shot(page, 'p13-approvals-after');
    await visit(page, '/college-lists');
    const names = (await inertiaPage(page)).props.colleges.map((c) => c.name);
    const ok = clicked && names.includes('Synthetic Willow University') && names.includes('Synthetic Aspen College') && !names.includes('Synthetic Birch Institute');

    return { ok, note: `list now has ${names.length} colleges` };
});

await step('nav badge cleared after approvals', async () => {
    await visit(page, '/dashboard');
    const count = (await inertiaPage(page)).props.pending_approval_count;

    return { ok: count === 0, note: `pending_approval_count=${count}` };
});

const chats = {};
await step('chat (real model, Ask): answers from saved facts', async () => {
    await visit(page, '/dashboard');
    const reply = await sendChat(page, 'In two short sentences: which curriculum am I studying, and name one thing that is missing from my profile.');
    chats.ask = reply;
    await shot(page, 'p13-chat-ask');

    return { ok: reply.text.length > 0 && reply.errors.length === 0, note: `tools=${reply.tools.join(',') || 'none'}; text="${reply.text.slice(0, 160)}"` };
});

await step('chat (real model, Ask): a change request is not performed', async () => {
    const reply = await sendChat(page, 'Add Synthetic Spruce College to my college list.');
    chats.askMutation = reply;
    await shot(page, 'p13-chat-ask-mutation');
    await visit(page, '/college-lists');
    const names = (await inertiaPage(page)).props.colleges.map((c) => c.name);

    return { ok: !names.includes('Synthetic Spruce College'), note: `tools=${reply.tools.join(',') || 'none'}; text="${reply.text.slice(0, 160)}"` };
});

await step('chat (real model, Agent): a list addition becomes an approval card', async () => {
    await visit(page, '/dashboard');
    await clickByText(page, '[aria-label="Mentor mode"] button', 'Agent');
    const reply = await sendChat(page, 'Please add Synthetic Cedar College to my college list as a reach school. It has the economics program I want.');
    chats.agent = reply;
    await shot(page, 'p13-chat-agent');
    const cardVisible = await page.evaluate(() => document.body.innerText.includes('Waiting for your approval'));

    return { ok: reply.tools.includes('ProposeCollegeListAdditionsTool') && cardVisible, note: `tools=${reply.tools.join(',') || 'none'}; card=${cardVisible}; text="${reply.text.slice(0, 160)}"` };
});

report('smoke-phase1-3', { results, issues, chats });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
for (const issue of issues) {
    console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
}
await browser.close();
