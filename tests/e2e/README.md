# Browser smokes (real browser, real queue, real model)

End-to-end checks for each build phase. They drive the running app in Chrome with puppeteer and use the configured AI provider. All data is synthetic; never point them at a real database.

## Setup (once)

```bash
cd tests/e2e
npm install                      # puppeteer-core only (uses your installed Chrome)
export CHROME_PATH="/path/to/chrome"   # if Chrome is not at the Windows default location
(cd ../.. && npm run build && npm run build:extension)
```

The provider key in the app's `.env` must work. e2e runs use Anthropic by default; override with `E2E_AI_PROVIDER` / `E2E_ANTHROPIC_MODEL`.

## Run everything

```bash
node site-server.mjs &          # synthetic college site on 127.0.0.1:8766 (keep it running)
bash run-all.sh                  # each smoke on a fresh e2e database, with its own server and queue worker
cat logs/run-all-summary.txt
```

Or run a subset: `bash run-all.sh smoke-phase10`.

## Run one by hand

```bash
E2E_QUEUE=database bash setup-db.sh      # stop the e2e server/worker first; it refuses otherwise
E2E_QUEUE=database bash serve.sh &       # app on 127.0.0.1:8765 against tests/e2e/e2e.sqlite
E2E_QUEUE=database bash worker.sh &      # queue worker
node smoke-phase7.mjs
```

## Safety

- `setup-db.sh`, `wal.php` and `inspect.php` refuse any database other than `tests/e2e/e2e.sqlite`.
- The e2e server shares the app's `storage/` folder with local development. Smokes therefore never delete accounts, because erasure removes that student's storage folders. Account deletion is covered by feature tests on fake storage.
- `files/` and `site/` hold synthetic fixtures only (`make-files.php` regenerates the uploads).

## What each smoke covers

| Smoke | Phase |
| --- | --- |
| `smoke-phase1-3` | profile, college list, applications, Ask/Agent modes, approvals |
| `smoke-phase4` | guided onboarding, document reading and review |
| `smoke-phase5` | knowledge base, sourced research, conflicts, isolation |
| `smoke-phase6` | discovery, assessments, suggestions review, application workspace |
| `smoke-phase7` | durable tasks on a real worker: plans, approvals, cancel, recovery |
| `smoke-phase8` | writing coach and policies, budget re-evaluation, form plan |
| `smoke-phase9` | exports, signed downloads, expiry, deletion |
| `smoke-phase10` | the unpacked MV3 extension: link, observe, approve, fill, reconnect, stale, wrong tab, revoke |
| `smoke-onboarding` | first-run onboarding in plan order as a fresh student: consent, every section, a transcript read by the worker, review, resume after reload, finish |
| `smoke-mentor-panel` | the mentor side panel: ask from any page, keep the chat across pages and reloads, attach a document the mentor then reads, history, new chat, laptop overlay, phone full screen |

## UI tour (screenshots + layout checks)

```bash
node ui-tour.mjs <label> [--only=dashboard,onboarding] [--viewports=desktop,laptop,mobile] [--theme=light|dark|both]
```

It signs in as the synthetic student and opens every main screen. For each, it saves `shots/ui-<label>-<screen>-<viewport>-<theme>.png` and fails on console errors, 5xx responses and sideways overflow. The results are written to `ui-tour-<label>.json`. The onboarding screen uses `fresh.student@example.test`. `setup-db.sh` creates that student, and `php ui-fixtures.php <app>` resets them to "not onboarded".

`lib.mjs` → `livePage(page)` reads the current Inertia page from `history.state`. Use it after client-side visits: the JSON embedded in the HTML only describes the first load.

