// Browser smoke for mentor browser control (real model, real extension, e2e database): the student gives the
// mentor one tab in the side panel, asks in the chat to fill a portal, logs in when asked, and the mentor reads
// and fills the pages itself. It never types a password, and the final submit needs the student's Allow in the
// panel (denied here: nothing is submitted). Needs: run-all (fresh DB, server, workers), site-server.mjs on
// :8766 and `npm run build:extension`.
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, clickByText, launch, login, report, settle, shot, teeChat, waitForText } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const DIST = path.resolve(here, '../../browser-extension/dist');
const PORTAL = 'http://127.0.0.1:8766/lakeside/portal/apply.html';
const chat = 'section[aria-label="Mentor"]';
const lastReply = `${chat} .space-y-7 > div:last-child`;

const results = [];
async function step(name, fn) {
    try {
        const detail = (await fn()) ?? {};
        const ok = detail.ok ?? true;
        results.push({ step: name, ok, ...detail });
        console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail.note ? ' — ' + detail.note : ''}`);
    } catch (error) {
        results.push({ step: name, ok: false, note: String(error?.message ?? error).slice(0, 400) });
        console.log(`FAIL  ${name} — ${String(error?.message ?? error).slice(0, 400)}`);
    }
}

const { browser, page: web, issues } = await launch({ extensions: true, protocolTimeout: 900000 });
await web.setViewport({ width: 1440, height: 900 });
await teeChat(web);

// Statuses the panel handles itself (410 after Stop, 409 wrong tab) are not errors.
const EXPECTED = [/status of (401|409|410|422)/, /ERR_CONNECTION_FAILED|ERR_FAILED/];
function watch(p, name) {
    p.on('console', (msg) => {
        if (msg.type() === 'error' && !EXPECTED.some((re) => re.test(msg.text())) && !/favicon\.ico$/.test(msg.location()?.url ?? '')) issues.push({ kind: `${name}:console`, url: p.url(), text: msg.text().slice(0, 300) });
    });
    p.on('pageerror', (err) => issues.push({ kind: `${name}:pageerror`, url: p.url(), text: String(err?.message ?? err).slice(0, 300) }));
}

/** Send a message in the mentor chat and wait for the whole reply (background turn) to finish. */
async function ask(message, timeout = 600000) {
    await web.bringToFront();
    const before = await web.evaluate(() => window.__chatStreams.length);
    await web.click(`${chat} textarea`);
    await web.type(`${chat} textarea`, message);
    await web.keyboard.press('Enter');
    await web.waitForFunction((n) => window.__chatStreams.length > n && window.__chatStreams.at(-1).done, { timeout, polling: 1000 }, before);
    await settle(web, 1200);

    return web.$eval(lastReply, (el) => el.innerText);
}

/** The tools the last reply used, in order (from the recorded stream). */
const toolsUsed = () =>
    web.evaluate(() =>
        (window.__chatStreams.at(-1)?.text ?? '')
            .split('\n\n')
            .map((chunk) => {
                try {
                    return JSON.parse(chunk.replace(/^data: /, ''));
                } catch {
                    return null;
                }
            })
            .filter((e) => e?.type === 'tool-input-available')
            .map((e) => e.toolName),
    );

const portalState = (p) =>
    p.evaluate(() => ({
        url: location.href,
        application: JSON.parse(localStorage.getItem('lakeside_portal_application') || '{}'),
        submitted: localStorage.getItem('lakeside_portal_submitted'),
        password: document.querySelector('input[type="password"]')?.value ?? null,
    }));

const ids = {};
let portal;
let panel;

await step('setup: link the extension and give the mentor the portal tab', async () => {
    ids.extension = await browser.installExtension(DIST);
    await login(web, 'synthetic.student@example.test');

    await web.goto(`${BASE}/settings/browser-extension`, { waitUntil: 'domcontentloaded' });
    await clickByText(web, 'button', 'Create a link code');
    await web.waitForSelector('[data-testid="link-code"] .font-mono', { timeout: 15000 });
    const code = await web.$eval('[data-testid="link-code"] .font-mono', (el) => el.textContent.trim());

    portal = await browser.newPage();
    watch(portal, 'portal');
    await portal.goto('http://127.0.0.1:8766/lakeside/portal/login.html', { waitUntil: 'load' });
    await portal.evaluate(() => localStorage.clear());

    panel = await browser.newPage({ type: 'window' });
    watch(panel, 'panel');
    await panel.goto(`chrome-extension://${ids.extension}/sidepanel/index.html`, { waitUntil: 'load' });
    ids.tab = await panel.evaluate(async () => (await chrome.tabs.query({})).find((t) => t.url?.includes('/lakeside/portal/'))?.id);
    await panel.goto(`chrome-extension://${ids.extension}/sidepanel/index.html?tab=${ids.tab}`, { waitUntil: 'load' });

    await panel.waitForSelector('input[name="server"]');
    await panel.type('input[name="server"]', BASE);
    await panel.type('input[name="code"]', code);
    await panel.click('input[name="device"]');
    await panel.keyboard.down('Control');
    await panel.keyboard.press('KeyA');
    await panel.keyboard.up('Control');
    await panel.keyboard.press('Backspace');
    await panel.type('input[name="device"]', 'E2E Chrome');
    await panel.click('button[type="submit"]');
    await waitForText(panel, 'Let the mentor use this browser', 30000);
    await clickByText(panel, 'button', 'Let the mentor use this browser');
    await waitForText(panel, 'Mentor control is on', 30000);
    await shot(panel, 'agent-1-panel-control-on');

    return { note: `extension ${ids.extension}; tab ${ids.tab}` };
});

await step('ask in the chat: the mentor opens the portal, finds the login and asks the student to log in (no password typed)', async () => {
    await web.bringToFront();
    await web.evaluate(() => {
        localStorage.removeItem('admitme.mentor.conversation');
        localStorage.setItem('admitme.mentor.open', '1');
        // Browser tools exist only in Agent mode; the chat remembers the mode per browser.
        localStorage.setItem('admitme.mentor.mode', 'agent');
    });
    await web.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
    await web.waitForSelector(`${chat} textarea`, { timeout: 30000 });
    await settle(web, 1500);
    await clickByText(web, '[aria-label="Mentor mode"] button', 'Agent');
    const mode = await web.evaluate(() => [...document.querySelectorAll('[aria-label="Mentor mode"] button')].find((b) => b.getAttribute('aria-pressed') === 'true' || b.dataset.state === 'on' || b.getAttribute('aria-checked') === 'true')?.innerText.trim() ?? '?');

    const reply = await ask(`Please fill in my Synthetic Lakeside University application for me in my browser. The portal is ${PORTAL}`);
    const tools = await toolsUsed();
    const state = await portalState(portal);
    await shot(web, 'agent-2-asks-to-log-in');
    await shot(panel, 'agent-2-panel');

    return {
        ok: tools.includes('BrowserOpenTool') && /log ?in|sign ?in/i.test(reply) && state.password === '' && state.url.includes('login.html'),
        note: `mode=${mode}; tools=${tools.join(',')}; portal at ${state.url.split('/').pop()}; password field empty=${state.password === ''}; reply="${reply.replace(/\s+/g, ' ').slice(0, 220)}"`,
    };
});

await step('the student logs in; the mentor fills every page it has answers for and stops before submitting', async () => {
    // The student signs in themselves (practice portal: any credentials work).
    await portal.bringToFront();
    await portal.type('#username', 'ada.synthetic');
    await portal.type('#password', 'practice-only');
    await portal.click('button[type="submit"]');
    await portal.waitForFunction(() => location.pathname.endsWith('apply.html'), { timeout: 20000 });
    await web.bringToFront();

    const reply = await ask("I'm logged in now. Go ahead and fill it in, but don't submit it.");
    const tools = await toolsUsed();
    const state = await portalState(portal);
    const app = state.application;
    await shot(portal, 'agent-3-portal');
    await shot(web, 'agent-3-chat');
    await shot(panel, 'agent-3-panel');

    const filled = {
        first: app.first_name === 'Ada',
        last: app.last_name === 'Synthetic',
        email: app.email === 'synthetic.student@example.test',
        country: app.country === 'Nigeria',
        curriculum: app.curriculum === 'A-Levels',
        major: /economics/i.test(app.major ?? ''),
    };

    return {
        ok: Object.values(filled).every(Boolean) && !state.submitted && tools.includes('BrowserFillTool'),
        note: `tools=${tools.join(',')}; saved=${JSON.stringify(app)}; checks=${JSON.stringify(filled)}; now on ${state.url.split('/').pop()}; submitted=${!!state.submitted}; reply="${reply.replace(/\s+/g, ' ').slice(0, 260)}"`,
    };
});

await step('asked to submit, the mentor clicks Submit; the panel asks the student; Deny → nothing is submitted', async () => {
    // Answer every question the panel asks with Deny while the mentor's reply runs (it may try the
    // certification box and then Submit); stop looking once the reply has ended.
    const asked = [];
    let replied = false;
    const denying = (async () => {
        const started = Date.now();
        while (Date.now() - started < 540000 && !replied) {
            const card = await panel.evaluate(() => {
                const button = [...document.querySelectorAll('button')].find((b) => b.innerText.trim() === 'Deny');

                return button ? (button.closest('.card, section, div')?.innerText ?? 'asked') : null;
            });
            if (card) {
                asked.push(card.replace(/\s+/g, ' ').slice(0, 120));
                if (asked.length === 1) await shot(panel, 'agent-4-panel-asks');
                await clickByText(panel, 'button', 'Deny');
                await new Promise((r) => setTimeout(r, 1500));
                continue;
            }
            await new Promise((r) => setTimeout(r, 700));
        }

        return asked.length > 0;
    })();

    const reply = await ask('Yes — tick the certification and submit the application now.').finally(() => {
        replied = true;
    });
    const denied = await denying;
    const state = await portalState(portal);
    await shot(web, 'agent-4-chat');

    return {
        ok: denied && !state.submitted && /declin|didn.t|not submitted|did not|deny|denied/i.test(reply),
        note: `panel asked ${asked.length}× (${asked.map((a) => `"${a}"`).join(' / ')}); submitted=${!!state.submitted}; reply="${reply.replace(/\s+/g, ' ').slice(0, 220)}"`,
    };
});

await step('Stop in the panel ends control at once; the mentor can no longer use the browser', async () => {
    await clickByText(panel, 'button', 'Stop');
    await waitForText(panel, 'Let the mentor use this browser', 20000);
    const reply = await ask('Read the portal page again please.', 300000);
    const tools = await toolsUsed();

    return {
        ok: /side panel|let the mentor use this browser|control/i.test(reply),
        note: `tools=${tools.join(',')}; reply="${reply.replace(/\s+/g, ' ').slice(0, 200)}"`,
    };
});

await step('no console or page errors', async () => ({ ok: issues.length === 0, note: issues.map((i) => `${i.kind}: ${i.text}`).join(' | ').slice(0, 400) }));

report('smoke-browser-agent', { results, issues });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
await browser.close();
