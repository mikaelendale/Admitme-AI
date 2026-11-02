#!/usr/bin/env bash
# Run a PHP helper or artisan command against the e2e database only.
set -euo pipefail
source "$(dirname "$0")/env.sh"
export APP_PATH="$APP_DIR"
cd "$APP_DIR"
if [ "${1:-}" = "artisan" ]; then shift; exec php artisan "$@"; fi
exec php "$E2E_DIR/inspect.php" "$@"
