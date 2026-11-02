// Browser smoke for the rebuilt first-run onboarding (build plan Phase 4 order) as a fresh synthetic student.
// Prerequisites: e2e server + queue worker running (see README) and `php ui-fixtures.php <app>` to reset the student.
// Run: node smoke-onboarding.mjs
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { clickByText, launch, livePage as inertiaPage, login, report, settle, shot, BASE } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const EMAIL = 'fresh.student@example.test';
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

const current = async (page) => (await inertiaPage(page))?.props?.current;

async function typeInto(page, selector, value) {
    await page.click(selector, { clickCount: 3 });
    await page.keyboard.press('Backspace');
    await page.type(selector, value);
}

async function chip(page, key, value) {
    await page.type(`#field-${key}`, value);
    await page.keyboard.press('Enter');
}

async function submitStep(page) {
    await clickByText(page, 'button[type="submit"]', 'Save and continue');
    await settle(page, 900);
}

const { browser, page, issues } = await launch();
await page.setViewport({ width: 1440, height: 900 });

await step('fresh student is sent to onboarding and must consent first', async () => {
    await login(page, EMAIL);
    await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    const url = new URL(page.url());
    await shot(page, 'ob-1-consent');
    const props = (await inertiaPage(page))?.props;

    return { ok: url.pathname === '/onboarding' && props?.current === 'consent' && props.steps.length >= 8, note: `${url.pathname} current=${props?.current} steps=${props?.steps?.map((s) => s.key).join(',')}` };
});

await step('steps cannot be skipped before consent', async () => {
    await page.goto(`${BASE}/onboarding?step=budget`, { waitUntil: 'domcontentloaded' });
    await settle(page);

    return { ok: (await current(page)) === 'consent' };
});

await step('consent: age range + terms', async () => {
    await clickByText(page, 'button[role="radio"]', '17–18');
    // The terms row contains links; tick its checkbox itself.
    await page.evaluate(() => {
        const label = [...document.querySelectorAll('label')].find((l) => l.textContent?.includes('I am 13 or older'));
        label?.querySelector('input[type="checkbox"]')?.click();
    });
    await clickByText(page, 'button[type="submit"]', 'Agree and start');
    await settle(page, 900);
    await shot(page, 'ob-2-situation');

    return { ok: (await current(page)) === 'situation' };
});

await step('situation & intake saves and moves on', async () => {
    await clickByText(page, 'button[role="radio"]', 'Finishing high school');
    await clickByText(page, 'button[role="radio"]', 'Yes');
    await typeInto(page, '#field-citizenship_residency', 'Ghanaian citizen');
    await typeInto(page, '#field-location_country', 'Ghana');
    await typeInto(page, '#field-location_region', 'Greater Accra');
    await typeInto(page, '#field-graduation_year', '2027');
    await clickByText(page, 'button', 'Fall 2027');
    await submitStep(page);
    await shot(page, 'ob-3-academics');

    return { ok: (await current(page)) === 'academics' };
});

await step('curriculum & grades keeps the original scale; unknown test scores are allowed', async () => {
    await clickByText(page, 'button', 'WAEC');
    await typeInto(page, '#field-current_grade', 'SHS 3');
    await typeInto(page, '#field-gpa_value', '3.6');
    await typeInto(page, '#field-gpa_scale', '4');
    await clickByText(page, 'button[role="radio"]', 'Unweighted');
    // SAT / ACT: "don't know"
    await page.evaluate(() => {
        for (const key of ['sat_score', 'act_score']) {
            const field = document.getElementById(`field-${key}`)?.closest('div.flex.flex-col');
            field?.querySelector('input[type="checkbox"]')?.click();
        }
    });
    await typeInto(page, '#field-test_optional_status', 'Not testing');
    await submitStep(page);

    return { ok: (await current(page)) === 'interests' };
});

await step('interests use chips', async () => {
    await chip(page, 'intended_majors', 'Economics');
    await clickByText(page, 'button', 'Computer Science');
    await shot(page, 'ob-4-interests');
    await submitStep(page);

    return { ok: (await current(page)) === 'destinations' };
});

await step('destinations', async () => {
    await clickByText(page, 'button', 'United States');
    await clickByText(page, 'button', 'Canada');
    await chip(page, 'region_preferences', 'Northeast US');
    await clickByText(page, 'button[role="radio"]', 'Medium');
    await shot(page, 'ob-5-destinations');
    await submitStep(page);

    return { ok: (await current(page)) === 'budget' };
});

await step('budget keeps the student currency', async () => {
    await typeInto(page, '#field-budget_amount', '20000');
    await clickByText(page, 'button', 'USD');
    await clickByText(page, 'button', '75%');
    await shot(page, 'ob-6-budget');
    await submitStep(page);
    const props = (await inertiaPage(page))?.props;

    return { ok: props?.current === 'documents', note: `research ready=${props?.readiness?.college_research?.ready}` };
});

await step('optional document upload goes through the document pipeline', async () => {
    const input = await page.$('input[type="file"]');
    await input.uploadFile(path.join(here, 'files', 'transcript-v1.pdf'));
    await settle(page, 1500);
    let props = (await inertiaPage(page))?.props;
    const uploaded = props?.documents?.length === 1;
    // The queue worker reads it in the background; wait until it settles.
    for (let i = 0; i < 40 && props?.documents?.some((d) => d.status === 'queued' || d.status === 'processing'); i++) {
        await new Promise((r) => setTimeout(r, 3000));
        props = (await inertiaPage(page))?.props;
    }
    await shot(page, 'ob-7-documents');
    const doc = props?.documents?.[0];

    return { ok: uploaded && doc && doc.status !== 'queued' && doc.status !== 'processing', note: `status=${doc?.status} pendingValues=${doc?.pendingValues}` };
});

await step('continue to review', async () => {
    await clickByText(page, 'button', 'Continue');
    await settle(page, 900);
    await shot(page, 'ob-8-review');
    const props = (await inertiaPage(page))?.props;

    return { ok: props?.current === 'review', note: `missing=${JSON.stringify(props?.missing)} ready=${Object.values(props?.readiness ?? {}).map((r) => r.ready).join(',')}` };
});

await step('reload resumes on the same step', async () => {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await settle(page);

    return { ok: (await current(page)) === 'review' };
});

await step('review shows saved answers in plain words', async () => {
    const text = await page.evaluate(() => document.body.innerText);

    return { ok: text.includes('Ghanaian citizen') && text.includes('Finishing high school') && text.includes("Don't know") && text.includes('USD'), note: '' };
});

await step('finish lands on the dashboard with the profile saved', async () => {
    const finish = await clickByText(page, 'button', 'Finish setup');
    if (!finish) {
        await clickByText(page, 'a', 'Continue');
        await settle(page, 900);
        await clickByText(page, 'button', 'Skip for now');
    }
    await page.waitForFunction(() => location.pathname === '/dashboard', { timeout: 30000 });
    await settle(page, 1200);
    await shot(page, 'ob-9-dashboard');
    const props = (await inertiaPage(page))?.props;

    return { ok: props?.profile_completion?.completeness_score > 0, note: `completeness=${props?.profile_completion?.completeness_score}` };
});

await step('no console errors or server errors', async () => ({ ok: issues.length === 0, note: issues.map((i) => i.text).join(' | ').slice(0, 300) }));

await browser.close();
report('smoke-onboarding', results);
const passed = results.filter((r) => r.ok).length;
console.log(`\n${passed}/${results.length} steps passed; ${issues.length} browser issue(s).`);
process.exit(passed === results.length ? 0 : 1);
