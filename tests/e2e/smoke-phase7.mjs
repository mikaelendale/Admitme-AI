// End-to-end smoke for Phase 7 (durable task orchestration) with a REAL queue worker and the real model.
// Needs: E2E_QUEUE=database for serve.sh AND worker.sh, site-server.mjs on :8766, a fresh DB (setup-db.sh).
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

function parseStream(raw) {
    const parts = raw.split('\n').filter((l) => l.startsWith('data: ') && !l.includes('[DONE]')).map((l) => { try { return JSON.parse(l.slice(6)); } catch { return null; } }).filter(Boolean);

    return {
        text: parts.filter((p) => p.type === 'text-delta').map((p) => p.delta).join(''),
        tools: parts.filter((p) => p.type === 'tool-input-available').map((p) => p.toolName),
        outputs: parts.filter((p) => p.type === 'tool-output-available').map((p) => (typeof p.output === 'string' ? p.output : JSON.stringify(p.output))),
        conversationId: parts.find((p) => p.type === 'data-conversation-id')?.data?.conversationId ?? null,
    };
}

async function sendChat(page, message, timeout = 240000) {
    const before = await page.evaluate(() => (window.__chatStreams ?? []).length);
    await page.click('textarea');
    await page.type('textarea', message);
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => (window.__chatStreams ?? []).length > n && window.__chatStreams.at(-1).done, { timeout, polling: 500 }, before);
    const raw = await page.evaluate(() => window.__chatStreams.at(-1).text);
    await settle(page, 1200);

    return parseStream(raw);
}

const json = (page, path, method = 'GET', body = null) =>
    page.evaluate(
        async (p, m, b) => {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const res = await fetch(p, {
                method: m,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: b ? JSON.stringify(b) : undefined,
            });

            return { status: res.status, body: await res.json().catch(() => null) };
        },
        path,
        method,
        body,
    );

async function waitForGoal(page, goalId, predicate, timeoutMs = 300000) {
    const started = Date.now();
    let goal = null;
    while (Date.now() - started < timeoutMs) {
        goal = (await json(page, `/agent-tasks/${goalId}`)).body?.goal ?? null;
        if (goal && predicate(goal)) return goal;
        await new Promise((r) => setTimeout(r, 3000));
    }
    throw new Error(`Timed out; last goal state: ${goal ? `${goal.status} [${goal.steps.map((s) => `${s.key}:${s.status}`).join(', ')}]` : 'none'}`);
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
let goalId = null;
let conversationId = null;
let proposal = null;

await step('a cost page for Cedar is read by the queue worker (real extraction)', async () => {
    await login(page, 'synthetic.student@example.test');
    await visit(page, '/college-lists');
    for (const c of (await inertiaPage(page)).props.colleges) ids[c.name] = c.id;
    const added = await json(page, `/college-lists/${ids['Synthetic Cedar College']}/sources`, 'POST', { url: 'http://127.0.0.1:8766/cedar/costs.html' });
    const started = Date.now();
    let knowledge = null;
    while (Date.now() - started < 180000) {
        knowledge = (await json(page, `/college-lists/${ids['Synthetic Cedar College']}/knowledge`)).body;
        if (knowledge?.requirements?.some((r) => r.type === 'cost')) break;
        await new Promise((r) => setTimeout(r, 2000));
    }
    const cost = knowledge?.requirements?.find((r) => r.type === 'cost');

    return { ok: added.status === 201 && cost?.amount === '22000.00', note: `source=${added.status}; requirements=${(knowledge?.requirements ?? []).map((r) => `${r.type}:${r.value}`).join('; ')}` };
});

await step('Agent mode: "research affordable options and prepare a shortlist" starts a visible plan', async () => {
    await visit(page, '/dashboard');
    await clickByText(page, '[aria-label="Mentor mode"] button', 'Agent');
    const reply = await sendChat(page, 'Research affordable options for me and prepare a shortlist.');
    conversationId = reply.conversationId;
    const started = reply.outputs.map((o) => { try { return JSON.parse(o); } catch { return null; } }).find((o) => o?.status === 'tasks_started');
    goalId = started?.goal_task_id ?? null;
    await page.waitForSelector(`[data-task-goal="${goalId}"]`, { timeout: 20000 }).catch(() => null);
    const cardVisible = Boolean(await page.$(`[data-task-goal="${goalId}"]`));
    await shot(page, 'p7-plan-started');

    return { ok: reply.tools.includes('PlanTasksTool') && Boolean(goalId) && cardVisible, note: `tools=${reply.tools.join(',')}; steps=${(started?.steps ?? []).map((s) => s.key + ':' + s.status).join(', ')}; text="${reply.text.slice(0, 220)}"` };
});

await step('the worker runs the steps to one sourced proposal that waits for approval', async () => {
    const goal = await waitForGoal(page, goalId, (g) => ['awaiting_approval', 'failed', 'blocked', 'completed', 'cancelled'].includes(g.status) && !g.active);
    const proposalStep = goal.steps.find((s) => s.type === 'propose_shortlist');
    proposal = proposalStep?.proposal ? (await json(page, `/approvals/${proposalStep.proposal.id}`)).body?.proposal : null;
    const cedar = proposal?.items.find((i) => i.label === 'Synthetic Cedar College');
    const sourced = cedar?.evidence?.some((e) => (e.url ?? '').includes('/cedar/costs.html'));
    await settle(page, 1500);
    await shot(page, 'p7-awaiting-approval');

    return {
        ok: goal.status === 'awaiting_approval' && proposalStep?.status === 'awaiting_approval' && Boolean(sourced),
        note: `goal=${goal.status}; steps=${goal.steps.map((s) => `${s.key ?? s.type}:${s.status}(${s.attempts})`).join(', ')}; proposed=${(proposal?.items ?? []).map((i) => i.label).join(', ')}; research="${(goal.steps.find((s) => s.type === 'college_research')?.summary ?? '').slice(0, 160)}"`,
    };
});

await step('refreshing the page shows the same plan and nothing ran twice', async () => {
    await visit(page, conversationId ? `/dashboard/chat/${conversationId}` : '/dashboard');
    await page.waitForSelector(`[data-task-goal="${goalId}"]`, { timeout: 20000 });
    const goal = (await json(page, `/agent-tasks/${goalId}`)).body.goal;
    const tasksPage = await (async () => {
        await visit(page, '/tasks');
        return (await inertiaPage(page)).props.goals;
    })();
    await shot(page, 'p7-tasks-page');
    const ranOnce = goal.steps.every((s) => s.attempts <= 1);

    return { ok: goal.status === 'awaiting_approval' && ranOnce && tasksPage.length === 1 && tasksPage[0].id === goalId, note: `attempts=${goal.steps.map((s) => s.attempts).join(',')}; plans on tasks page=${tasksPage.length}` };
});

await step('switching to Ask does not stop the plan, and the mentor reports it', async () => {
    await visit(page, conversationId ? `/dashboard/chat/${conversationId}` : '/dashboard');
    await clickByText(page, '[aria-label="Mentor mode"] button', 'Ask');
    const reply = await sendChat(page, 'How is my shortlist task going? Is anything waiting for me?');
    const goal = (await json(page, `/agent-tasks/${goalId}`)).body.goal;
    await shot(page, 'p7-ask-mode');

    return { ok: goal.status === 'awaiting_approval' && /approv/i.test(reply.text), note: `tools=${reply.tools.join(',') || 'none (context section)'}; goal=${goal.status}; text="${reply.text.slice(0, 220)}"` };
});

await step('approve part of the proposal in the task card: only the approved colleges are saved', async () => {
    await visit(page, conversationId ? `/dashboard/chat/${conversationId}` : '/dashboard');
    await page.waitForSelector(`[data-task-goal="${goalId}"] [aria-label^="Include "]`, { timeout: 20000 });
    const labels = proposal.items.map((i) => i.label);
    const dropped = labels.find((l) => l !== 'Synthetic Cedar College') ?? null;
    if (dropped) await page.click(`[data-task-goal="${goalId}"] [aria-label="Include ${dropped}"]`);
    await settle(page, 400);
    await page.evaluate((gid) => {
        const card = document.querySelector(`[data-task-goal="${gid}"]`);
        [...card.querySelectorAll('button')].find((b) => /^Approve/.test(b.innerText.trim()))?.click();
    }, goalId);
    const goal = await waitForGoal(page, goalId, (g) => g.status === 'completed', 60000);
    await settle(page, 1500);
    await shot(page, 'p7-approved');
    await visit(page, '/college-lists');
    const status = Object.fromEntries((await inertiaPage(page)).props.colleges.map((c) => [c.name, c.choiceStatus]));
    const approvedOk = status['Synthetic Cedar College'] === 'shortlisted' && (!dropped || status[dropped] === 'suggested');

    return { ok: goal.status === 'completed' && approvedOk, note: `kept out=${dropped}; statuses=${JSON.stringify(status).slice(0, 300)}; proposal step="${goal.steps.find((s) => s.type === 'propose_shortlist')?.summary}"` };
});

await step('a plan started from the task panel can be cancelled; nothing after the cancel is saved', async () => {
    await visit(page, '/tasks');
    await page.type('input[aria-label="What to look for"]', 'small colleges with strong mathematics');
    await clickByText(page, 'button', 'Start');
    await page.waitForFunction(() => document.querySelectorAll('[data-task-goal]').length >= 2, { timeout: 20000 });
    const newest = await page.evaluate(() => document.querySelector('[data-task-goal]')?.getAttribute('data-task-goal'));
    await page.evaluate((gid) => [...document.querySelector(`[data-task-goal="${gid}"]`).querySelectorAll('button')].find((b) => b.innerText.includes('Cancel'))?.click(), newest);
    await settle(page, 300);
    await page.evaluate((gid) => [...document.querySelector(`[data-task-goal="${gid}"]`).querySelectorAll('button')].find((b) => b.innerText.includes('Yes, cancel'))?.click(), newest);
    const goal = await waitForGoal(page, newest, (g) => g.status === 'cancelled' && g.steps.every((s) => ['cancelled', 'completed', 'failed'].includes(s.status)), 240000);
    await shot(page, 'p7-cancelled');
    // Contract: nothing keeps running, whatever had not finished is cancelled, finished work is kept, and a
    // proposal from a cancelled step stays pending in Approvals (it changes nothing unless approved).
    const cancelled = goal.steps.filter((s) => s.status === 'cancelled');
    const proposalStep = goal.steps.find((s) => s.type === 'propose_shortlist');
    const proposalStillPending = !proposalStep?.proposal || proposalStep.proposal.status === 'pending';

    return {
        ok: goal.status === 'cancelled' && cancelled.length > 0 && goal.steps.every((s) => s.status === 'cancelled' || s.status === 'completed') && proposalStillPending,
        note: `steps=${goal.steps.map((s) => `${s.key}:${s.status}`).join(', ')}; proposal=${proposalStep?.proposal?.status ?? 'none'}; cancelled reasons=${cancelled.map((s) => s.statusReason).join(' | ')}`,
    };
});

await step('cancelling while research is in flight stops it at its checkpoint and saves nothing from it', async () => {
    await visit(page, '/college-lists');
    const before = (await inertiaPage(page)).props.colleges.length;
    const started = await json(page, '/agent-tasks/playbooks/affordable-shortlist', 'POST', { criteria: 'colleges in Canada with co-op programmes', idempotency_key: `cancel-${Date.now()}` });
    const id = started.body.goal.id;
    // Give the worker a moment to claim the research step, then cancel while its model call is in flight.
    await new Promise((r) => setTimeout(r, 1500));
    const cancelled = await json(page, `/agent-tasks/${id}/cancel`, 'POST');
    const goal = await waitForGoal(page, id, (g) => g.steps.every((s) => ['cancelled', 'completed', 'failed'].includes(s.status)), 240000);
    await visit(page, '/college-lists');
    const after = (await inertiaPage(page)).props.colleges.length;
    const research = goal.steps.find((s) => s.type === 'college_research');

    return {
        ok: cancelled.status === 200 && goal.status === 'cancelled' && research.status === 'cancelled' && after === before,
        note: `research=${research.status} ("${research.statusReason}"); colleges before/after=${before}/${after}; steps=${goal.steps.map((s) => `${s.key}:${s.status}`).join(', ')}`,
    };
});

report('smoke-phase7', { results, issues });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
for (const issue of issues) console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
await browser.close();
