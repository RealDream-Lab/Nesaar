<?php
// Lightweight endpoint that exposes the current Tehran date/time for footer clock sync.
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/jdf.php';
license_guard_enforce_api();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$currentDate = jdate('Y/m/d', '', '', 'Asia/Tehran', 'en');
$currentTime = jdate('H:i:s', '', '', 'Asia/Tehran', 'en');

echo json_encode([
    'date' => $currentDate,
    'time' => $currentTime,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
