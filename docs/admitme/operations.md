# Operations runbook

How to run, watch and recover AdmitMe in production. Settings are listed in `.env.example`; architecture choices are in [`decisions.md`](decisions.md).

## Processes that must be running

| Process | Command | Why |
| --- | --- | --- |
| Web | your PHP host (PHP 8.3+) | the app and the extension API (`/api/browser/*`, HTTPS only in production) |
| Queue worker | `php artisan queue:work --tries=1 --timeout=600` (keep it running, e.g. with Supervisor) | task steps, document extraction, exports, summaries, research. Jobs set their own attempt counts; task steps (`RunAgentTaskJob`) are never retried by the queue because the orchestrator owns retries |
| Scheduler | `php artisan schedule:run` every minute (cron) | `admitme:tasks:recover` (every minute), `admitme:prune-data` (hourly), `admitme:health --log` (hourly), deadline/pass emails (daily) |
| Reverb (optional) | `php artisan reverb:start` | live council feed; everything else polls stored state |

## Daily and weekly checks

- `php artisan admitme:health` covers:
  - **Queue:** backlog and oldest job (database queue), plus failed jobs in the last 24 h.
  - **Stuck work:** task steps past their lease or queued for over 15 minutes, and exports unfinished after an hour.
  - **Knowledge:** shared sources past their re-check date.
  - **Configuration:** whether the mentor's provider has an API key. Keys are never printed, and a present key can still be rejected (watch the logs for 401s).
  - **Storage:** whether private storage is writable.

  It exits non-zero on a failure, so it can feed an uptime monitor. The scheduler also runs it hourly with `--log`.
- `php artisan admitme:metrics --days=7` reports:
  - **Mentor:** turns, failures, p50/p90 latency, tokens, tool outcomes.
  - **Specialists:** runs, failures, latency, tokens, models and code versions.
  - **Research:** the share of claims that are source-supported.
  - **Documents:** extraction corrections (accepted as read / corrected / rejected).
  - **Approvals:** approved, partially approved, rejected, stale or expired, and items the student edited.
  - **Tasks:** completion, retries, steps needing the student, latency, tokens per completed goal.
  - **Browser:** typed-and-verified vs wrong-or-unverified fills, stale and failed steps.
  - **Integrity checks:** each must be 0. It exits non-zero if one fails; `--json` gives machine-readable output.

## Provider failures and budgets

- Mentor turns are never retried automatically. A failed stream shows the student a failure message, records the run as `failed`, and changes nothing.
- Task steps retry provider errors with backoff (`ADMITME_TASKS_RETRY_BACKOFF`), up to the step's attempts. Then they fail visibly; a provider rate limit makes steps wait (`ADMITME_TASKS_PROVIDER_CALLS_PER_MINUTE`) instead of failing. A retry re-runs only the step's model call: proposals are created once, and nothing that changes student data runs without their approval. There is **no automatic failover to another model** that could replay side effects.
- Budgets:
  - each step has a maximum number of model calls;
  - concurrency is capped per student (`ADMITME_TASKS_MAX_RUNNING`) and overall (`ADMITME_TASKS_MAX_RUNNING_GLOBAL`);
  - plan quotas cap mentor messages and paid features per student.
- To switch provider or model, set `ADMITME_AI_PROVIDER` and the model variables, run `php artisan admitme:eval all --compare=…` against the last saved results, and check `admitme:health`.

## Backups and restoration

The app does not back itself up; configure both of these with the host.

1. **Database.** Use the provider's point-in-time restore (for Postgres on Neon: check the project's history-retention window). Keep 30 days. Test a restore into a branch or staging database at least once per quarter, then run `php artisan migrate:status` and `php artisan admitme:health` against it.
2. **Private files.** Documents and export archives are under `storage/app/private`, or the configured disk. Back up that path (or use object storage with versioning) with the same retention. Export archives are short-lived and can be excluded.
3. **Deletion and backups.** A student's deletion removes live data at once. Copies in backups expire when the backups roll over (30 days). Say this in the privacy policy.

To restore one student's data after an operator mistake, restore into a separate database and copy only that student's rows. Never overwrite the live database to recover one account.

## Retention (defaults)

| Data | Kept | Setting |
| --- | --- | --- |
| Export archives | 24 hours | `ADMITME_EXPORT_EXPIRES_HOURS` |
| Mentor run records (what each run was given, tool outcomes) | 90 days | `ADMITME_CONTEXT_SNAPSHOT_DAYS` |
| Browser observations (what a form showed) | 30 days | `ADMITME_BROWSER_SNAPSHOT_DAYS` |
| Extension link codes | 1 day after expiry | — |
| Application logs | 14 days | `LOG_DAILY_DAYS` |
| Documents, writing, profile, applications | until the student deletes them or their account | — |

Run records store names, versions, durations, token counts and outcome labels. They never store message text, tool arguments or tool results. Errors are redacted (keys, bearer tokens, emails) before they are stored or logged.

## Shared knowledge freshness

A source not re-checked within `ADMITME_KNOWLEDGE_STALE_DAYS` (30) is shown to students as stale, and `admitme:health` counts them. Students and the mentor can re-check sources (the `refresh_sources` task); requirements from a changed page replace the old ones and stay unverified until reviewed.

## Evaluation before releases

- `php artisan admitme:eval all --save` runs the synthetic research and writing set through the production prompts and validators with real model calls (about 7 calls). It writes nothing to the database and saves results under `storage/app/private/evals`.
- Compare with `--compare=evals/<file>`.
- Add a case for every real failure: see [`regression-scenarios.md`](regression-scenarios.md).
