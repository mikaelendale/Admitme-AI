// End-to-end smoke for Phase 5 (knowledge base + source-backed research). Needs site-server.mjs on :8766.
import { clickByText, inertiaPage, launch, login, report, settle, shot, teeChat, visit } from './lib.mjs';

const results = [];
async function step(name, fn) {
    try {
        const detail = (await fn()) ?? {};
        const ok = detail.ok ?? true;
        results.push({ step: name, ok, ...detail });
        console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail.note ? ' — ' + detail.note : ''}`);
    } catch (error) {
        results.push({ step: name, ok: false, note: String(error?.message ?? error).slice(0, 300) });
        console.log(`FAIL  ${name} — ${String(error?.message ?? error).slice(0, 300)}`);
    }
}

const knowledge = (page, id) =>
    page.evaluate(async (schoolId) => (await fetch(`/college-lists/${schoolId}/knowledge`, { headers: { Accept: 'application/json' } })).json(), id);

const { browser, page, issues } = await launch();
// Mentor replies run as background turns; record each one in window.__chatStreams.
await teeChat(page);

// Capture chat streams (same technique as the Phase 1–3 smoke).
await page.evaluateOnNewDocument(() => {
    window.__chatStreams = [];
    const original = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const response = await original(...args);
        const url = typeof args[0] === 'string' ? args[0] : args[0]?.url ?? '';
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

            return new Response(forPage, { status: response.status, headers: response.headers });
        }

        return response;
    };
});

let schoolId = null;

await step('login and open a college', async () => {
    await login(page, 'synthetic.student@example.test');
    await visit(page, '/college-lists');
    schoolId = (await inertiaPage(page)).props.colleges.find((c) => c.name === 'Synthetic Lakeside University').id;
    await visit(page, `/college-lists/${schoolId}`);
    await page.waitForFunction(() => document.body.innerText.includes('Unresolved'), { timeout: 15000 });
    const data = await knowledge(page, schoolId);
    await shot(page, 'p5-before-sources');

    return { ok: data.requirements.length === 0 && data.unresolved.length > 0, note: data.unresolved.join(' | ').slice(0, 160) };
});

await step('add the official admissions page (operator-verified domain); requirements are extracted with cycle and source', async () => {
    await page.type('input[aria-label="Source link"]', 'http://127.0.0.1:8766/lakeside/apply.html');
    await clickByText(page, 'button', 'Add source');
    await page.waitForFunction(() => !document.body.innerText.includes('Reading page…'), { timeout: 240000 });
    await settle(page, 1000);
    let data = await knowledge(page, schoolId);
    // With a queue worker, requirements are extracted shortly after the page is saved (sync: already there).
    for (let i = 0; i < 90 && !data.requirements.some((r) => r.type === 'deadline'); i++) {
        await new Promise((r) => setTimeout(r, 2000));
        data = await knowledge(page, schoolId);
    }
    await shot(page, 'p5-after-official-source');
    const deadline = data.requirements.find((r) => r.type === 'deadline' && r.deadlineDate === '2027-01-01');
    const fee = data.requirements.find((r) => r.type === 'fee');

    return {
        ok: Boolean(deadline) && fee?.amount === '75.00' && fee?.currency === 'USD' && data.sources[0]?.type === 'official' && data.sources[0]?.private === false,
        note: data.requirements.map((r) => `${r.label}=${r.value} [${r.cycle ?? 'no cycle'}]`).join('; ').slice(0, 400),
    };
});

await step('ask the researcher: answer cites the official page with the cycle', async () => {
    await page.type('input[aria-label="Question about this college"]', 'When is the Regular Decision deadline and how much is the application fee?');
    await clickByText(page, 'button', 'Ask');
    await page.waitForFunction(() => !document.body.innerText.includes('Researching…'), { timeout: 240000 });
    await settle(page, 800);
    const data = await knowledge(page, schoolId);
    const answer = data.answers[0];
    await shot(page, 'p5-research-answer');
    const supported = answer.claims.filter((c) => c.status === 'supported' && c.citations.length > 0);
    const citesOfficial = supported.some((c) => c.citations.some((label) => answer.evidence[label]?.url?.includes('/lakeside/apply.html')));

    return { ok: supported.length > 0 && citesOfficial, note: `answer="${answer.answer.slice(0, 220)}" claims=${answer.claims.map((c) => c.status).join(',')}` };
});

await step('a disagreeing third-party page stays private to this student and is flagged as a conflict, not merged', async () => {
    await page.type('input[aria-label="Source link"]', 'http://localhost:8766/lakeside/guide.html');
    await clickByText(page, 'button', 'Add source');
    await page.waitForFunction(() => !document.body.innerText.includes('Reading page…'), { timeout: 240000 });
    await settle(page, 1000);
    let data = await knowledge(page, schoolId);
    // With a queue worker the page is read shortly after it is saved; the disagreement appears then.
    for (let i = 0; i < 90 && !data.unresolved.some((u) => u.startsWith('Sources disagree')); i++) {
        await new Promise((r) => setTimeout(r, 2000));
        data = await knowledge(page, schoolId);
    }
    await shot(page, 'p5-conflict');
    const disagreement = data.unresolved.find((u) => u.startsWith('Sources disagree'));
    const guide = data.sources.find((s) => s.url.includes('/lakeside/guide.html'));
    const onScreen = await page.evaluate(() => document.body.innerText.includes('only you see it'));

    return { ok: Boolean(disagreement) && guide?.private === true && guide?.type === 'student_provided' && onScreen, note: disagreement ?? data.unresolved.join(' | ') };
});

await step('another student with the same college sees the shared official facts but not the private page or its conflict', async () => {
    const context = await browser.createBrowserContext();
    const peer = await context.newPage();
    await peer.setViewport({ width: 1366, height: 900 });
    try {
        await login(peer, 'synthetic.peer@example.test');
        await visit(peer, '/college-lists');
        const peerSchoolId = (await inertiaPage(peer)).props.colleges.find((c) => c.name === 'Synthetic Lakeside University').id;
        await visit(peer, `/college-lists/${peerSchoolId}`);
        await settle(peer, 800);
        const data = await knowledge(peer, peerSchoolId);
        await shot(peer, 'p5-peer-view');
        const fee = data.requirements.find((r) => r.type === 'fee');
        const leaked = data.sources.some((s) => s.url.includes('guide.html')) || data.unresolved.some((u) => u.startsWith('Sources disagree'));

        return { ok: fee?.amount === '75.00' && !leaked && data.sources.length === 1, note: `peer sources=${data.sources.map((s) => s.url).join(',')}; unresolved=${data.unresolved.join(' | ').slice(0, 160)}` };
    } finally {
        await context.close();
    }
});

await step('mentor chat (Ask) answers from the stored requirements with a source', async () => {
    await visit(page, '/dashboard');
    const before = await page.evaluate(() => window.__chatStreams.length);
    await page.click('textarea');
    await page.type('textarea', 'What is the application fee at Synthetic Lakeside University, and where does that come from?');
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => window.__chatStreams.length > n && window.__chatStreams.at(-1).done, { timeout: 240000, polling: 500 }, before);
    const raw = await page.evaluate(() => window.__chatStreams.at(-1).text);
    await settle(page, 1200);
    await shot(page, 'p5-chat');
    const parts = raw.split('\n').filter((l) => l.startsWith('data: ') && !l.includes('[DONE]')).map((l) => { try { return JSON.parse(l.slice(6)); } catch { return null; } }).filter(Boolean);
    const text = parts.filter((p) => p.type === 'text-delta').map((p) => p.delta).join('');
    const tools = parts.filter((p) => p.type === 'tool-input-available').map((p) => p.toolName);

    return { ok: tools.includes('ReadCollegeRequirementsTool') && /75/.test(text), note: `tools=${tools.join(',')}; text="${text.slice(0, 200)}"` };
});

report('smoke-phase5', { results, issues });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
for (const issue of issues) console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
await browser.close();
