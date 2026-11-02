// Minimal e2e helpers around puppeteer-core + the locally installed Chrome.
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';

const here = path.dirname(fileURLToPath(import.meta.url));
export const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8765';
export const SHOTS = path.join(here, 'shots');
mkdirSync(SHOTS, { recursive: true });

// Chrome or Chromium to drive (puppeteer-core does not download one). Set CHROME_PATH on other machines.
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';

// Noise that is expected in this environment (no Reverb server running for websockets).
const KNOWN_NOISE = [/WebSocket connection to/i, /reverb/i, /favicon\.ico/i, /pusher/i];

export async function launch(options = {}) {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: true,
        args: ['--no-sandbox', '--disable-gpu', '--window-size=1366,900'],
        // One protocol call can last as long as a waitForFunction; the default (180s) cuts long mentor replies short.
        ...(options.protocolTimeout ? { protocolTimeout: options.protocolTimeout } : {}),
        // Phase 10: branded Chrome ignores --load-extension; unpacked extensions are installed over CDP (pipe).
        ...(options.extensions ? { pipe: true, enableExtensions: true } : {}),
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1366, height: 900 });
    // PHP's single-threaded dev server can stall ~40s on Chrome's idle speculative preconnections; that is the
    // dev server, not the app, so navigations get more headroom than puppeteer's 30s default.
    page.setDefaultNavigationTimeout(90000);

    const issues = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error' && !KNOWN_NOISE.some((re) => re.test(msg.text()))) {
            issues.push({ kind: 'console', url: page.url(), text: msg.text().slice(0, 500) });
        }
    });
    page.on('pageerror', (err) => issues.push({ kind: 'pageerror', url: page.url(), text: String(err?.message ?? err).slice(0, 500) }));
    page.on('response', (res) => {
        if (res.status() >= 500) {
            issues.push({ kind: 'http', url: res.url(), text: `HTTP ${res.status()}` });
        }
    });

    return { browser, page, issues };
}

export async function login(page, email, password = 'password') {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle2' });
    await page.type('#email', email);
    await page.type('#password', password);
    await page.click('[data-test="login-button"]');
    await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 20000 });
    await settle(page);
}

export async function settle(page, ms = 600) {
    try {
        await page.waitForNetworkIdle({ idleTime: 500, timeout: 15000 });
    } catch {
        /* long-polling pages never go fully idle */
    }
    await new Promise((r) => setTimeout(r, ms));
}

export async function visit(page, pathName) {
    const response = await page.goto(`${BASE}${pathName}`, { waitUntil: 'domcontentloaded' });
    await settle(page);

    return response?.status() ?? 0;
}

/**
 * Record each mentor reply the page receives in window.__chatStreams ({ turnId, text, done, doneAt, body }),
 * where text holds the reply's parts as "data: {json}" lines — the same shape the old streamed endpoint had.
 * Replies run as background turns: the page starts one (POST /dashboard/chat/turns) and reads its parts from
 * /dashboard/chat/turns/{id}/events until the turn has finished and nothing is left to read.
 */
export async function teeChat(page) {
    await page.evaluateOnNewDocument(() => {
        window.__chatStreams = window.__chatStreams ?? [];
        const original = window.fetch.bind(window);
        const entryFor = (turnId, body = null) => {
            let entry = window.__chatStreams.find((e) => e.turnId === turnId);
            if (!entry) {
                entry = { turnId, text: '', done: false, doneAt: null, body };
                window.__chatStreams.push(entry);
            }

            return entry;
        };
        window.fetch = async (...args) => {
            const url = typeof args[0] === 'string' ? args[0] : (args[0]?.url ?? '');
            const method = String(args[1]?.method ?? 'GET').toUpperCase();
            const response = await original(...args);

            try {
                if (method === 'POST' && /\/dashboard\/chat\/turns(\?|$)/.test(url) && response.ok) {
                    const json = await response.clone().json();
                    if (json?.turn?.id) entryFor(json.turn.id, typeof args[1]?.body === 'string' ? args[1].body : null);
                }

                const match = url.match(/\/dashboard\/chat\/turns\/([^/?]+)\/events/);
                if (match && response.ok) {
                    const json = await response.clone().json();
                    const entry = entryFor(match[1]);
                    for (const event of json.events ?? []) entry.text += `data: ${JSON.stringify(event)}\n\n`;
                    const active = json.turn?.status === 'queued' || json.turn?.status === 'running';
                    if (!active && (json.events ?? []).length === 0 && !entry.done) {
                        entry.done = true;
                        entry.doneAt = performance.now();
                        entry.status = json.turn?.status;
                    }
                }
            } catch {
                /* not JSON: ignore */
            }

            return response;
        };
    });
}

export async function shot(page, name) {
    const file = path.join(SHOTS, `${name}.png`);
    // A full-page capture briefly resizes the window (to 1x1), which flips width media queries — the docked
    // mentor would turn into its overlay for that frame. The app shell never scrolls the document, so only
    // capture beyond the viewport when the document really is taller.
    const taller = await page.evaluate(() => document.documentElement.scrollHeight > window.innerHeight + 1);
    await page.screenshot({ path: file, fullPage: taller });

    return file;
}

/** Read the current Inertia page object (v3 stores it in a JSON script tag; older versions in data-page). */
export async function inertiaPage(page) {
    return page.evaluate(() => {
        const script = document.querySelector('script[data-page="app"][type="application/json"]');
        if (script) {
            return JSON.parse(script.textContent ?? '{}');
        }
        const el = document.querySelector('#app[data-page]');

        return el ? JSON.parse(el.getAttribute('data-page') ?? '{}') : null;
    });
}

export async function clickByText(page, selector, text) {
    const handles = await page.$$(selector);
    for (const handle of handles) {
        const content = await handle.evaluate((el) => el.textContent?.trim() ?? '');
        if (content.includes(text)) {
            await handle.click();

            return true;
        }
    }

    return false;
}

export async function waitForText(page, text, timeout = 20000) {
    await page.waitForFunction((t) => document.body.innerText.includes(t), { timeout }, text);
}

export function report(name, data) {
    writeFileSync(path.join(here, `${name}.json`), JSON.stringify(data, null, 2));
}

/** The current Inertia page after client-side visits (the embedded JSON only describes the first load). */
export async function livePage(page) {
    const state = await page.evaluate(() => window.history.state?.page ?? null);

    return state && typeof state === 'object' && state.props ? state : inertiaPage(page);
}
