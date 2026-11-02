// Screenshots of the signed-out pages (welcome, login, register, password reset): node ui-guest.mjs <label> [--theme=light|dark]
import path from 'node:path';
import { launch, settle, BASE, SHOTS } from './lib.mjs';

const label = process.argv[2] ?? 'guest';
const theme = process.argv.find((a) => a.startsWith('--theme='))?.split('=')[1] ?? 'light';
const { browser, page, issues } = await launch();
await page.setCookie({ name: 'appearance', value: theme, url: BASE });
await page.evaluateOnNewDocument((t) => localStorage.setItem('appearance', t), theme);
for (const [key, url] of [['welcome', '/'], ['login', '/login'], ['register', '/register'], ['forgot', '/forgot-password']]) {
    for (const [vp, size] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }]]) {
        await page.setViewport(size);
        await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' });
        await settle(page, 700);
        const file = path.join(SHOTS, `ui-${label}-${key}-${vp}-${theme}.png`);
        await page.screenshot({ path: file });
        console.log(file);
    }
}
console.log(issues.length ? issues : 'no issues');
await browser.close();
