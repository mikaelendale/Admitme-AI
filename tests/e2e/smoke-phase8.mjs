// End-to-end smoke for Phase 8 (writing coach, policies, budget re-evaluation, attributable runs, form plan).
// Needs: E2E_QUEUE=database for serve.sh AND worker.sh, site-server.mjs on :8766, a fresh DB (setup-db.sh).
import { clickByText, inertiaPage, launch, login, report, settle, shot, visit } from './lib.mjs';

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

async function props(page, path) {
    const version = (await inertiaPage(page))?.version ?? '';

    return page.evaluate(async (p, v) => (await (await fetch(p, { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': v, Accept: 'text/html, application/xhtml+xml' } })).json()).props, path, version);
}

async function waitForGoal(page, goalId, predicate, timeoutMs = 300000) {
    const started = Date.now();
    let goal = null;
    while (Date.now() - started < timeoutMs) {
        goal = (await json(page, `/agent-tasks/${goalId}`)).body?.goal ?? null;
        if (goal && predicate(goal)) return goal;
        await new Promise((r) => setTimeout(r, 3000));
    }
    throw new Error(`Timed out; last: ${goal ? `${goal.status} [${goal.steps.map((s) => `${s.key}:${s.status}`).join(', ')}]` : 'none'}`);
}

const DRAFT = `Debate taught me to listen before I speak. In my first tournament I has argued so fast that I missed my opponent's best point, and we lost. Afterwards our coach asked me to repeat her argument back to her, word for word, and I could not. Since then I take notes on every speech before I answer it. At Lakeside I want to study economics because good policy also starts with listening to the people it affects.`;

const { browser, page, issues } = await launch();
const ids = {};

await step('writing: a draft is saved as an immutable version written by the student', async () => {
    await login(page, 'synthetic.student@example.test');
    await visit(page, '/college-lists');
    for (const c of (await inertiaPage(page)).props.colleges) ids[c.name] = c.id;
    await visit(page, '/writing');
    await page.type('#piece-title', 'Why Lakeside');
    await page.select('#piece-college', ids['Synthetic Lakeside University']);
    await page.type('#piece-prompt', 'Why do you want to study at Synthetic Lakeside University? (150 words)');
    await page.type('#piece-limit', '150');
    await page.select('#piece-kind', 'supplement');
    await clickByText(page, 'button', 'Create');
    await page.waitForFunction(() => location.pathname.startsWith('/writing/') && document.querySelector('textarea[aria-label="Your draft"]'), { timeout: 20000 });
    ids.piece = await page.evaluate(() => location.pathname.split('/').pop());
    await page.type('textarea[aria-label="Your draft"]', DRAFT);
    const counted = await page.$eval('[data-testid="word-count"]', (el) => el.innerText);
    await clickByText(page, 'button', 'Save version');
    await page.waitForFunction(() => document.body.innerText.includes('Version 1 ·'), { timeout: 20000 });
    const p = await props(page, `/writing/${ids.piece}`);
    await shot(page, 'p8-writing-saved');

    return { ok: p.versions.length === 1 && p.versions[0].body === DRAFT && p.versions[0].author === 'student' && p.piece.policy.value === 'feedback_only', note: `count shown "${counted}"; policy=${p.piece.policy.label}` };
});

await step('writing: coach feedback (real model) quotes the student\'s own words and changes nothing', async () => {
    await clickByText(page, 'button', 'Get feedback on version 1');
    await page.waitForFunction(() => document.body.innerText.includes('Feedback on version 1'), { timeout: 180000 });
    await settle(page, 800);
    const p = await props(page, `/writing/${ids.piece}`);
    const fb = p.feedback[0];
    const normalize = (s) => s.toLowerCase().replace(/[’‘]/g, "'").replace(/\s+/g, ' ');
    const allQuoted = fb.comments.every((c) => normalize(DRAFT).includes(normalize(c.quote)));
    const noRewrites = fb.comments.every((c) => c.fix === null);
    await shot(page, 'p8-writing-feedback');

    return {
        ok: fb.comments.length > 0 && allQuoted && noRewrites && p.versions.length === 1 && p.versions[0].body === DRAFT,
        note: `${fb.comments.length} comments (dropped ${fb.droppedComments}); first: "${fb.comments[0]?.quote}" → ${fb.comments[0]?.comment?.slice(0, 120)}`,
    };
});

await step('writing: a college that asks for no AI help gets no coach at all', async () => {
    await visit(page, '/writing');
    await page.type('#piece-title', 'Why Northfield');
    await page.select('#piece-college', ids['Synthetic Northfield College']);
    await clickByText(page, 'button', 'Create');
    await page.waitForFunction(() => location.pathname.startsWith('/writing/') && document.querySelector('textarea[aria-label="Your draft"]'), { timeout: 20000 });
    const pieceId = await page.evaluate(() => location.pathname.split('/').pop());
    await page.type('textarea[aria-label="Your draft"]', 'My own essay for Northfield.');
    await clickByText(page, 'button', 'Save version');
    await page.waitForFunction(() => document.body.innerText.includes('Version 1 ·'), { timeout: 20000 });
    const disabled = await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.innerText.includes('Get feedback'))?.disabled ?? false);
    const banner = await page.evaluate(() => document.body.innerText.includes('No AI assistance'));
    const forced = await page.evaluate(async (id) => {
        const p = JSON.parse(document.querySelector('script[data-page="app"]')?.textContent ?? '{}').props;
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const res = await fetch(`/writing/${id}/feedback`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ version_id: p.versions?.[0]?.id ?? p.piece?.current?.id }) });

        return res.status;
    }, pieceId);
    await shot(page, 'p8-no-ai-policy');

    return { ok: disabled && banner && forced === 422, note: `button disabled=${disabled}; banner=${banner}; forced request → HTTP ${forced}` };
});

let goalId = null;
let firstProposal = null;

await step('a shortlist plan runs on the worker; every AI result names the agent and model that produced it', async () => {
    await visit(page, '/tasks');
    const started = await json(page, '/agent-tasks/playbooks/affordable-shortlist', 'POST', { criteria: 'economics with strong aid for international students', idempotency_key: `p8-${Date.now()}` });
    goalId = started.body.goal.id;
    const goal = await waitForGoal(page, goalId, (g) => ['awaiting_approval', 'completed', 'failed', 'blocked'].includes(g.status) && !g.active);
    const research = goal.steps.find((s) => s.type === 'college_research');
    firstProposal = goal.steps.find((s) => s.type === 'propose_shortlist')?.proposal ?? null;

    return {
        ok: goal.status === 'awaiting_approval' && research.runs?.[0]?.agent === 'CollegeResearchAgent' && Boolean(research.runs?.[0]?.model) && Boolean(firstProposal),
        note: `research runs=${JSON.stringify(research.runs)}; proposal=${firstProposal?.title}`,
    };
});

await step('lowering the budget re-evaluates the pending shortlist: the old proposal goes stale and a new one replaces it', async () => {
    const before = (await json(page, `/approvals/${firstProposal.id}`)).body.proposal;
    await visit(page, '/profile-setup');
    const changed = await page.evaluate(async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const res = await fetch('/profile-setup/budget', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'text/html', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ budget_amount: 20000, budget_currency: 'USD', financial_aid_need_percent: 90 }),
        });

        return res.status;
    });
    const goal = await waitForGoal(page, goalId, (g) => {
        const proposal = g.steps.find((s) => s.type === 'propose_shortlist');

        return proposal?.status === 'awaiting_approval' && proposal.proposal && proposal.proposal.id !== firstProposal.id;
    }, 180000);
    const proposalStep = goal.steps.find((s) => s.type === 'propose_shortlist');
    const after = (await json(page, `/approvals/${firstProposal.id}`)).body.proposal;
    const fresh = (await json(page, `/approvals/${proposalStep.proposal.id}`)).body.proposal;
    await visit(page, '/tasks');
    await shot(page, 'p8-reevaluated');

    return {
        ok: [200, 302].includes(changed) && before.status === 'pending' && after.status === 'stale' && fresh.status === 'pending',
        note: `old proposal ${before.status} → ${after.status}; new proposal items=${fresh.items.map((i) => i.label).join(', ')}; step events=${proposalStep.events.map((e) => e.type).join(',')}`,
    };
});

await step('the application form plan (real model) labels every field: saved data, checks, AI judgments', async () => {
    await json(page, `/college-lists/${ids['Synthetic Lakeside University']}`, 'PATCH', { choice_status: 'applying' });
    await visit(page, '/college-lists');
    const lakeside = (await inertiaPage(page)).props.colleges.find((c) => c.name === 'Synthetic Lakeside University');
    await visit(page, `/applications/${lakeside.applicationId}`);
    await clickByText(page, 'button', 'Prepare the form plan');
    await page.waitForFunction(() => document.querySelectorAll('[data-basis]').length > 10, { timeout: 240000, polling: 2000 });
    await settle(page, 800);
    const workspace = (await props(page, `/applications/${lakeside.applicationId}`)).workspace;
    const entries = workspace.formPlan.plan.entries;
    const bases = [...new Set(entries.map((e) => e.basis))];
    const judgment = entries.find((e) => e.basis === 'model_judgment');
    await shot(page, 'p8-form-plan');

    return {
        ok: bases.includes('profile') && bases.includes('validation') && bases.includes('model_judgment') && entries.find((e) => e.field === 'Legal name')?.basis === 'profile',
        note: `bases=${bases.join(',')}; judgment="${judgment?.value?.slice(0, 120)}" (${judgment?.note?.slice(0, 80)})`,
    };
});

report('smoke-phase8', { results, issues });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
for (const issue of issues.slice(0, 8)) console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
await browser.close();
