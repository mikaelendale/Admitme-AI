// Browser smoke for the mentor side panel (real model, real queue worker): ask from any page, keep the
// conversation while navigating, restore it after a reload, attach a document, history and new chat.
// Run against the e2e server (see README): node smoke-mentor-panel.mjs
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { clickByText, launch, livePage, login, report, settle, shot, teeChat, BASE } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
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
        errors: parts.filter((p) => p.type === 'error').map((p) => p.errorText),
        conversationId: parts.find((p) => p.type === 'data-conversation-id')?.data?.conversationId ?? null,
        request: null,
    };
}

const panel = 'section[aria-label="Mentor"]';

async function ask(page, message, timeout = 180000) {
    const before = await page.evaluate(() => (window.__chatStreams ?? []).length);
    await page.click(`${panel} textarea`);
    await page.type(`${panel} textarea`, message);
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => (window.__chatStreams ?? []).length > n && window.__chatStreams.at(-1).done, { timeout, polling: 500 }, before);
    const entry = await page.evaluate(() => window.__chatStreams.at(-1));
    await settle(page, 1500);

    return { ...parseStream(entry.text), request: entry.body };
}

const { browser, page, issues } = await launch();
// Mentor replies run as background turns; record each one in window.__chatStreams.
await teeChat(page);
await page.setViewport({ width: 1440, height: 900 });

// Tee the chat stream and remember what was sent.
await page.evaluateOnNewDocument(() => {
    window.__chatStreams = [];
    const original = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url ?? '');
        const response = await original(...args);
        if (/\/dashboard\/chat(\?|$)/.test(url) && response.body) {
            const [forPage, forTest] = response.body.tee();
            const entry = { url, done: false, text: '', body: typeof args[1]?.body === 'string' ? args[1].body : null };
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

await step('panel is docked and open on a wide screen, on a page other than Home', async () => {
    await login(page, EMAIL);
    await page.evaluate(() => {
        localStorage.removeItem('admitme.mentor.conversation');
        localStorage.removeItem('admitme.mentor.open');
    });
    await page.goto(`${BASE}/college-lists`, { waitUntil: 'domcontentloaded' });
    await settle(page, 1200);
    const open = await page.$(`${panel} textarea`);
    await shot(page, 'mp-1-docked');

    return { ok: !!open };
});

let first;
await step('ask from College lists: answer streams with its steps', async () => {
    first = await ask(page, 'Which colleges on my list have deadlines in the next 120 days? Keep it short.');
    await shot(page, 'mp-2-answer');
    const steps = await page.$$eval(`${panel} ul[aria-label="Steps"] li`, (items) => items.map((li) => li.textContent?.trim()));

    return { ok: first.text.length > 40 && first.errors.length === 0 && !!first.conversationId, note: `tools=${first.tools.join(',')} steps=${steps.join(' | ').slice(0, 160)}` };
});

await step('conversation stays when moving to another page', async () => {
    // Sidebar links carry an aria-label only while the sidebar is collapsed; match the link itself.
    await clickByText(page, 'a[href="/applications"]', 'Applications');
    await page.waitForFunction(() => location.pathname === '/applications', { timeout: 20000 });
    await settle(page, 800);
    const text = await page.$eval(panel, (el) => el.innerText);

    return { ok: text.includes('deadlines in the next 120 days'), note: '' };
});

await step('reload restores the last conversation in the panel', async () => {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction((sel) => document.querySelector(sel)?.innerText.includes('deadlines in the next 120 days'), { timeout: 20000 }, panel);

    return { ok: true };
});

let attachedId = null;
await step('attach a document from the composer: uploads through Documents and is read', async () => {
    const input = await page.$(`${panel} input[type="file"]`);
    await input.uploadFile(path.join(here, 'files', 'transcript-v2.pdf'));
    await page.waitForFunction((sel) => document.querySelector(`${sel} [aria-label="Attached files"] li`) !== null, { timeout: 30000 }, panel);
    // Wait until the background reader finishes (the chip loses its spinner).
    await page.waitForFunction((sel) => !document.querySelector(`${sel} [aria-label="Attached files"] [aria-label="Still being read"]`), { timeout: 120000, polling: 1000 }, panel);
    await shot(page, 'mp-3-attached');
    attachedId = await page.evaluate(async () => {
        const res = await fetch('/documents/attachable', { headers: { Accept: 'application/json' } });
        const json = await res.json();

        return json.documents.find((d) => d.name === 'transcript-v2.pdf')?.id ?? null;
    });

    return { ok: !!attachedId };
});

await step('the mentor reads the attached file for that message', async () => {
    const reply = await ask(page, 'What grade average does the attached transcript show? Quote the number.');
    const body = JSON.parse(reply.request ?? '{}');
    const bubble = await page.$$eval(`${panel} [aria-label="Attached files"]`, (lists) => lists.map((l) => l.textContent));
    await shot(page, 'mp-4-attachment-answer');

    return { ok: (body.document_ids ?? []).includes(attachedId) && /3\.9|3,9/.test(reply.text), note: `answer=${reply.text.slice(0, 160).replace(/\n/g, ' ')} chips=${bubble.join('|')}` };
});

await step('history lists the conversation; new chat starts empty', async () => {
    await page.click(`${panel} button[aria-label="Chat history"]`);
    await settle(page, 800);
    const items = await page.$$eval('[role="menuitem"]', (els) => els.map((e) => e.textContent?.trim()));
    await page.keyboard.press('Escape');
    await page.click(`${panel} button[aria-label="New chat"]`);
    await settle(page, 400);
    const empty = await page.$eval(panel, (el) => el.innerText.includes('How can I help'));
    await shot(page, 'mp-5-new-chat');

    return { ok: items.length >= 2 && empty, note: items.slice(0, 3).join(' | ').slice(0, 160) };
});

await step('hide and show the panel', async () => {
    await page.click('button[aria-label="Hide mentor"]');
    await settle(page, 300);
    const hidden = (await page.$(panel)) === null;
    await page.click('button[aria-label="Open mentor"]');
    await settle(page, 300);
    const shown = (await page.$(panel)) !== null;

    return { ok: hidden && shown };
});

await step('laptop width: the panel is an overlay that closes on navigation', async () => {
    await page.setViewport({ width: 1180, height: 760 });
    await page.goto(`${BASE}/documents`, { waitUntil: 'domcontentloaded' });
    await settle(page, 800);
    const closedAtStart = (await page.$(panel)) === null;
    await page.click('button[aria-label="Open mentor"]');
    await settle(page, 500);
    const opened = (await page.$(panel)) !== null;
    await shot(page, 'mp-6-overlay');
    await page.keyboard.press('Escape');
    await settle(page, 400);

    return { ok: closedAtStart && opened && (await page.$(panel)) === null };
});

await step('phone width: the panel covers the screen', async () => {
    await page.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
    await settle(page, 800);
    await page.click('button[aria-label="Open mentor"]');
    await settle(page, 500);
    const box = await page.$eval(panel, (el) => el.getBoundingClientRect().width);
    await shot(page, 'mp-7-phone');

    return { ok: box >= 380, note: `width=${box}` };
});

await step('no console errors or server errors', async () => ({ ok: issues.length === 0, note: issues.map((i) => i.text).join(' | ').slice(0, 300) }));

await browser.close();
report('smoke-mentor-panel', results);
const passed = results.filter((r) => r.ok).length;
console.log(`\n${passed}/${results.length} steps passed; ${issues.length} browser issue(s).`);
process.exit(passed === results.length ? 0 : 1);
