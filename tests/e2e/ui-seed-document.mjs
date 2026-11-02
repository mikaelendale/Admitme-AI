// Upload the synthetic transcript as the synthetic student so the document pages have content (UI work only).
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { clickByText, launch, login, settle, BASE } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const { browser, page } = await launch();
await login(page, 'synthetic.student@example.test');
await page.goto(`${BASE}/documents`, { waitUntil: 'domcontentloaded' });
await settle(page);
const input = await page.$('#document-file');
await input.uploadFile(path.join(here, 'files', process.argv[2] ?? 'transcript-v1.pdf'));
await page.select('#document-kind', 'transcript');
await clickByText(page, 'form button[type="submit"]', 'Upload');
await page.waitForFunction(() => /\/documents\/[0-9a-z]{26}$/i.test(location.pathname), { timeout: 60000 });
console.log('uploaded', page.url());
await browser.close();
