# Environment for the e2e server and setup. Real environment variables override .env values.
E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$E2E_DIR/../.." && pwd)"
# PHP on Windows needs a drive-letter path; elsewhere the POSIX path is fine.
if command -v cygpath > /dev/null 2>&1; then E2E_DB="$(cygpath -m "$E2E_DIR/e2e.sqlite")"; else E2E_DB="$E2E_DIR/e2e.sqlite"; fi
export APP_ENV=local
export APP_DEBUG=true
export APP_URL=http://127.0.0.1:8765
export DB_CONNECTION=sqlite
export DB_DATABASE="$E2E_DB"
export DB_URL=
export QUEUE_CONNECTION="${E2E_QUEUE:-sync}"
export CACHE_STORE=file
export SESSION_DRIVER=file
export BROADCAST_CONNECTION=log
export MAIL_MAILER=log
export BILLING_ENFORCE_PURCHASE_ACCESS=false

# Which provider the AdmitMe agents use in e2e runs (its key must be valid in .env). Override as needed.
export ADMITME_AI_PROVIDER="${E2E_AI_PROVIDER:-anthropic}"
export ADMITME_ANTHROPIC_MODEL="${E2E_ANTHROPIC_MODEL:-claude-haiku-5-5}"

# Phase 5: the synthetic college site served locally (development-only allowances).
export ADMITME_KNOWLEDGE_EXTRA_HOSTS=127.0.0.1:8766,localhost:8766
export ADMITME_KNOWLEDGE_ALLOW_HTTP=true

# Phase 10: a short lease so the "lost acknowledgement" reconnect check does not wait a full minute.
export ADMITME_BROWSER_LEASE_SECONDS=8
