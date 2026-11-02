// Browser smoke for the docked mentor panel's resize handle (no model calls): drag wider, keyboard narrower,
// double-click back to the default, and the width is remembered across a reload.
// Run against the e2e server (see README): node smoke-dock-resize.mjs
import { launch, login, report, settle, shot, BASE } from './lib.mjs';

const results = [];
const separator = '[role="separator"][aria-label="Resize mentor panel"]';

function record(step, ok, note = '') {
    results.push({ step, ok, note });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${note ? ' — ' + note : ''}`);
}

const { browser, page, issues } = await launch();
await page.setViewport({ width: 1440, height: 900 });
await login(page, 'synthetic.student@example.test');
await page.evaluate(() => {
    localStorage.removeItem('admitme.mentor.width');
    localStorage.setItem('admitme.mentor.open', '1');
});
await page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector(separator, { timeout: 30000 });
await settle(page, 800);

const width = () => page.$eval('aside[aria-label="Mentor"]', (el) => Math.round(el.getBoundingClientRect().width));
const center = async () => {
    const box = await (await page.$(separator)).boundingBox();

    return [box.x + box.width / 2, box.y + box.height / 2];
};

const start = await width();
let [x, y] = await center();
await page.mouse.move(x, y);
await page.mouse.down();
await page.mouse.move(x - 100, y, { steps: 6 });
await page.mouse.up();
await settle(page, 300);
const dragged = await width();
record('drag the edge 100px left widens the panel by 100px', dragged - start === 100, `start=${start} dragged=${dragged}`);

await page.reload({ waitUntil: 'domcontentloaded' });
await page.waitForSelector(separator, { timeout: 30000 });
await settle(page, 800);
const reloaded = await width();
record('the width is remembered after a reload', reloaded === dragged, `reloaded=${reloaded}`);

await page.focus(separator);
await page.keyboard.press('ArrowRight');
const keyed = await width();
record('ArrowRight on the focused handle narrows by 24px', reloaded - keyed === 24, `keyed=${keyed}`);

[x, y] = await center();
await page.mouse.click(x, y);
await new Promise((resolve) => setTimeout(resolve, 120));
await page.mouse.click(x, y);
await settle(page, 300);
const reset = await width();
const stored = await page.evaluate(() => localStorage.getItem('admitme.mentor.width'));
await shot(page, 'dock-reset');
record('a double-click resets to the default width and forgets the saved one', reset === 400 && stored === null, `reset=${reset} stored=${stored}`);

[x, y] = await center();
await page.mouse.click(x, y);
await settle(page, 600);
record('a single click does not resize', (await width()) === 400, `after click=${await width()}`);

record('no console or server errors', issues.length === 0, issues.map((i) => i.text).join(' | ').slice(0, 200));

const passed = results.filter((r) => r.ok).length;
console.log(`\n${passed}/${results.length} steps passed; ${issues.length} browser issue(s).`);
report('smoke-dock-resize', { results, issues });
await browser.close();
process.exit(results.every((r) => r.ok) ? 0 : 1);
