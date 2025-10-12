<?php
// Lightweight endpoint that exposes the current Tehran date/time for footer clock sync.
require_once __DIR__ . '/jdf.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$currentDate = jdate('Y/m/d', '', '', 'Asia/Tehran', 'en');
$currentTime = jdate('H:i', '', '', 'Asia/Tehran', 'en');

echo json_encode([
    'date' => $currentDate,
    'time' => $currentTime,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
