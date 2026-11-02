<?php

// Switch the e2e SQLite file to WAL journaling (persistent). Usage: php wal.php <path-to-e2e.sqlite>
$path = $argv[1] ?? '';

if (! str_ends_with($path, 'e2e.sqlite')) {
    fwrite(STDERR, "Refusing: {$path} is not the e2e database\n");
    exit(1);
}

$pdo = new PDO('sqlite:'.$path);
echo 'journal_mode='.$pdo->query('PRAGMA journal_mode=WAL')->fetchColumn().PHP_EOL;
