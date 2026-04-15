# AdmitMe Queue System

## Overview

AdmitMe uses Laravel's built-in **database queue driver** for all background jobs. This means:

- Jobs are stored in the `jobs` table in the application database.
- A long-running PHP process (`queue:work`) polls that table and executes jobs one at a time.
- If no worker is running, **jobs pile up silently and nothing executes** — the student sees a spinner forever.

---

## Queue connection

```
QUEUE_CONNECTION=database   ← default in .env and config/queue.php
```

The relevant database table is `jobs` (created by `0001_01_01_000002_create_jobs_table.php`).

---

## Background jobs in this app

| Class | `job_type` in tracking | What it does |
|---|---|---|
| `BuildUserAiContextJob` | `ai_context` | Rebuilds a student's AI profile context from onboarding data or a pasted freeform resume |
| `ProcessEssayRunJob` | `essay_run` | Runs essay brainstorm or draft agents and writes output to `essay_runs` |
| `RunCollegeListCouncilJob` | `college_list_council` | Runs the full college list council pipeline (preflight → scout → judge → assemble) |
| `CollegeListCouncilAutoContinuePreflightJob` | `council_preflight` | Delayed job that auto-resumes a council run if the student doesn't respond to preflight questions |

All four jobs:
- Are tracked in the `job_tracking` table (status: `pending → running → completed / failed`)
- Send a **database notification** to the user when they permanently fail (after all retries)
- Log full context (user ID, relevant IDs, exception class and message) on failure
- Never email the user on job failure — in-app notification only

---

## Running the worker in development

```bash
php artisan queue:work --tries=3 --timeout=600 --sleep=3
```

- `--tries=3` — attempt each job up to 3 times before moving to `failed_jobs`
- `--timeout=600` — must be **≥** `COLLEGE_LIST_COUNCIL_JOB_TIMEOUT` (default 600). If the worker timeout is lower than a long council run, Laravel will kill the job and you will see `RunCollegeListCouncilJob` / “attempted too many times” in logs even when OpenAI is still working.
- `--sleep=3` — poll the `jobs` table every 3 seconds when idle

For local dev you can also use the sync driver to skip queuing entirely:

```bash
QUEUE_CONNECTION=sync php artisan serve
```

With `sync`, jobs run inline (blocking) and the `job_tracking` table is still updated correctly.

---

## Running the scheduler in development

The scheduler handles deadline notification emails and monthly AI context refreshes:

```bash
php artisan schedule:work
```

Or, in a cron environment, add this single cron entry:

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks defined in this app:

| Command | Schedule | What it does |
|---|---|---|
| `admitme:send-deadline-notifications` | Daily at 08:00 UTC | Sends deadline reminder notifications |
| `admitme:notify-expiring-passes` | Daily at 08:30 UTC | Sends expiry reminders for time-limited passes |
| `refresh-stale-ai-context` (closure) | Monthly on the 1st at 04:00 | Re-queues `BuildUserAiContextJob` for all users with stale profile context (older than 30 days) |

---

## Production — Supervisor config

The worker must run as a persistent daemon managed by Supervisor (or your platform's equivalent).

```ini
[program:admitme-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/admitme/artisan queue:work database --sleep=3 --tries=3 --timeout=600 --max-jobs=500 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/admitme-worker.log
stopwaitsecs=620
```

- `--max-jobs=500` / `--max-time=3600` — restart the worker process periodically to prevent memory leaks
- `numprocs=2` — run 2 worker processes in parallel; increase if job throughput demands it
- `stopwaitsecs` should be slightly higher than `--timeout` to allow clean shutdown

---

## Production — Laravel Cloud / Forge

On Laravel Cloud or Forge, add a queue worker via the UI:

- **Connection**: `database`
- **Queue**: `default`
- **Timeout**: `600`
- **Tries**: `3`
- **Processes**: `2`

---

## What happens if the worker is not running

1. User triggers a college list build → `RunCollegeListCouncilJob` is written to `jobs` table.
2. The job sits in `jobs` with no worker to pick it up.
3. The student sees the spinner and live council feed in the UI, but no events appear.
4. Nothing fails visibly — there is no timeout on the `jobs` table row.
5. If the worker comes back online, **it will pick up and process the job** unless it has expired (very unlikely with the default retry window).

**Diagnosis**: if jobs aren't running, check `php artisan queue:work` is alive. Look at `jobs` table row count.

---

## Checking failed jobs

Failed jobs land in the `failed_jobs` table after exhausting all retries.

```bash
# List all failed jobs
php artisan queue:failed

# Retry a specific failed job by UUID
php artisan queue:retry <uuid>

# Retry all failed jobs
php artisan queue:retry all

# Flush (delete) all failed jobs
php artisan queue:flush
```

---

## Job tracking table

Every job writes a row to `job_tracking` when it starts, and updates it when it completes or fails.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | Auto-increment |
| `user_id` | bigint | FK → users |
| `job_type` | string(80) | `ai_context`, `essay_run`, `college_list_council`, `council_preflight` |
| `status` | string(20) | `pending`, `running`, `completed`, `failed` |
| `payload` | JSON | Relevant IDs (essay_run_id, run_id, etc.) |
| `started_at` | timestamp | When `handle()` began |
| `completed_at` | timestamp | When the job finished successfully |
| `failed_at` | timestamp | When the job permanently failed |
| `error_message` | text | Truncated exception message (max 2000 chars) |
| `created_at` / `updated_at` | timestamps | Standard Laravel timestamps |

This table is the single source of truth for job status per user. The AI mentor agent queries it via `GetActiveJobsTool` to answer student questions like "is my list ready?".

---

## Retry endpoint

Users can retry a failed job without Tinker or server access:

```
POST /jobs/{jobTrackingId}/retry
```

- Verifies the job belongs to the authenticated user
- Re-dispatches the correct job class using the original payload
- Resets the `job_tracking` record to `pending`
- Rate-limited to 20 requests per minute per user

In-app failure notifications include a link to this endpoint as the retry CTA.

---

## Active jobs API

The mentor chat agent (and any frontend polling) can call:

```
GET /api/jobs/active
```

Returns:

```json
{
  "active": [
    {
      "id": 42,
      "type": "college_list_council",
      "label": "Building your college list",
      "status": "running",
      "started_at": "2026-04-14T10:23:00Z",
      "started_ago": "3 minutes ago"
    }
  ],
  "recent_failed": [
    {
      "id": 38,
      "type": "essay_run",
      "label": "Generating your essay",
      "status": "failed",
      "failed_at": "2026-04-14T09:00:00Z",
      "error_message": "Essay agent returned empty content after retry",
      "retry_url": "https://app.admitme.ai/jobs/38/retry"
    }
  ]
}
```

---

## Horizon (recommended for production)

Laravel Horizon is **not currently installed** but is strongly recommended for production monitoring.

**Install it with:**

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan migrate
```

**What Horizon gives you over bare `queue:work`:**

- Real-time web dashboard at `/horizon` showing job throughput, wait times, and failure rates
- Failed job browser with full stack traces — no more `queue:failed` in the terminal
- One-click retry for failed jobs from the UI
- Named queues with separate worker pools and priorities
- Supervisor-like worker management from within Laravel (no separate Supervisor config needed)
- Metrics retention and per-job timing charts

**Run it in production with:**

```bash
php artisan horizon
```

Manage with Supervisor using `php artisan horizon` instead of `queue:work`. See [Laravel Horizon docs](https://laravel.com/docs/horizon) for full setup.
