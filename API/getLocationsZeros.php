<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    // Count locations with required_proctors == 0
    $q = $pdo->query('SELECT COUNT(*) AS c FROM `locations` WHERE required_proctors = 0');
    $count = (int)($q->fetch()['c'] ?? 0);
    echo json_encode(['zeros' => $count], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در بررسی مکان‌ها'], JSON_UNESCAPED_UNICODE);
}

?>
