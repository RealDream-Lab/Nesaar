<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    // Ensure caller is allowed
    license_guard_enforce_api();

    // Check if `locations` table exists in current DB
    $stmt = $pdo->query('SELECT DATABASE() AS db');
    $dbName = $stmt ? ($stmt->fetch()['db'] ?? '') : '';
    if (!$dbName) {
        echo json_encode(['error' => 'DB name not resolved'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $check = $pdo->prepare('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $check->execute([$dbName, 'locations']);
    $exists = (int)($check->fetch()['cnt'] ?? 0) > 0;

    if (!$exists) {
        echo json_encode(['locations' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = $pdo->query('SELECT COUNT(*) AS c FROM `locations`');
    $count = (int)($q->fetch()['c'] ?? 0);

    echo json_encode(['locations' => $count], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت شمار مکان‌ها'], JSON_UNESCAPED_UNICODE);
}

?>
