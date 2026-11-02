// Browser smoke for background mentor replies (real model, e2e database): while a specialist works, other pages
// still load quickly; a full page refresh mid-reply brings the reply back and it keeps going; once finished, the
// reply — the specialist's card, its searches and its report — is stored and comes back on every reload.
// Run against the e2e server with its queue workers (see README): node smoke-mentor-turns.mjs
import {
    clickByText,
    launch,
    login,
    report,
    settle,
    shot,
    teeChat,
    BASE,
} from './lib.mjs';

const results = [];
const panel = 'section[aria-label="Mentor"]';
const lastReply = `${panel} .space-y-7 > div:last-child`;

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

const researcherCard = () =>
    page.evaluate((sel) => {
        const button = [...document.querySelectorAll(`${sel} button`)].find(
            (b) => b.innerText.includes('Researcher'),
        );

        return button ? button.innerText.replace(/\s+/g, ' ').trim() : null;
    }, lastReply);

const { browser, page, issues } = await launch();
await page.setViewport({ width: 1440, height: 900 });
await teeChat(page);

const QUESTION =
    'Use the researcher specialist to find the Fall 2027 Early Action and Regular Decision deadlines for Purdue University and the University of Georgia from official pages, then tell me what that means for me in two lines.';

await step('start a reply that hands work to the Researcher', async () => {
    await login(page, 'synthetic.student@example.test');
    await page.evaluate(() => {
        localStorage.removeItem('admitme.mentor.conversation');
        localStorage.setItem('admitme.mentor.open', '1');
    });
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`${panel} textarea`, { timeout: 30000 });
    await settle(page, 800);
    await clickByText(page, '[aria-label="Mentor mode"] button', 'Agent');
    await page.click(`${panel} textarea`);
    await page.type(`${panel} textarea`, QUESTION);
    await page.keyboard.press('Enter');
    await page.waitForFunction(
        (sel) =>
            [...document.querySelectorAll(`${sel} button`)].some(
                (b) =>
                    b.innerText.includes('Researcher') &&
                    b.innerText.includes('working'),
            ),
        { timeout: 120000, polling: 300 },
        lastReply,
    );
    await shot(page, 'turns-1-researcher-working');

    return { ok: true, note: (await researcherCard()) ?? '' };
});

await step('other pages load while the mentor works', async () => {
    const started = Date.now();
    await clickByText(page, 'a', 'College lists');
    await page.waitForFunction(
        () =>
            location.pathname === '/college-lists' &&
            document.querySelector('h1')?.innerText.includes('College lists'),
        { timeout: 20000 },
    );
    const ms = Date.now() - started;
    const stillWorking = await page.evaluate(
        (sel) => document.querySelector(sel)?.innerText.includes('working'),
        lastReply,
    );

    return {
        ok: ms < 6000,
        note: `navigated in ${ms}ms; reply still working=${stillWorking}`,
    };
});

await step(
    'a full refresh mid-reply brings the reply back and it keeps going',
    async () => {
        await page.reload({ waitUntil: 'domcontentloaded' });
        // The question reappears with the reply in progress (restored from the server, not from memory).
        await page.waitForFunction(
            (q) =>
                document
                    .querySelector('section[aria-label="Mentor"]')
                    ?.innerText.includes(q.slice(0, 60)),
            { timeout: 30000, polling: 300 },
            QUESTION,
        );
        // The reply's parts are read back from the server right after; the specialist's card is among the first.
        const restored = await page
            .waitForFunction(
                (sel) => {
                    const button = [
                        ...document.querySelectorAll(`${sel} button`),
                    ].find((b) => b.innerText.includes('Researcher'));

                    return button
                        ? button.innerText.replace(/\s+/g, ' ').trim()
                        : null;
                },
                { timeout: 30000, polling: 300 },
                lastReply,
            )
            .then((handle) => handle.jsonValue())
            .catch(() => null);
        await shot(page, 'turns-2-restored');
        // Wait for the reply to finish after the refresh.
        await page.waitForFunction(
            () => window.__chatStreams.some((e) => e.done),
            { timeout: 300000, polling: 1000 },
        );
        await settle(page, 1500);
        const finished = await researcherCard();
        const reply = await page.$eval(lastReply, (el) => el.innerText);
        await shot(page, 'turns-3-finished');

        return {
            ok:
                restored !== null &&
                finished !== null &&
                finished.includes('done') &&
                reply.length > 200,
            note: `restored="${(restored ?? '').slice(0, 60)}" finished="${(finished ?? '').slice(0, 80)}"`,
        };
    },
);

await step(
    'the finished reply is stored: the specialist card, its searches and report come back on reload',
    async () => {
        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForFunction(
            () =>
                [
                    ...document.querySelectorAll(
                        'section[aria-label="Mentor"] button',
                    ),
                ].some(
                    (b) =>
                        b.innerText.includes('Researcher') &&
                        b.innerText.includes('done'),
                ),
            { timeout: 30000, polling: 300 },
        );
        const card = await researcherCard();
        const opened = await page.evaluate((sel) => {
            const button = [...document.querySelectorAll(`${sel} button`)].find(
                (b) => b.innerText.includes('Researcher'),
            );
            button?.click();

            return !!button;
        }, lastReply);
        await settle(page, 400);
        const activity = await page.$$eval(
            `${lastReply} [aria-label="Specialist activity"] li`,
            (items) =>
                items.map((li) => li.innerText.replace(/\s+/g, ' ').trim()),
        );
        await shot(page, 'turns-4-reloaded');

        return {
            ok: !!card && opened,
            note: `card="${(card ?? '').slice(0, 80)}" activity=${activity.length}: ${activity.slice(0, 2).join(' | ').slice(0, 120)}`,
        };
    },
);

await step('no console or server errors', async () => ({
    ok: issues.length === 0,
    note: issues
        .map((i) => i.text)
        .join(' | ')
        .slice(0, 300),
}));

const passed = results.filter((r) => r.ok).length;
console.log(
    `\n${passed}/${results.length} steps passed; ${issues.length} browser issue(s).`,
);
report('smoke-mentor-turns', { results, issues });
await browser.close();
process.exit(results.every((r) => r.ok) ? 0 : 1);
