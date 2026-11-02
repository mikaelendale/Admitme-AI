// End-to-end smoke for Phase 4 (documents + guided onboarding). Run after setup-db.sh: node smoke-phase4.mjs
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { clickByText, inertiaPage, launch, login, report, settle, shot, visit } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const FILES = path.join(here, 'files');
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

async function freshProps(page) {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await settle(page, 400);

    return (await inertiaPage(page)).props;
}

async function upload(page, file, kind = 'transcript') {
    await visit(page, '/documents');
    const input = await page.$('#document-file');
    await input.uploadFile(path.join(FILES, file));
    await page.select('#document-kind', kind);
    await clickByText(page, 'form button[type="submit"]', 'Upload');
    await page.waitForFunction(() => /\/documents\/[0-9a-z]{26}$/i.test(location.pathname), { timeout: 180000 });
    await settle(page, 800);
    await waitProcessed(page);
}

// With the sync queue the file is read during the upload request; with a queue worker it is read shortly
// after. Either way, wait until the current version is no longer queued or being read.
async function waitProcessed(page, timeoutMs = 180000) {
    const started = Date.now();
    while (Date.now() - started < timeoutMs) {
        const props = await freshProps(page);
        const current = props.versions.find((v) => v.id === props.document?.currentVersionId) ?? props.versions[0];
        if (current && !['queued', 'processing'].includes(current.status)) return props;
        await new Promise((r) => setTimeout(r, 2000));
    }
    throw new Error('The document was still being read after 3 minutes');
}

const { browser, page, issues } = await launch();

await step('login', async () => {
    await login(page, 'synthetic.student@example.test');
});

await step('guided setup: save "situation" and resume at the next step after reload', async () => {
    await visit(page, '/profile-setup');
    await shot(page, 'p4-setup-start');
    await clickByText(page, 'button[role="radio"]', 'On a gap year');
    await clickByText(page, 'button[role="radio"]', 'Yes');
    await page.click('#field-target_intake', { clickCount: 3 });
    await page.type('#field-target_intake', 'Fall 2027');
    await clickByText(page, 'form button[type="submit"]', 'Save and continue');
    await page.waitForFunction(() => location.search.includes('section=academics'), { timeout: 15000 });
    await page.goto(`${new URL(page.url()).origin}/profile-setup`, { waitUntil: 'domcontentloaded' });
    await settle(page);
    const props = (await inertiaPage(page)).props;
    await shot(page, 'p4-setup-resumed');

    return { ok: props.current === 'academics' && props.sections[0].completed === true, note: `resumed at ${props.current}` };
});

await step('guided setup: "don\'t know" for SAT is accepted', async () => {
    await visit(page, '/profile-setup?section=academics');
    // The checkboxes are visually replaced by drawn boxes; click the inputs themselves.
    await page.$eval('[aria-label="SAT score: I don\'t know / not applicable"]', (el) => el.click());
    await page.$eval('[aria-label="ACT score: I don\'t know / not applicable"]', (el) => el.click());
    await clickByText(page, 'form button[type="submit"]', 'Save and continue');
    await page.waitForFunction(() => location.search.includes('section=interests'), { timeout: 15000 });
    await visit(page, '/profile-setup?section=academics');
    const fields = (await inertiaPage(page)).props.sections.find((s) => s.key === 'academics').fields;
    const sat = fields.find((f) => f.key === 'sat_score');

    return { ok: sat.unknown === true, note: `sat unknown=${sat.unknown}` };
});

let documentId = null;
await step('documents: a text transcript is read and its values are listed with page quotes', async () => {
    await upload(page, 'transcript-v1.pdf');
    documentId = new URL(page.url()).pathname.split('/').pop();
    const props = await freshProps(page);
    await shot(page, 'p4-document-review');
    const values = props.values.map((v) => `${v.field}=${v.value}${v.status === 'conflict' ? '(conflict)' : ''}@p${v.page}`);
    const allQuoted = props.values.every((v) => v.quote && v.page);
    const hasGpa = props.values.some((v) => v.field === 'gpa_value' && v.value.startsWith('3.85'));

    return { ok: props.versions[0].status === 'needs_review' && allQuoted && hasGpa, note: values.join(', ') };
});

await step('documents: accepting the GPA saves it to the profile', async () => {
    const props = await freshProps(page);
    const gpa = props.values.find((v) => v.field === 'gpa_value');
    const cards = await page.$$('li');
    for (const card of cards) {
        const text = await card.evaluate((el) => el.textContent ?? '');
        if (text.includes('GPA / grade average') && text.includes('Accept')) {
            const buttons = await card.$$('button');
            for (const button of buttons) {
                if ((await button.evaluate((el) => el.textContent?.trim())) === 'Accept') {
                    await button.click();
                    break;
                }
            }
            break;
        }
    }
    await settle(page, 1500);
    const after = await freshProps(page);
    await shot(page, 'p4-document-accepted');
    await visit(page, '/profile-overview');
    const profile = (await inertiaPage(page)).props.profile;

    return {
        ok: after.values.find((v) => v.id === gpa.id)?.reviewState === 'accepted' && String(profile.academics.gpa_value).startsWith('3.85'),
        note: `profile gpa=${profile.academics.gpa_value}`,
    };
});

await step('documents: a scanned image fails visibly with a manual-entry route', async () => {
    await upload(page, 'scan.png');
    const text = await page.evaluate(() => document.body.innerText);
    await shot(page, 'p4-scan-failed');

    return { ok: /could not be read/i.test(text) && /Enter values manually/.test(text), note: text.match(/This is an image[^.]*\./)?.[0] ?? '' };
});

await step('documents: replacing the transcript keeps history and flags a conflict', async () => {
    await visit(page, `/documents/${documentId}`);
    const input = await page.$('input[aria-label="Replacement file"]');
    await input.uploadFile(path.join(FILES, 'transcript-v2.pdf'));
    await clickByText(page, 'form button[type="submit"]', 'Upload a new version');
    await settle(page, 2000);
    await page.waitForFunction(() => document.body.innerText.includes('Replaced by a newer version'), { timeout: 180000 });
    const props = await waitProcessed(page);
    await shot(page, 'p4-document-replaced');
    const gpa = props.values.find((v) => v.field === 'gpa_value' && v.versionId === props.document.currentVersionId);

    return {
        ok: props.versions.length === 2 && props.versions.some((v) => v.status === 'superseded') && gpa?.status === 'conflict',
        note: `versions=${props.versions.map((v) => `v${v.version}:${v.status}`).join(',')}; new gpa=${gpa?.value} ${gpa?.status}`,
    };
});

await step('tasks page shows the document jobs', async () => {
    await visit(page, '/tasks');
    const tasks = (await inertiaPage(page)).props.tasks;
    await shot(page, 'p4-tasks');

    return { ok: tasks.some((t) => t.type === 'document_processing' && t.status === 'failed') && tasks.some((t) => t.type === 'document_processing' && t.status === 'completed'), note: `${tasks.length} task(s)` };
});

report('smoke-phase4', { results, issues });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
for (const issue of issues) console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
await browser.close();
