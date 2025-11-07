<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    // Ensure locations table exists
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
        echo json_encode(['locations' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = $pdo->query('SELECT id, building, class_name, required_proctors FROM locations ORDER BY building ASC, class_name ASC');
    $rows = $q->fetchAll();

    echo json_encode(['locations' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت مکان‌ها'], JSON_UNESCAPED_UNICODE);
}

?>
