#!/usr/bin/env bash
# Queue worker for queued e2e runs (E2E_QUEUE=database). Uses the same environment as serve.sh.
set -euo pipefail
source "$(dirname "$0")/env.sh"
cd "$APP_DIR"
# Queues to work (default: mentor replies first, then everything else). run-all also starts one for mentor only.
QUEUES="${1:-mentor,default}"
exec php artisan queue:work --queue="$QUEUES" --sleep=1 --tries=1 --timeout=900 --name=e2e
