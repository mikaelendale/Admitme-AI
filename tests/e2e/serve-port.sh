#!/usr/bin/env bash
# Serve the app against the e2e database on another port (for parallel UI work): bash serve-port.sh 8771
set -euo pipefail
source "$(dirname "$0")/env.sh"
PORT="${1:?port}"
export APP_URL="http://127.0.0.1:${PORT}"
cd "$APP_DIR/public"
exec php -S "127.0.0.1:${PORT}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
