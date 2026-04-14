<?php

declare(strict_types=1);

$dbPath = __DIR__.'/../database/database.sqlite';

if (! file_exists($dbPath)) {
    fwrite(STDERR, "SQLite DB not found at {$dbPath}\n");
    exit(1);
}

$pdo = new PDO('sqlite:'.$dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function scalar(PDO $pdo, string $sql): int|string|null
{
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! is_array($row)) {
        return null;
    }
    $v = array_values($row)[0] ?? null;

    return $v;
}

echo 'jobs_count='.scalar($pdo, 'select count(*) from jobs').PHP_EOL;
echo 'failed_jobs_count='.scalar($pdo, 'select count(*) from failed_jobs').PHP_EOL;
echo 'now_unix='.time().PHP_EOL;

$failed = $pdo->query('select id,uuid,failed_at,substr(exception,1,250) as ex from failed_jobs order by id desc limit 3')
    ->fetchAll(PDO::FETCH_ASSOC);
if ($failed !== []) {
    echo 'failed_jobs (latest 3)'.PHP_EOL;
    foreach ($failed as $f) {
        echo "- failed#{$f['id']} uuid={$f['uuid']} failed_at={$f['failed_at']} ex_prefix=".str_replace(["\n", "\r"], ' ', (string) $f['ex']).PHP_EOL;
    }
}

$rows = $pdo->query('select id, queue, attempts, reserved_at, available_at, created_at from jobs order by id desc limit 10')
    ->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $reservedAge = $r['reserved_at'] ? (time() - (int) $r['reserved_at']) : null;
    $availableIn = $r['available_at'] ? ((int) $r['available_at'] - time()) : null;
    echo "- job#{$r['id']} queue={$r['queue']} attempts={$r['attempts']} reserved_at={$r['reserved_at']} reserved_age_s=".($reservedAge ?? 'null')." available_at={$r['available_at']} available_in_s=".($availableIn ?? 'null').PHP_EOL;
}

$track = $pdo->query('select id,user_id,job_type,status,started_at,completed_at,failed_at,substr(error_message,1,120) as error from job_tracking order by id desc limit 10')
    ->fetchAll(PDO::FETCH_ASSOC);

echo 'job_tracking (latest 10)'.PHP_EOL;
foreach ($track as $t) {
    echo "- tracking#{$t['id']} user={$t['user_id']} type={$t['job_type']} status={$t['status']} started_at={$t['started_at']} failed_at={$t['failed_at']} error=".($t['error'] ?? '').PHP_EOL;
}

$runs = $pdo->query('select id,user_id,status,intent,created_at,started_at,completed_at from college_list_runs order by rowid desc limit 10')
    ->fetchAll(PDO::FETCH_ASSOC);
echo 'college_list_runs (latest 10)'.PHP_EOL;
foreach ($runs as $r) {
    echo "- run={$r['id']} user={$r['user_id']} status={$r['status']} intent={$r['intent']} started_at={$r['started_at']} completed_at={$r['completed_at']}".PHP_EOL;
}
