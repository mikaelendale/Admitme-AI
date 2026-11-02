#!/usr/bin/env bash
# Runs every browser smoke on the final code, each on a fresh e2e database with its own server and
# queue worker (database queue). The fixture site (site-server.mjs on :8766) must already be running.
set -u
cd "$(dirname "$0")"
mkdir -p logs
SMOKES="${*:-smoke-phase1-3 smoke-phase4 smoke-phase5 smoke-phase6 smoke-phase7 smoke-phase8 smoke-phase9 smoke-phase10 smoke-onboarding smoke-mentor-panel smoke-mentor-inline smoke-dock-resize smoke-agent-crud smoke-mentor-turns smoke-browser-agent}"
SUMMARY=logs/run-all-summary.txt
: > "$SUMMARY"

stop_php() {
    if command -v powershell > /dev/null 2>&1; then
        powershell -NoProfile -ExecutionPolicy Bypass -File stop-php.ps1 > /dev/null 2>&1
    else
        pkill -f '127.0.0.1:8765' > /dev/null 2>&1; pkill -f 'queue:work.*--name=e2e' > /dev/null 2>&1
    fi
    sleep 2
}

for smoke in $SMOKES; do
    stop_php
    if ! E2E_QUEUE=database bash setup-db.sh > "logs/$smoke-setup.log" 2>&1; then
        echo "$smoke: SETUP FAILED (see logs/$smoke-setup.log)" | tee -a "$SUMMARY"
        continue
    fi

    E2E_QUEUE=database bash serve.sh > "logs/$smoke-serve.log" 2>&1 &
    E2E_QUEUE=database bash worker.sh > "logs/$smoke-worker.log" 2>&1 &
    # Mentor replies run as background turns: a worker of their own keeps them from waiting behind long jobs.
    E2E_QUEUE=database bash worker.sh mentor > "logs/$smoke-mentor-worker.log" 2>&1 &

    for _ in $(seq 1 60); do
        [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8765/login)" = "200" ] && break
        sleep 1
    done

    started=$(date +%s)
    timeout 1800 node "$smoke.mjs" > "logs/$smoke.log" 2>&1
    code=$?
    line=$(grep -E "steps passed" "logs/$smoke.log" | tail -1)
    echo "$smoke: ${line:-no summary (exit $code)} [$(( $(date +%s) - started ))s]" | tee -a "$SUMMARY"
done

stop_php
echo "done" >> "$SUMMARY"
