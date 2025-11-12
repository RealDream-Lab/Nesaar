#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/API/db_init.php';
require_once $root . '/includes/audit_log.php';

$keepDays = 90;
if (isset($argv[1])) {
    $candidate = (int)$argv[1];
    if ($candidate > 0) {
        $keepDays = $candidate;
    }
}

$deleted = audit_cleanup($pdo, $keepDays);
$timestamp = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d H:i:s');

$summary = sprintf(
    '[%s] audit_cleanup: removed %d record(s) older than %d day(s).%s',
    $timestamp,
    $deleted,
    $keepDays,
    PHP_EOL
);

echo $summary;
