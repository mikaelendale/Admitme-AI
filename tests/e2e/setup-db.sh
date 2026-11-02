#!/usr/bin/env bash
# Recreate the e2e database and seed synthetic data. Refuses to run against any other database.
set -euo pipefail
source "$(dirname "$0")/env.sh"
cd "$APP_DIR"

configured=$(php artisan tinker --execute="echo config('database.connections.sqlite.database');" 2>/dev/null | tail -1)
case "$configured" in
  *e2e.sqlite) ;;
  *) echo "REFUSING: configured database is '$configured', not the e2e database." >&2; exit 1 ;;
esac

# Never reset under live connections: deleting the WAL/SHM files of an open SQLite database corrupts it.
# On Windows an open file cannot be deleted, so a failed delete means the e2e server or worker is running.
if ! rm -f "$DB_DATABASE" 2>/dev/null; then
  echo "REFUSING: $DB_DATABASE is in use. Stop the e2e server and queue worker, then run setup again." >&2
  exit 1
fi
rm -f "$DB_DATABASE-wal" "$DB_DATABASE-shm"
touch "$DB_DATABASE"
php artisan migrate:fresh --force -q
php artisan db:seed --class=AdmitMeSyntheticStudentSeeder --force -q
# In queued runs a worker and the server write at the same time; WAL lets readers and one writer coexist.
php "$E2E_DIR/wal.php" "$DB_DATABASE"
php "$E2E_DIR/fixtures.php" "$APP_DIR"
php "$E2E_DIR/ui-fixtures.php" "$APP_DIR"
echo "e2e database ready: $configured"
