// End-to-end smoke for Phase 6 (discovery, suggestions review, compare, application workspace).
// Needs site-server.mjs on :8766 and a fresh e2e DB (setup-db.sh). Uses the real configured model for extraction and chat.
import { clickByText, inertiaPage, launch, login, report, settle, shot, teeChat, visit } from './lib.mjs';

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

/** Fresh page props via an Inertia JSON request (the DOM copy goes stale after client-side reloads). */
async function props(page, path) {
    const version = (await inertiaPage(page))?.version ?? '';

    return page.evaluate(
        async (p, v) => {
            const res = await fetch(p, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': v, Accept: 'text/html, application/xhtml+xml' } });

            return (await res.json()).props;
        },
        path,
        version,
    );
}

async function addSource(page, schoolId, url) {
    return page.evaluate(
        async (id, u) => {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const res = await fetch(`/college-lists/${id}/sources`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ url: u }),
            });

            return { status: res.status, body: await res.json() };
        },
        schoolId,
        url,
    );
}

/** Click a button (by visible text) inside the checklist item whose text contains `match`. */
async function clickInItem(page, match, buttonText) {
    const clicked = await page.evaluate(
        (m, b) => {
            const item = [...document.querySelectorAll('li[id^="item-"]')].find((li) => li.innerText.includes(m));
            const button = item ? [...item.querySelectorAll('button')].find((el) => el.innerText.trim().includes(b)) : null;
            button?.click();

            return Boolean(button);
        },
        match,
        buttonText,
    );
    if (!clicked) throw new Error(`No "${buttonText}" button in the item matching "${match}"`);
    await settle(page, 900);
}

const { browser, page, issues } = await launch();
// Mentor replies run as background turns; record each one in window.__chatStreams.
await teeChat(page);

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

const ids = {};
let applicationId = null;

await step('suggestions from a research run are listed separately, each with reasons and their basis', async () => {
    await login(page, 'synthetic.student@example.test');
    await visit(page, '/college-lists');
    await page.waitForFunction(() => document.body.innerText.includes('Suggestions to review'), { timeout: 15000 });
    const colleges = (await inertiaPage(page)).props.colleges;
    for (const c of colleges) ids[c.name] = c.id;
    const cedar = colleges.find((c) => c.name === 'Synthetic Cedar College');
    const text = await page.evaluate(() => document.querySelector('[aria-labelledby="suggestions-heading"]')?.innerText ?? '');
    await shot(page, 'p6-suggestions');
    const interests = cedar.assessment.reasons.find((r) => r.kind === 'interests');

    return {
        ok: colleges.filter((c) => c.choiceStatus === 'suggested').length === 3 && text.includes('AI research estimate — not verified') && interests?.status === 'good' && cedar.assessment.affordability.status === 'incomplete',
        note: `cedar reasons: ${cedar.assessment.reasons.map((r) => `${r.status}:${r.text}`).join(' | ').slice(0, 260)}`,
    };
});

await step('approve two of three suggestions, then dismiss the third', async () => {
    await page.click('[aria-label="Select Synthetic Cedar College"]');
    await page.click('[aria-label="Select Synthetic Summit University"]');
    await clickByText(page, 'button', 'to my shortlist');
    await page.waitForFunction(() => document.body.innerText.includes('Added 2 to your shortlist'), { timeout: 15000 });
    await settle(page, 800);
    await page.click('[aria-label="Select Synthetic Maple Institute"]');
    await clickByText(page, 'button', 'Dismiss 1');
    await page.waitForFunction(() => !document.body.innerText.includes('Suggestions to review'), { timeout: 15000 });
    await settle(page, 800);
    const colleges = (await props(page, '/college-lists')).colleges;
    const status = Object.fromEntries(colleges.map((c) => [c.name, c.choiceStatus]));
    await shot(page, 'p6-after-review');

    return {
        ok: status['Synthetic Cedar College'] === 'shortlisted' && status['Synthetic Summit University'] === 'shortlisted' && !('Synthetic Maple Institute' in status),
        note: JSON.stringify(status),
    };
});

await step('filter the saved list by status', async () => {
    await page.click('[aria-label="Filter by list status"]');
    await settle(page, 300);
    await clickByText(page, '[role="option"]', 'Applying');
    await settle(page, 500);
    // The first column is the compare checkbox; the school name is in the second.
    const rows = await page.evaluate(() => [...document.querySelectorAll('table tbody tr')].map((tr) => tr.querySelector('td:nth-child(2)')?.innerText.split('\n')[0] ?? ''));
    await shot(page, 'p6-filter-applying');
    await page.click('[aria-label="Filter by list status"]');
    await settle(page, 300);
    await clickByText(page, '[role="option"]', 'All saved');
    await settle(page, 300);

    return { ok: rows.length === 1 && rows[0].includes('Harbor State'), note: rows.join(', ') };
});

await step('sourced cost and aid pages make Lakeside "needs aid" against the student budget (real extraction)', async () => {
    const apply = await addSource(page, ids['Synthetic Lakeside University'], 'http://127.0.0.1:8766/lakeside/apply.html');
    const costs = await addSource(page, ids['Synthetic Lakeside University'], 'http://127.0.0.1:8766/lakeside/costs.html');
    let school = (await props(page, `/college-lists/${ids['Synthetic Lakeside University']}`)).school;
    // With a queue worker, requirements are extracted shortly after each page is saved (sync: already there).
    for (let i = 0; i < 90 && school.assessment.affordability.status !== 'needs_aid'; i++) {
        await new Promise((r) => setTimeout(r, 2000));
        school = (await props(page, `/college-lists/${ids['Synthetic Lakeside University']}`)).school;
    }
    const types = (costs.body.requirements ?? []).map((r) => `${r.type}:${r.value}`).join('; ');

    return {
        ok: apply.status === 201 && costs.status === 201 && school.assessment.affordability.status === 'needs_aid' && !school.assessment.affordability.complete,
        note: `${school.assessment.affordability.summary} || requirements: ${types.slice(0, 260)}`,
    };
});

await step('compare three saved colleges side by side', async () => {
    await visit(page, '/college-lists');
    for (const name of ['Synthetic Lakeside University', 'Synthetic Cedar College', 'Synthetic Harbor State University']) {
        await page.click(`[aria-label="Compare ${name}"]`);
    }
    await clickByText(page, 'button', 'Compare (3)');
    await page.waitForFunction(() => location.pathname === '/college-lists/compare', { timeout: 15000 });
    await settle(page, 800);
    const headers = await page.evaluate(() => [...document.querySelectorAll('thead th')].map((th) => th.innerText.trim()).filter((t) => t && t !== 'Attribute'));
    const lakesideAffordability = await page.evaluate(() => document.body.innerText.includes('Needs aid'));
    await shot(page, 'p6-compare');

    return { ok: headers.length === 3 && lakesideAffordability, note: headers.join(' | ') };
});

await step('start the Lakeside application: the workspace shows real requirements, sources, deadline and next actions', async () => {
    await visit(page, `/college-lists/${ids['Synthetic Lakeside University']}`);
    await clickByText(page, 'button', 'Start application');
    await page.waitForFunction(() => [...document.querySelectorAll('a')].some((a) => a.innerText.includes('Open application')), { timeout: 20000 });
    await clickByText(page, 'a', 'Open application');
    await page.waitForFunction(() => location.pathname.startsWith('/applications/'), { timeout: 20000 });
    await settle(page, 1000);
    applicationId = await page.evaluate(() => location.pathname.split('/').pop());
    const workspace = (await props(page, `/applications/${applicationId}`)).workspace;
    await shot(page, 'p6-workspace');
    const sourced = workspace.checklist.filter((i) => i.source);
    const titles = workspace.checklist.map((i) => `${i.owner === 'third_party' ? '[3P] ' : ''}${i.title}`);
    const fee = workspace.checklist.find((i) => i.kind === 'fee');

    return {
        ok: workspace.deadline?.fromSource === true && sourced.length >= 4 && fee?.title.includes('75') && workspace.checklist.some((i) => i.owner === 'third_party' && i.kind === 'recommendation') && workspace.nextActions.length > 0,
        note: `deadline=${workspace.deadline?.label} (${workspace.deadline?.basis}); items=${titles.join(' | ').slice(0, 420)}`,
    };
});

await step('third-party work: "I asked" is not completion; "portal shows it received" is', async () => {
    await clickInItem(page, 'Done by: Teacher', 'I’ve asked them');
    let workspace = (await props(page, `/applications/${applicationId}`)).workspace;
    const recommendation = () => workspace.checklist.find((i) => i.kind === 'recommendation');
    const afterAsk = { status: recommendation().status, complete: recommendation().complete, received: workspace.progress.othersReceived, requested: workspace.progress.othersRequested };
    await shot(page, 'p6-requested');

    await clickInItem(page, 'Done by: Teacher', 'Portal shows it received');
    workspace = (await props(page, `/applications/${applicationId}`)).workspace;
    const afterReceived = { status: recommendation().status, basis: recommendation().completionBasis, received: workspace.progress.othersReceived };

    await clickInItem(page, 'Pay the', 'I did this');
    workspace = (await props(page, `/applications/${applicationId}`)).workspace;
    const fee = workspace.checklist.find((i) => i.kind === 'fee');
    await shot(page, 'p6-progress');

    return {
        ok: afterAsk.status === 'requested' && !afterAsk.complete && afterAsk.received === 0 && afterAsk.requested === 1 && afterReceived.status === 'received' && afterReceived.received === 1 && fee.completionBasis === 'Marked done by you',
        note: `after ask ${JSON.stringify(afterAsk)}; after receipt ${JSON.stringify(afterReceived)}; fee "${fee.statusLabel}"`,
    };
});

await step('the mentor (Ask) reads the same workspace and gives the same next steps', async () => {
    const workspace = (await props(page, `/applications/${applicationId}`)).workspace;
    await visit(page, '/dashboard');
    const before = await page.evaluate(() => window.__chatStreams.length);
    await page.click('textarea');
    await page.type('textarea', 'What are my next steps for my Synthetic Lakeside University application? Use my application checklist.');
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => window.__chatStreams.length > n && window.__chatStreams.at(-1).done, { timeout: 240000, polling: 500 }, before);
    const raw = await page.evaluate(() => window.__chatStreams.at(-1).text);
    await settle(page, 1200);
    await shot(page, 'p6-chat');
    const parts = raw.split('\n').filter((l) => l.startsWith('data: ') && !l.includes('[DONE]')).map((l) => { try { return JSON.parse(l.slice(6)); } catch { return null; } }).filter(Boolean);
    const text = parts.filter((p) => p.type === 'text-delta').map((p) => p.delta).join('');
    const tools = parts.filter((p) => p.type === 'tool-input-available').map((p) => p.toolName);
    // Every open next action names a step; at least two of them should be recognisable in the reply.
    const keywords = workspace.nextActions.map((a) => (a.text.match(/transcript|English|TOEFL|essay|deadline|portal|application form|account|SAT|counselor|CSS/i) ?? [null])[0]).filter(Boolean);
    const mentioned = [...new Set(keywords)].filter((k) => text.toLowerCase().includes(k.toLowerCase()));
    const claimsRecommendationPending = /recommendation[^.]*(still|waiting|pending|not yet)/i.test(text);

    return {
        ok: tools.includes('ReadApplicationTool') && mentioned.length >= 2 && !claimsRecommendationPending,
        note: `tools=${tools.join(',')}; matched=${mentioned.join(',')}; screen next=${workspace.nextActions.map((a) => a.text).join(' | ').slice(0, 200)}; reply="${text.slice(0, 260)}"`,
    };
});

report('smoke-phase6', { results, issues });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
for (const issue of issues) console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
await browser.close();
