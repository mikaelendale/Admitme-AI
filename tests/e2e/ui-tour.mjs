// UI tour: screenshots of every main screen at desktop and phone width, light and dark, plus layout checks.
// Run against the e2e server (see README): node ui-tour.mjs <label> [--only=dashboard,onboarding] [--theme=light|dark|both]
//   [--viewports=desktop,mobile]
// Writes shots/ui-<label>-<screen>-<viewport>-<theme>.png and ui-tour-<label>.json (console errors, 5xx responses,
// horizontal overflow and the elements causing it). Exits non-zero when a check fails.
import { launch, login, report, settle, BASE, SHOTS } from './lib.mjs';
import path from 'node:path';

const label = process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : 'tour';
const arg = (name, fallback) => process.argv.find((a) => a.startsWith(`--${name}=`))?.split('=')[1] ?? fallback;
const only = arg('only', '').split(',').filter(Boolean);
const themes = arg('theme', 'light') === 'both' ? ['light', 'dark'] : [arg('theme', 'light')];
const viewports = {
    desktop: { width: 1440, height: 900 },
    laptop: { width: 1180, height: 760 },
    mobile: { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
};
const chosenViewports = arg('viewports', 'desktop,mobile').split(',');

const STUDENT = 'synthetic.student@example.test';
const FRESH = 'fresh.student@example.test';

/** Screens for the onboarded synthetic student. `find` picks a detail page from links on a list page. */
const SCREENS = [
    { key: 'dashboard', path: '/dashboard' },
    { key: 'chats', path: '/dashboard/chats' },
    { key: 'college-lists', path: '/college-lists' },
    { key: 'college-school', find: { from: '/college-lists', pattern: /^\/college-lists\/[0-9a-zA-Z]+$/ } },
    { key: 'compare', path: '/college-lists/compare' },
    { key: 'applications', path: '/applications' },
    { key: 'deadlines', path: '/deadlines' },
    { key: 'application', find: { from: '/applications', pattern: /^\/applications\/[0-9A-Za-z]{20,}$/ } },
    { key: 'documents', path: '/documents' },
    { key: 'document', find: { from: '/documents', pattern: /^\/documents\/[0-9A-Za-z]{20,}$/ } },
    { key: 'writing', path: '/writing' },
    { key: 'writing-piece', find: { from: '/writing', pattern: /^\/writing\/[0-9A-Za-z]{20,}$/ } },
    { key: 'supplements', path: '/essay-suite/supplements' },
    { key: 'settings-security', path: '/settings/security' },
    { key: 'settings-billing', path: '/settings/billing' },
    { key: 'settings-addons', path: '/settings/add-ons' },
    { key: 'settings-email', path: '/settings/notifications' },
    { key: 'settings-privacy', path: '/settings/privacy' },
    { key: 'profile', path: '/profile-overview' },
    { key: 'profile-setup', path: '/profile-setup' },
    { key: 'approvals', path: '/approvals' },
    { key: 'tasks', path: '/tasks' },
    { key: 'notifications', path: '/notifications' },
    { key: 'essay-suite', path: '/essay-suite' },
    { key: 'gap-fix', path: '/gap-fix' },
    { key: 'exports', path: '/exports' },
    { key: 'settings', path: '/settings/profile' },
    { key: 'settings-ai', path: '/settings/ai' },
    { key: 'browser-extension', path: '/settings/browser-extension' },
];

const results = [];
let failed = false;

/** Elements whose right edge passes the viewport while the page itself cannot scroll sideways to show them. */
async function layoutChecks(page) {
    return page.evaluate(() => {
        const width = window.innerWidth;
        const doc = document.scrollingElement ?? document.documentElement;
        const pageOverflow = doc.scrollWidth > width + 1;
        const offenders = [];
        const scrollsSideways = (el) => {
            for (let node = el.parentElement; node; node = node.parentElement) {
                const style = getComputedStyle(node);
                if (['auto', 'scroll', 'hidden', 'clip'].includes(style.overflowX) && node !== document.body && node !== document.documentElement) {
                    return true;
                }
            }

            return false;
        };
        for (const el of document.querySelectorAll('body *')) {
            const rect = el.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0 || rect.right <= width + 1) {
                continue;
            }
            const style = getComputedStyle(el);
            if (style.visibility === 'hidden' || style.display === 'none' || style.position === 'fixed') {
                continue;
            }
            if (scrollsSideways(el)) {
                continue;
            }
            const name = `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 4).join('.') : ''}`;
            offenders.push({ el: name.slice(0, 160), right: Math.round(rect.right), text: (el.textContent ?? '').trim().slice(0, 60) });
            if (offenders.length >= 6) {
                break;
            }
        }

        return { pageOverflow, scrollWidth: doc.scrollWidth, width, offenders };
    });
}

async function capture(page, issues, screen, viewportName, theme) {
    const before = issues.length;
    let target = screen.path;
    if (screen.find) {
        await page.goto(`${BASE}${screen.find.from}`, { waitUntil: 'domcontentloaded' });
        await settle(page);
        const hrefs = await page.$$eval('a[href]', (links) => links.map((a) => new URL(a.href).pathname));
        target = hrefs.find((href) => screen.find.pattern.test(href));
        if (!target) {
            results.push({ screen: screen.key, viewport: viewportName, theme, ok: false, note: 'no detail link found' });
            failed = true;

            return;
        }
    }
    const response = await page.goto(`${BASE}${target}`, { waitUntil: 'domcontentloaded' });
    await settle(page, 900);
    const status = response?.status() ?? 0;
    const finalPath = new URL(page.url()).pathname;
    const checks = await layoutChecks(page);
    const file = path.join(SHOTS, `ui-${label}-${screen.key}-${viewportName}-${theme}.png`);
    await page.screenshot({ path: file, fullPage: false });
    const newIssues = issues.slice(before);
    const ok = status < 400 && !checks.pageOverflow && checks.offenders.length === 0 && newIssues.length === 0;
    if (!ok) {
        failed = true;
    }
    results.push({ screen: screen.key, path: target, finalPath, viewport: viewportName, theme, status, ok, overflow: checks, issues: newIssues, file });
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${screen.key.padEnd(18)} ${viewportName.padEnd(7)} ${theme.padEnd(5)} ${status} ${finalPath}${checks.offenders.length ? ` overflow:${checks.offenders.map((o) => o.el).join(' | ')}` : ''}${newIssues.length ? ` issues:${newIssues.map((i) => i.text).join(' | ').slice(0, 300)}` : ''}`);
}

async function session(email, screens) {
    for (const theme of themes) {
        for (const viewportName of chosenViewports) {
            const { browser, page, issues } = await launch();
            try {
                await page.setViewport(viewports[viewportName]);
                await page.setCookie({ name: 'appearance', value: theme, url: BASE });
                await page.evaluateOnNewDocument((t) => localStorage.setItem('appearance', t), theme);
                await login(page, email);
                for (const screen of screens) {
                    await capture(page, issues, screen, viewportName, theme);
                }
            } finally {
                await browser.close();
            }
        }
    }
}

const wanted = (key) => only.length === 0 || only.includes(key);

await session(STUDENT, SCREENS.filter((s) => wanted(s.key)));
if (wanted('onboarding')) {
    await session(FRESH, [{ key: 'onboarding', path: '/onboarding' }]);
}

report(`ui-tour-${label}`, results);
console.log(`\n${results.filter((r) => r.ok).length}/${results.length} screens passed checks`);
process.exit(failed ? 1 : 0);
