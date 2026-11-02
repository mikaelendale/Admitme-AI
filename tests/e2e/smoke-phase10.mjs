// End-to-end smoke for Phase 10: the real MV3 extension (unpacked build) in Chrome, against the e2e app and a
// synthetic college portal. Needs: E2E_QUEUE=database for serve.sh AND worker.sh (the form plan runs on the
// worker with a real model), site-server.mjs on :8766, a fresh DB (setup-db.sh), and `npm run build:extension`.
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    BASE,
    clickByText,
    inertiaPage,
    launch,
    login,
    report,
    settle,
    shot,
    visit,
    waitForText,
} from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const DIST = path.resolve(here, '../../browser-extension/dist');
const FORM_URL = 'http://127.0.0.1:8766/lakeside/form.html';
const OTHER_ORIGIN_FORM_URL = 'http://localhost:8766/lakeside/form.html';
const FILLED = {
    legal_name: 'Ada Synthetic',
    email: 'synthetic.student@example.test',
    country: 'Nigeria',
    citizenship: 'Nigerian citizen',
    curriculum: 'A-Levels',
    major: 'Economics, Mathematics',
};

const php = (...args) =>
    JSON.parse(
        execFileSync('bash', [path.join(here, 'e2e-php.sh'), ...args], {
            encoding: 'utf8',
        })
            .trim()
            .split('\n')
            .pop(),
    );

const results = [];
async function step(name, fn) {
    try {
        const detail = (await fn()) ?? {};
        const ok = detail.ok ?? true;
        results.push({ step: name, ok, ...detail });
        console.log(
            `${ok ? 'PASS' : 'FAIL'}  ${name}${detail.note ? ' — ' + detail.note : ''}`,
        );
    } catch (error) {
        results.push({
            step: name,
            ok: false,
            note: String(error?.message ?? error).slice(0, 400),
        });
        console.log(
            `FAIL  ${name} — ${String(error?.message ?? error).slice(0, 400)}`,
        );
    }
}

const json = (page, p, method = 'GET', body = null) =>
    page.evaluate(
        async (url, m, b) => {
            const token =
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '';
            const res = await fetch(url, {
                method: m,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: b ? JSON.stringify(b) : undefined,
            });

            return {
                status: res.status,
                body: await res.json().catch(() => null),
            };
        },
        p,
        method,
        body,
    );

async function props(page, p) {
    const version = (await inertiaPage(page))?.version ?? '';

    return page.evaluate(
        async (url, v) =>
            (
                await (
                    await fetch(url, {
                        headers: {
                            'X-Inertia': 'true',
                            'X-Inertia-Version': v,
                            Accept: 'text/html, application/xhtml+xml',
                        },
                    })
                ).json()
            ).props,
        p,
        version,
    );
}

async function until(predicate, timeoutMs, label) {
    const started = Date.now();
    let last;
    while (Date.now() - started < timeoutMs) {
        last = await predicate();
        if (last) return last;
        await new Promise((r) => setTimeout(r, 1000));
    }
    throw new Error(`Timed out waiting for ${label}`);
}

const formValues = (page) =>
    page.evaluate(() =>
        Object.fromEntries(
            Array.from(
                document.querySelectorAll(
                    '#application input, #application select, #application textarea',
                ),
            )
                .filter((el) => el.type !== 'file' && el.type !== 'hidden')
                .map((el) => [
                    el.name,
                    { value: el.value, inputs: el.dataset.inputs ?? '0' },
                ]),
        ),
    );

const { browser, page: web, issues } = await launch({ extensions: true });

// Expected noise on the extension pages: statuses the panel handles (401 after revoking, 409 wrong tab,
// 422 reused code) and the request this smoke deliberately drops to simulate a lost acknowledgement.
const EXPECTED = [
    /status of (401|409|422)/,
    /ERR_CONNECTION_FAILED|ERR_FAILED/,
];
function watch(page, name) {
    page.on('console', (msg) => {
        if (
            msg.type() === 'error' &&
            !EXPECTED.some((re) => re.test(msg.text())) &&
            !/favicon\.ico$/.test(msg.location()?.url ?? '')
        )
            issues.push({
                kind: `${name}:console`,
                url: page.url(),
                text: msg.text().slice(0, 300),
            });
    });
    page.on('pageerror', (err) =>
        issues.push({
            kind: `${name}:pageerror`,
            url: page.url(),
            text: String(err?.message ?? err).slice(0, 300),
        }),
    );
}

const ids = {};
let form;
let panel;

await step(
    'setup: the Lakeside application has a form plan (real model on the worker); the workspace offers browser assistance',
    async () => {
        ids.extension = await browser.installExtension(DIST);
        await login(web, 'synthetic.student@example.test');
        await visit(web, '/college-lists');
        const lakesideRow = (await inertiaPage(web)).props.colleges.find(
            (c) => c.name === 'Synthetic Lakeside University',
        );
        await json(web, `/college-lists/${lakesideRow.id}`, 'PATCH', {
            choice_status: 'applying',
        });
        await visit(web, '/college-lists');
        ids.application = (await inertiaPage(web)).props.colleges.find(
            (c) => c.name === 'Synthetic Lakeside University',
        ).applicationId;
        await visit(web, `/applications/${ids.application}`);
        const before = await web.evaluate(
            () =>
                [...document.querySelectorAll('section')].find((s) =>
                    s
                        .querySelector('h2')
                        ?.textContent.includes('Browser assistance'),
                )?.innerText ?? '',
        );
        await clickByText(web, 'button', 'Prepare the form plan');
        await web.waitForFunction(
            () => document.querySelectorAll('[data-basis]').length > 10,
            { timeout: 300000, polling: 2000 },
        );
        await settle(web, 800);
        const entries = (await props(web, `/applications/${ids.application}`))
            .workspace.formPlan.plan.entries;

        return {
            ok:
                before.includes('No browser is linked') &&
                entries.find((e) => e.field === 'Legal name')?.basis ===
                    'profile' &&
                entries.length > 10,
            note: `extension ${ids.extension}; ${entries.length} plan entries; workspace said "${before.split('\n').pop()}"`,
        };
    },
);

await step(
    'linking: a one-time code from Settings links the side panel; the code cannot be reused; the device is listed',
    async () => {
        await visit(web, '/settings/browser-extension');
        await clickByText(web, 'button', 'Create a link code');
        await web.waitForSelector('[data-testid="link-code"] .font-mono', {
            timeout: 15000,
        });
        const code = await web.$eval(
            '[data-testid="link-code"] .font-mono',
            (el) => el.textContent.trim(),
        );

        form = await browser.newPage();
        watch(form, 'form');
        await form.goto(FORM_URL, { waitUntil: 'load' });

        panel = await browser.newPage({ type: 'window' });
        watch(panel, 'panel');
        await panel.goto(
            `chrome-extension://${ids.extension}/sidepanel/index.html`,
            { waitUntil: 'load' },
        );
        ids.formTab = await panel.evaluate(
            async (url) =>
                (await chrome.tabs.query({})).find((t) => t.url === url)?.id,
            FORM_URL,
        );
        await panel.goto(
            `chrome-extension://${ids.extension}/sidepanel/index.html?tab=${ids.formTab}`,
            { waitUntil: 'load' },
        );

        await panel.waitForSelector('input[name="server"]');
        await panel.type('input[name="server"]', BASE);
        await panel.type('input[name="code"]', code);
        await panel.click('input[name="device"]');
        await panel.keyboard.down('Control');
        await panel.keyboard.press('KeyA');
        await panel.keyboard.up('Control');
        await panel.keyboard.press('Backspace');
        await panel.type('input[name="device"]', 'E2E Chrome');
        await panel.click('button[type="submit"]');
        await waitForText(panel, 'Start on the form tab', 20000);

        const reuse = await panel.evaluate(
            async (base, c) =>
                (
                    await fetch(`${base}/api/browser/link`, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ code: c }),
                    })
                ).status,
            BASE,
            code,
        );
        await visit(web, '/settings/browser-extension');
        const listed = await web.evaluate(() =>
            Array.from(
                document.querySelectorAll('[data-device] p.font-medium'),
            ).some((el) => el.textContent.trim() === 'E2E Chrome'),
        );
        await shot(panel, 'p10-panel-linked');

        return {
            ok: reuse === 422 && listed,
            note: `code ${code}; reuse → ${reuse}; device listed=${listed}`,
        };
    },
);

await step(
    'session: "Use this tab" binds the form tab; observing never sends passwords, hidden fields or file inputs',
    async () => {
        await panel.select('select[name="application"]', ids.application);
        await clickByText(panel, 'button', 'Use this tab');
        await waitForText(panel, 'Session on http://127.0.0.1:8766', 20000);
        ids.session = await panel.$eval(
            '[data-session]',
            (el) => el.dataset.session,
        );
        await clickByText(panel, 'button', 'Observe this page');
        await waitForText(panel, 'Saw ', 20000);
        const snapshot = php('browser-snapshot', ids.session);
        const labels = snapshot.fields.map((f) => f.label);
        const leaked = [
            'synthetic-portal-password',
            'SYNTHETIC-HIDDEN-SECRET',
            'Portal password',
            'Upload transcript',
        ].filter((s) => snapshot.raw.includes(s));

        return {
            ok:
                leaked.length === 0 &&
                labels.length === 7 &&
                labels.includes('Legal name'),
            note: `observed: ${labels.join(' | ')}; leaked=${JSON.stringify(leaked)}`,
        };
    },
);

await step(
    'prepare: the fill offers only literal profile details (no essay); it shows in the web app and Approvals; nothing typed yet',
    async () => {
        await clickByText(panel, 'button', 'Prepare fill');
        await panel.waitForSelector('[data-proposal][data-status="pending"]', {
            timeout: 20000,
        });
        const items = await panel.$$eval(
            '[data-proposal][data-status="pending"] li strong',
            (els) => els.map((el) => el.textContent.trim()),
        );
        const values = await formValues(form);
        await visit(web, `/applications/${ids.application}`);
        const inWorkspace = await web.evaluate(
            () =>
                document.querySelector('[data-browser-session]')?.innerText ??
                '',
        );
        await visit(web, '/approvals');
        const inApprovals = await web.evaluate(() =>
            document.body.innerText.includes('Fill 6 field(s) on 127.0.0.1'),
        );
        await shot(panel, 'p10-panel-proposal');

        return {
            ok:
                items.length === 6 &&
                !items.some((l) => l.startsWith('Personal statement')) &&
                Object.values(values).every(
                    (v) =>
                        v.value === '' ||
                        v.value === 'synthetic-portal-password',
                ) &&
                inWorkspace.includes('nothing has been typed') &&
                inApprovals,
            note: `items: ${items.join(', ')}; workspace shows pending=${inWorkspace.includes('nothing has been typed')}; approvals lists it=${inApprovals}`,
        };
    },
);

await step(
    'approve in the web app: the panel waits for the form tab, then types each value once and reads it back; nothing submitted',
    async () => {
        await web.bringToFront(); // the form tab is now in the background
        await visit(web, `/applications/${ids.application}`);
        await clickByText(web, '[data-browser-session] button', 'Approve');
        const waiting = await until(
            async () => {
                const text = await panel.evaluate(
                    () =>
                        document.querySelector('[data-testid="waiting"]')
                            ?.textContent ?? '',
                );

                return text.includes('Switch to the form tab') ? text : null;
            },
            20000,
            'the panel to wait for the form tab',
        );
        const untouched = Object.values(await formValues(form)).every(
            (v) => v.inputs === '0',
        );

        await form.bringToFront();
        await until(
            async () =>
                php('browser-commands', ids.session).every(
                    (c) => c.status === 'completed',
                ) || null,
            30000,
            'all six steps to complete',
        );
        const values = await formValues(form);
        const typedOnce = Object.keys(FILLED).every(
            (name) =>
                values[name].value === FILLED[name] &&
                values[name].inputs === '1',
        );
        const submitted = await form.evaluate(
            () => document.body.dataset.submitted ?? 'no',
        );
        await shot(form, 'p10-form-filled');
        await visit(web, `/applications/${ids.application}`);
        const completedInWeb = await web.$$eval(
            '[data-command-status="completed"]',
            (els) => els.length,
        );
        await shot(web, 'p10-workspace-steps');

        return {
            ok:
                untouched &&
                typedOnce &&
                values.essay.value === '' &&
                values.portal_password.value === 'synthetic-portal-password' &&
                submitted === 'no' &&
                completedInWeb === 6,
            note: `waited ("${waiting.slice(0, 60)}…") with nothing typed=${untouched}; typed once & read back=${typedOnce}; essay empty=${values.essay.value === ''}; submitted=${submitted}; web shows ${completedInWeb} completed`,
        };
    },
);

await step(
    'reconnect: a lost acknowledgement is reconciled after the panel reloads; the value is not typed twice',
    async () => {
        await form.evaluate(() => {
            const major = document.getElementById('major');
            major.value = '';
            major.dataset.inputs = '0';
        });
        await clickByText(panel, 'button', 'Observe this page');
        await waitForText(panel, 'Saw ', 20000);
        await clickByText(panel, 'button', 'Prepare fill');
        await panel.waitForSelector('[data-proposal][data-status="pending"]', {
            timeout: 20000,
        });

        // Every acknowledgement is dropped, as if the connection died right after the field was filled.
        await panel.setRequestInterception(true);
        const drop = (req) =>
            req.method() === 'POST' &&
            /\/api\/browser\/commands\/.+\/result$/.test(req.url())
                ? req.abort('connectionfailed')
                : req.continue();
        panel.on('request', drop);
        // The buttons stay disabled until "Prepare fill" has finished in the panel; a click before that does nothing.
        await panel.waitForSelector(
            '[data-proposal][data-status="pending"] button.primary:not([disabled])',
            { timeout: 20000 },
        );
        await panel.click(
            '[data-proposal][data-status="pending"] button.primary:not([disabled])',
        );
        await until(
            async () =>
                (await panel.evaluate(() =>
                    document.body.innerText.includes(
                        'Could not confirm a step',
                    ),
                )) || null,
            30000,
            'the lost acknowledgement',
        );
        const leased = php('browser-commands', ids.session).at(-1);

        panel.off('request', drop);
        await panel.setRequestInterception(false);
        await panel.reload({ waitUntil: 'load' });
        await waitForText(panel, 'Session on', 20000);
        const done = await until(
            async () => {
                const last = php('browser-commands', ids.session).at(-1);

                return last.status === 'completed' ? last : null;
            },
            45000,
            'the reconciled step',
        );
        const major = (await formValues(form)).major;

        return {
            ok:
                leased.status === 'leased' &&
                done.attempts >= 2 &&
                major.value === FILLED.major &&
                major.inputs === '1',
            note: `after the drop: ${leased.status}; after reload: ${done.status} on attempt ${done.attempts}; typed ${major.inputs} time(s)`,
        };
    },
);

await step(
    'stale: a fill approved for one version of the page is not run after the page changes',
    async () => {
        await form.evaluate(() => {
            const citizenship = document.getElementById('citizenship');
            citizenship.value = '';
            citizenship.dataset.inputs = '0';
        });
        await clickByText(panel, 'button', 'Observe this page');
        await waitForText(panel, 'Saw ', 20000);
        await clickByText(panel, 'button', 'Prepare fill');
        await panel.waitForSelector('[data-proposal][data-status="pending"]', {
            timeout: 20000,
        });

        await web.bringToFront();
        await visit(web, `/applications/${ids.application}`);
        await clickByText(web, '[data-browser-session] button', 'Approve');
        await until(
            async () =>
                (
                    await panel.evaluate(
                        () =>
                            document.querySelector('[data-testid="waiting"]')
                                ?.textContent ?? '',
                    )
                ).includes('Switch to the form tab') || null,
            20000,
            'the panel to wait',
        );

        // The portal reveals another question before the student comes back to the tab.
        await form.evaluate(() => {
            const label = Object.assign(document.createElement('label'), {
                htmlFor: 'middle_name',
                textContent: 'Middle name',
            });
            const input = Object.assign(document.createElement('input'), {
                id: 'middle_name',
                name: 'middle_name',
                type: 'text',
            });
            document.getElementById('essay').before(label, input);
        });
        await form.bringToFront();
        const last = await until(
            async () => {
                const command = php('browser-commands', ids.session).at(-1);

                return command.status === 'stale' ? command : null;
            },
            30000,
            'the step to become stale',
        );
        const citizenship = (await formValues(form)).citizenship;
        const shown = await until(
            async () =>
                (await panel.evaluate(() =>
                    document.body.innerText.includes(
                        'Not done: the page changed',
                    ),
                )) || null,
            10000,
            'the panel to show the stale step',
        ).catch(() => false);
        await shot(panel, 'p10-panel-stale');

        return {
            ok: citizenship.value === '' && citizenship.inputs === '0' && shown,
            note: `step ${last.label} → ${last.status}; citizenship untouched=${citizenship.value === ''}; panel shows it=${shown}`,
        };
    },
);

await step(
    'wrong tab or origin: the server hands out nothing; nothing is typed on another site',
    async () => {
        const direct = await panel.evaluate(
            async (base, session, tab) => {
                const { link } = await chrome.storage.local.get('link');
                const lease = async (tabId, origin) =>
                    (
                        await fetch(
                            `${base}/api/browser/sessions/${session}/commands?tab_id=${tabId}&origin=${encodeURIComponent(origin)}&page_hash=${'0'.repeat(64)}`,
                            {
                                headers: {
                                    Accept: 'application/json',
                                    Authorization: `Bearer ${link.token}`,
                                },
                            },
                        )
                    ).status;

                return {
                    otherTab: await lease(tab + 1000, 'http://127.0.0.1:8766'),
                    otherOrigin: await lease(tab, 'http://localhost:8766'),
                };
            },
            BASE,
            ids.session,
            ids.formTab,
        );

        await clickByText(panel, 'button', 'Observe this page');
        await waitForText(panel, 'Saw ', 20000);
        await clickByText(panel, 'button', 'Prepare fill');
        await panel.waitForSelector('[data-proposal][data-status="pending"]', {
            timeout: 20000,
        });
        await web.bringToFront();
        await visit(web, `/applications/${ids.application}`);
        await clickByText(web, '[data-browser-session] button', 'Approve');

        // The same tab now shows another site.
        await form.goto(OTHER_ORIGIN_FORM_URL, { waitUntil: 'load' });
        await form.bringToFront();
        const refused = await until(
            async () => {
                const text = await panel.evaluate(
                    () =>
                        document.querySelector('[data-testid="waiting"]')
                            ?.textContent ?? '',
                );

                return text.includes(
                    'These steps are for the tab showing http://127.0.0.1:8766',
                )
                    ? text
                    : null;
            },
            20000,
            'the server to refuse the other origin',
        );
        const elsewhere = await formValues(form);
        const last = php('browser-commands', ids.session).at(-1);

        return {
            ok:
                direct.otherTab === 409 &&
                direct.otherOrigin === 409 &&
                Object.values(elsewhere).every((v) => v.inputs === '0') &&
                last.status === 'pending',
            note: `direct lease: other tab ${direct.otherTab}, other origin ${direct.otherOrigin}; panel: "${refused.slice(0, 70)}…"; step still ${last.status}; nothing typed on localhost`,
        };
    },
);

await step(
    'revoking the device in Settings signs the panel out at once and ends its session',
    async () => {
        await web.bringToFront();
        await visit(web, '/settings/browser-extension');
        await clickByText(web, 'button', 'Revoke');
        await settle(web, 800);
        const revoked = await web.evaluate(() =>
            document.body.innerText.includes('Revoked'),
        );
        await until(
            async () =>
                (await panel.evaluate(() =>
                    document.body.innerText.includes('no longer linked'),
                )) || null,
            20000,
            'the panel to sign out',
        );
        const linkForm = await panel.evaluate(() =>
            document.body.innerText.includes('Link this browser'),
        );
        const ended = await web.evaluate(() =>
            document.body.innerText.includes('Session ended'),
        );
        await shot(web, 'p10-settings-revoked');

        return {
            ok: revoked && linkForm && ended,
            note: `device revoked=${revoked}; panel back to linking=${linkForm}; session ended=${ended}`,
        };
    },
);

report('smoke-phase10', { results, issues });
console.log(
    `\n${results.filter((r) => r.ok).length}/${results.length} steps passed; ${issues.length} browser issue(s).`,
);
for (const issue of issues.slice(0, 8))
    console.log(`ISSUE ${issue.kind}: ${issue.text} @ ${issue.url}`);
await browser.close();
