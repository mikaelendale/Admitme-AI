// End-to-end smoke for Phase 9 (exports, expiring owner-only downloads, deletion across derived stores).
// Needs E2E_QUEUE=database for serve.sh and worker.sh and a fresh DB. Never deletes accounts (shared storage folder).
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { clickByText, inertiaPage, launch, login, report, settle, shot, visit } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('bash', [path.join(here, 'e2e-php.sh'), ...args], { encoding: 'utf8' }).trim().split('\n').pop());

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

const { browser, page, issues } = await launch();
const ids = {};
let exportRow = null;

await step('a document and a writing piece exist to export', async () => {
    await login(page, 'synthetic.student@example.test');
    await visit(page, '/documents');
    ids.document = await page.evaluate(async () => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const form = new FormData();
        form.append('kind', 'transcript');
        form.append('title', 'E2E transcript');
        form.append('file', new File(['Synthetic transcript\nMathematics: A*\nEconomics: A\nGraduation: 2027\n'], 'transcript.txt', { type: 'text/plain' }));
        const res = await fetch('/documents', { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': token, Accept: 'text/html' }, redirect: 'follow' });

        return res.url.split('/documents/')[1]?.split(/[?#]/)[0] ?? null;
    });
    await visit(page, '/writing');
    await page.type('#piece-title', 'Personal statement');
    await clickByText(page, 'button', 'Create');
    await page.waitForFunction(() => document.querySelector('textarea[aria-label="Your draft"]'), { timeout: 20000 });
    ids.piece = await page.evaluate(() => location.pathname.split('/').pop());
    await page.type('textarea[aria-label="Your draft"]', 'Version one of my statement, in my own words.');
    await clickByText(page, 'button', 'Save version');
    await page.waitForFunction(() => document.body.innerText.includes('Version 1 ·'), { timeout: 20000 });

    return { ok: Boolean(ids.document) && Boolean(ids.piece), note: `document=${ids.document}; piece=${ids.piece}` };
});

await step('an export is built by the worker and reconciles with its snapshot (versions + checksums)', async () => {
    await visit(page, '/exports');
    await page.click('[aria-label="Include Writing"]');
    await page.click('[aria-label="Include Documents"]');
    await page.click('[aria-label="Include document E2E transcript"]');
    await page.click('[aria-label="Include writing Personal statement"]');
    await clickByText(page, 'button', 'Prepare export');
    await page.waitForFunction(() => document.querySelector('[data-export-status="ready"]'), { timeout: 120000, polling: 1500 });
    exportRow = (await inertiaPage(page)).props.exports[0];
    await settle(page, 500);
    exportRow = await page.evaluate(async () => {
        const v = JSON.parse(document.querySelector('script[data-page="app"]').textContent).version;
        return (await (await fetch('/exports', { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': v } })).json()).props.exports[0];
    });
    const base64 = await page.evaluate(async (url) => {
        const buffer = await (await fetch(url)).arrayBuffer();
        let binary = '';
        new Uint8Array(buffer).forEach((b) => (binary += String.fromCharCode(b)));

        return btoa(binary);
    }, exportRow.downloadUrl);
    const file = path.join(here, 'shots', 'p9-export.zip');
    writeFileSync(file, Buffer.from(base64, 'base64'));
    const zip = php('zip', file.replace(/\\/g, '/'));
    await shot(page, 'p9-exports');

    return {
        ok: zip.hashes_match && zip.entries.includes('documents/e2e-transcript-v1.txt') && zip.entries.includes('writing/personal-statement-v1.txt') && zip.entries.includes('college-list.csv') && zip.profile_revision === zip.snapshot.profile_revision,
        note: `entries=${zip.entries.join(', ')}; profile revision ${zip.profile_revision}`,
    };
});

await step('another student cannot download it, and a tampered link is refused', async () => {
    const context = await browser.createBrowserContext();
    const peer = await context.newPage();
    try {
        await login(peer, 'synthetic.peer@example.test');
        const asPeer = await peer.evaluate(async (url) => (await fetch(url)).status, exportRow.downloadUrl);
        const tampered = await page.evaluate(async (url) => (await fetch(url.replace(/signature=[^&]+/, 'signature=forged'))).status, exportRow.downloadUrl);

        return { ok: asPeer === 404 && tampered === 403, note: `peer → ${asPeer}; forged signature → ${tampered}` };
    } finally {
        await context.close();
    }
});

await step('an expired export is deleted by the retention job and its link fails', async () => {
    php('expire', exportRow.id);
    execFileSync('bash', [path.join(here, 'e2e-php.sh'), 'artisan', 'admitme:prune-data'], { encoding: 'utf8' });
    const state = php('export', exportRow.id);
    const status = await page.evaluate(async (url) => (await fetch(url)).status, exportRow.downloadUrl);

    return { ok: state.status === 'expired' && !state.archive_exists && status === 410, note: `export ${state.status}, archive exists=${state.archive_exists}, link → ${status}` };
});

await step('deleting the document removes its file, its text, and every export that contains it', async () => {
    // A fresh export that includes the document...
    await visit(page, '/exports');
    await page.click('[aria-label="Include Documents"]');
    await page.click('[aria-label="Include document E2E transcript"]');
    await clickByText(page, 'button', 'Prepare export');
    await page.waitForFunction(() => document.querySelectorAll('[data-export-status="ready"]').length >= 1, { timeout: 120000, polling: 1500 });
    const second = (await page.evaluate(async () => {
        const v = JSON.parse(document.querySelector('script[data-page="app"]').textContent).version;
        return (await (await fetch('/exports', { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': v } })).json()).props.exports[0];
    }));
    const filesBefore = php('document-files', 'synthetic.student@example.test');

    await visit(page, `/documents/${ids.document}`);
    await clickByText(page, 'button', 'Delete document…');
    await clickByText(page, 'button', 'Yes, delete');
    await page.waitForFunction(() => location.pathname === '/documents', { timeout: 20000 });
    const filesAfter = php('document-files', 'synthetic.student@example.test');
    const exportState = php('export', second.id);
    const linkStatus = await page.evaluate(async (url) => (await fetch(url)).status, second.downloadUrl);
    const pageStatus = await page.evaluate(async (id) => (await fetch(`/documents/${id}`)).status, ids.document);
    await shot(page, 'p9-after-delete');

    return {
        ok: filesBefore.length > 0 && filesAfter.length === 0 && exportState.status === 'deleted' && !exportState.archive_exists && linkStatus === 410 && pageStatus === 404,
        note: `files ${filesBefore.length}→${filesAfter.length}; export ${exportState.status}; link → ${linkStatus}; document page → ${pageStatus}`,
    };
});

report('smoke-phase9', { results, issues });
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`);
for (const issue of issues.slice(0, 8)) console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
await browser.close();
