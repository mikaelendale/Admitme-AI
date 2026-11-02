// Browser smoke for the mentor reply layout (real model): text streams in as it is written, tools show inline
// where they ran, an approval card appears as soon as its tool returns (before the reply finishes), a specialist
// (sub-agent) shows its work live in its own card, a reopened chat keeps that order without fetching the card,
// and the docked panel resizes (drag, keyboard, double-click reset) and remembers its width.
// Run against the e2e server (see README): node smoke-mentor-inline.mjs
import { clickByText, launch, login, report, settle, shot, teeChat, BASE } from './lib.mjs';

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

const panel = 'section[aria-label="Mentor"]';
const separator = '[role="separator"][aria-label="Resize mentor panel"]';

const { browser, page, issues } = await launch();
// Mentor replies run as background turns; record each one in window.__chatStreams.
await teeChat(page);
await page.setViewport({ width: 1440, height: 900 });

const approvalRequests = [];
page.on('request', (req) => {
    if (/\/approvals\/[0-9A-Za-z]+$/.test(new URL(req.url()).pathname)) {
        approvalRequests.push(req.url());
    }
});

// Tee the chat stream, and note when the first tool row and the approval card appear in the panel.
await page.evaluateOnNewDocument(() => {
    window.__chatStreams = [];
    window.__marks = {};
    const original = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url ?? '');
        const response = await original(...args);
        if (/\/dashboard\/chat(\?|$)/.test(url) && response.body) {
            const [forPage, forTest] = response.body.tee();
            const entry = { url, done: false, doneAt: null, text: '' };
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
                entry.doneAt = performance.now();
            })();

            return new Response(forPage, { status: response.status, statusText: response.statusText, headers: response.headers });
        }

        return response;
    };
    window.__textLengths = [];
    window.__agentOutputs = [];
    new MutationObserver(() => {
        const root = document.querySelector('section[aria-label="Mentor"]');
        if (!root) return;
        window.__marks.tool ??= root.querySelector('ul[aria-label="Steps"] li') ? performance.now() : undefined;
        window.__marks.card ??= root.innerText.includes('Waiting for your approval') ? performance.now() : undefined;
        // How the last reply's visible text grows (streaming shows many sizes, not one jump at the end).
        const reply = root.querySelector('.space-y-7 > div:last-child');
        const length = reply ? reply.innerText.length : 0;
        if (window.__textLengths.at(-1) !== length) window.__textLengths.push(length);
        // A specialist's live output while it is still working.
        const working = [...root.querySelectorAll('.space-y-7 > div:last-child button[disabled]')].find((b) => b.innerText.includes('working'));
        const live = working?.parentElement?.querySelector(':scope > div');
        if (live && window.__agentOutputs.at(-1) !== live.innerText.length) window.__agentOutputs.push(live.innerText.length);
    }).observe(document, { childList: true, subtree: true, characterData: true });
});

const panelWidth = () => page.$eval('aside[aria-label="Mentor"]', (el) => Math.round(el.getBoundingClientRect().width));

await step('open the docked mentor in Agent mode on a fresh chat', async () => {
    await login(page, EMAIL);
    await page.evaluate(() => {
        localStorage.removeItem('admitme.mentor.conversation');
        localStorage.removeItem('admitme.mentor.width');
        localStorage.setItem('admitme.mentor.open', '1');
    });
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`${panel} textarea`, { timeout: 20000 });
    await settle(page, 800);
    const clicked = await clickByText(page, '[aria-label="Mentor mode"] button', 'Agent');
    await shot(page, 'mi-1-agent');

    return { ok: clicked, note: `width=${await panelWidth()}` };
});

let stream = null;
await step('a proposal turn: tool rows inline, the card shows before the reply ends, text keeps its place', async () => {
    await page.click(`${panel} textarea`);
    await page.type(`${panel} textarea`, 'Please propose a profile change: set my intended major to Computer Science. Create the proposal, then tell me in one short sentence.');
    await page.keyboard.press('Enter');
    await page.waitForFunction(() => window.__chatStreams.length > 0 && window.__chatStreams.at(-1).done, { timeout: 240000, polling: 500 });
    await settle(page, 1200);
    stream = await page.evaluate(() => ({ ...window.__chatStreams.at(-1), marks: window.__marks }));
    await shot(page, 'mi-2-proposal');

    const types = stream.text
        .split('\n')
        .filter((l) => l.startsWith('data: ') && !l.includes('[DONE]'))
        .map((l) => {
            try {
                return JSON.parse(l.slice(6)).type;
            } catch {
                return null;
            }
        });
    // Order inside the last reply: what kind of block comes first, second, ...
    const order = await page.$$eval(`${panel} .space-y-7 > div:last-child > *`, (nodes) =>
        nodes.map((n) => (n.querySelector('ul[aria-label="Steps"]') || /^Used \d+ tools/.test(n.innerText) ? 'tools' : n.innerText.includes('Waiting for your approval') ? 'card' : n.innerText.trim() ? 'text' : 'other')),
    );
    const { tool, card } = stream.marks;
    const cardBeforeEnd = card !== undefined && card < stream.doneAt;
    const toolsBeforeCard = order.indexOf('tools') !== -1 && order.indexOf('tools') < order.indexOf('card');

    return {
        ok: types.includes('data-proposal') && cardBeforeEnd && toolsBeforeCard && approvalRequests.length === 0,
        note: `order=${order.join('>')} tool@${Math.round(tool ?? -1)} card@${Math.round(card ?? -1)} end@${Math.round(stream.doneAt)} approvalFetches=${approvalRequests.length} parts=${[...new Set(types)].join(',')}`,
    };
});

await step('text streams in as it is written (many visible sizes before the end)', async () => {
    const lengths = await page.evaluate(() => window.__textLengths);
    const deltas = (stream?.text.match(/"type":"text-delta"/g) ?? []).length;

    return { ok: lengths.length >= 5 && deltas >= 3, note: `visible sizes=${lengths.length} text-deltas=${deltas}` };
});

let research = null;
await step('a specialist works in its own card: the Researcher streams its findings, then folds to a summary', async () => {
    await page.evaluate(() => {
        window.__agentOutputs = [];
    });
    const before = await page.evaluate(() => window.__chatStreams.length);
    await page.click(`${panel} textarea`);
    await page.type(
        `${panel} textarea`,
        'Use the researcher specialist to find the Fall 2027 Early Action and Regular Decision deadlines for Purdue University and the University of Georgia from official pages. Then answer in two short lines.',
    );
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => window.__chatStreams.length > n && window.__chatStreams.at(-1).done, { timeout: 300000, polling: 500 }, before);
    await settle(page, 1500);
    research = await page.evaluate(() => window.__chatStreams.at(-1).text);
    const card = await page.$$eval(`${panel} .space-y-7 > div:last-child button`, (buttons) => buttons.map((b) => b.innerText).find((t) => t.includes('Researcher')) ?? null);
    const live = await page.evaluate(() => window.__agentOutputs);
    const preliminary = (research.match(/"preliminary":true/g) ?? []).length;
    await shot(page, 'mi-3-researcher');

    return {
        ok: !!card && card.includes('done') && research.includes('"toolName":"researcher"') && preliminary > 0 && live.length >= 2,
        note: `card=${(card ?? '').replace(/\s+/g, ' ').slice(0, 140)} preliminary=${preliminary} liveSizes=${live.length}`,
    };
});

await step('the folded Researcher card opens to its full report', async () => {
    const opened = await page.evaluate(() => {
        const button = [...document.querySelectorAll('section[aria-label="Mentor"] .space-y-7 > div:last-child button')].find((b) => b.innerText.includes('Researcher'));
        if (!button) return null;
        button.click();

        return true;
    });
    await settle(page, 400);
    const expanded = await page.$$eval(`${panel} .space-y-7 > div:last-child button[aria-expanded="true"]`, (b) => b.length);
    await shot(page, 'mi-4-researcher-open');

    return { ok: opened === true && expanded >= 1, note: `expanded=${expanded}` };
});

await step('reopening the chat keeps the order and shows the card without fetching it', async () => {
    approvalRequests.length = 0;
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction((sel) => document.querySelector(sel)?.innerText.includes('Waiting for your approval'), { timeout: 30000 }, panel);
    await settle(page, 800);
    const researcherBack = await page.$$eval(`${panel} .space-y-7 > div:last-child button`, (buttons) => buttons.some((b) => b.innerText.includes('Researcher') && b.innerText.includes('done')));
    const order = await page.$$eval(`${panel} .space-y-7 > div:nth-last-child(3) > *`, (nodes) =>
        nodes.map((n) => (n.querySelector('ul[aria-label="Steps"]') || /^Used \d+ tools/.test(n.innerText) ? 'tools' : n.innerText.includes('Waiting for your approval') ? 'card' : n.innerText.trim() ? 'text' : 'other')),
    );
    await shot(page, 'mi-5-reopened');

    return {
        ok: researcherBack && order.includes('tools') && order.indexOf('tools') < order.indexOf('card') && approvalRequests.length === 0,
        note: `researcher=${researcherBack} order=${order.join('>')} approvalFetches=${approvalRequests.length}`,
    };
});

await step('drag the panel edge to resize; the width is remembered after a reload', async () => {
    const before = await panelWidth();
    const box = await (await page.$(separator)).boundingBox();
    const x = box.x + box.width / 2;
    const y = box.y + box.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x - 60, y, { steps: 4 });
    await page.mouse.move(x - 140, y, { steps: 4 });
    await page.mouse.up();
    await settle(page, 300);
    const after = await panelWidth();
    await shot(page, 'mi-6-resized');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(separator, { timeout: 20000 });
    await settle(page, 800);
    const reloaded = await panelWidth();

    return { ok: Math.abs(after - before - 140) <= 4 && Math.abs(reloaded - after) <= 2, note: `before=${before} after=${after} reloaded=${reloaded}` };
});

await step('keyboard resizes the focused handle; double-click resets to the default', async () => {
    const start = await panelWidth();
    await page.focus(separator);
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    const narrower = await panelWidth();
    const box = await (await page.$(separator)).boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    await new Promise((resolve) => setTimeout(resolve, 120));
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    await settle(page, 300);
    const reset = await panelWidth();
    const stored = await page.evaluate(() => localStorage.getItem('admitme.mentor.width'));

    return { ok: start - narrower === 48 && reset === 400 && stored === null, note: `start=${start} narrower=${narrower} reset=${reset} stored=${stored}` };
});

await step('no console errors or server errors', async () => ({ ok: issues.length === 0, note: issues.map((i) => `${i.kind}: ${i.text}`).join(' | ').slice(0, 300) }));

const passed = results.filter((r) => r.ok).length;
console.log(`\n${passed}/${results.length} steps passed; ${issues.length} browser issue(s).`);
report('smoke-mentor-inline', { results, issues, stream: stream ? { length: stream.text.length } : null });
await browser.close();
process.exit(results.every((r) => r.ok) ? 0 : 1);
