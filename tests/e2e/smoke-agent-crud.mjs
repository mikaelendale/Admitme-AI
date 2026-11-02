// Browser smoke for the mentor changing the student's data through approvals (real model, e2e database):
// it proposes a deadline change on the college list and starting an application, the student approves in the
// chat card, and the saved records change — nothing changes before approval.
// Run against the e2e server (see README): node smoke-agent-crud.mjs
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { clickByText, launch, login, report, settle, shot, teeChat, BASE } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const results = [];
const panel = 'section[aria-label="Mentor"]';
const last = `${panel} .space-y-7 > div:last-child`;

function record(step, ok, note = '') {
    results.push({ step, ok, note });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${note ? ' — ' + note : ''}`);
}

async function step(name, fn) {
    try {
        const { ok, note } = (await fn()) ?? { ok: true };
        record(name, ok, note);
    } catch (error) {
        record(name, false, String(error?.message ?? error).slice(0, 300));
    }
}

function college(name) {
    const out = execFileSync('bash', [path.join(here, 'e2e-php.sh'), 'college', name], { encoding: 'utf8' });

    return JSON.parse(out.trim().split('\n').pop());
}

const { browser, page, issues } = await launch();
// Mentor replies run as background turns; record each one in window.__chatStreams.
await teeChat(page);
await page.setViewport({ width: 1440, height: 900 });

await page.evaluateOnNewDocument(() => {
    window.__chatStreams = [];
    const original = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url ?? '');
        const response = await original(...args);
        if (/\/dashboard\/chat(\?|$)/.test(url) && response.body) {
            const [forPage, forTest] = response.body.tee();
            const entry = { done: false, text: '' };
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

async function ask(message) {
    const before = await page.evaluate(() => window.__chatStreams.length);
    await page.click(`${panel} textarea`);
    await page.type(`${panel} textarea`, message);
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => window.__chatStreams.length > n && window.__chatStreams.at(-1).done, { timeout: 300000, polling: 500 }, before);
    await settle(page, 1200);

    return page.evaluate(() => window.__chatStreams.at(-1).text);
}

/** Approve the pending card in the last reply and wait for it to report the outcome. */
async function approveLast() {
    const clicked = await page.evaluate((sel) => {
        const reply = document.querySelector(sel);
        const button = [...(reply?.querySelectorAll('button') ?? [])].find((b) => /^Approve( \d+ selected)?$/.test(b.innerText.trim()));
        button?.click();

        return !!button;
    }, last);

    if (clicked) {
        await page.waitForFunction((sel) => document.querySelector(sel)?.innerText.includes('Approved and applied'), { timeout: 60000 }, last);
    }

    return clicked;
}

await step('open the docked mentor in Agent mode on a new chat', async () => {
    await login(page, 'synthetic.student@example.test');
    await page.evaluate(() => {
        localStorage.removeItem('admitme.mentor.conversation');
        localStorage.setItem('admitme.mentor.open', '1');
    });
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`${panel} textarea`, { timeout: 30000 });
    await settle(page, 800);
    const agent = await clickByText(page, '[aria-label="Mentor mode"] button', 'Agent');

    return { ok: agent, note: '' };
});

let northfield;
await step('asked to change a deadline, the mentor proposes it and nothing changes yet', async () => {
    northfield = college('Synthetic Northfield College');
    const raw = await ask("On my college list, change Synthetic Northfield College's Regular Decision deadline to January 15, 2027. Propose the change now, no questions.");
    const pending = await page.$eval(last, (el) => el.innerText.includes('Waiting for your approval'));
    const unchanged = college('Synthetic Northfield College');
    await shot(page, 'crud-1-deadline-proposed');

    return {
        ok: pending && raw.includes('"type":"data-proposal"') && unchanged.rd === northfield.rd,
        note: `before=${northfield.rd} pending=${pending} still=${unchanged.rd} tools=${[...raw.matchAll(/"toolName":"([^"]+)"/g)].map((m) => m[1]).join(',')}`,
    };
});

await step('approving in the chat card saves the new deadline', async () => {
    const clicked = await approveLast();
    const after = college('Synthetic Northfield College');
    await shot(page, 'crud-2-deadline-applied');

    return { ok: clicked && after.rd === '2027-01-15', note: `clicked=${clicked} rd=${after.rd}` };
});

await step('asked to start an application, the mentor proposes it; approving opens the application', async () => {
    const before = college('Synthetic Riverbend Institute');
    const raw = await ask('Start an application for Synthetic Riverbend Institute. Propose it now, no questions.');
    const pending = await page.$eval(last, (el) => el.innerText.includes('Waiting for your approval'));
    const clicked = pending ? await approveLast() : false;
    const after = college('Synthetic Riverbend Institute');
    await shot(page, 'crud-3-application-started');

    return {
        ok: !before.application && pending && clicked && after.application && after.choice === 'applying',
        note: `before=${before.choice}/${before.application} after=${after.choice}/${after.application} tools=${[...raw.matchAll(/"toolName":"([^"]+)"/g)].map((m) => m[1]).join(',')}`,
    };
});

await step('reopened, both cards show their outcome', async () => {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction((sel) => (document.querySelector(sel)?.innerText.match(/Approved and applied/g) ?? []).length >= 2, { timeout: 30000 }, panel);

    return { ok: true, note: '' };
});

await step('no console or server errors', async () => ({ ok: issues.length === 0, note: issues.map((i) => i.text).join(' | ').slice(0, 300) }));

const passed = results.filter((r) => r.ok).length;
console.log(`\n${passed}/${results.length} steps passed; ${issues.length} browser issue(s).`);
report('smoke-agent-crud', { results, issues });
await browser.close();
process.exit(results.every((r) => r.ok) ? 0 : 1);
