#!/usr/bin/env bash
# Serve the app on 127.0.0.1:8765 against the e2e database.
# Laravel's router script treats the working directory as the public path, so start from public/.
set -euo pipefail
source "$(dirname "$0")/env.sh"
cd "$APP_DIR/public"
exec php -S 127.0.0.1:8765 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
