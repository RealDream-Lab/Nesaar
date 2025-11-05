<?php
// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    // Enforce license for API access
    license_guard_enforce_api();

    // Get current database name safely
    $stmt = $pdo->query('SELECT DATABASE() AS db');
    $dbName = $stmt ? ($stmt->fetch()['db'] ?? '') : '';
    if (!$dbName) {
        echo json_encode(['error' => 'DB name not resolved'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Helper to check existence of a table (with hyphen) and count rows
    $countFor = function(string $table) use ($pdo, $dbName) {
        try {
            $check = $pdo->prepare('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $check->execute([$dbName, $table]);
            $exists = (int)($check->fetch()['cnt'] ?? 0) > 0;
            if (!$exists) return 0;
            // Table exists; count rows using backticked name
            $q = $pdo->query('SELECT COUNT(*) AS c FROM `'.str_replace('`','``',$table).'`');
            return (int)($q->fetch()['c'] ?? 0);
        } catch (Throwable $e) {
            return 0; // If any issue (permissions, etc.), treat as 0
        }
    };

    $eCount = $countFor('e-exams');
    $kCount = $countFor('k-exams');

    echo json_encode([
        'e_exams' => $eCount,
        'k_exams' => $kCount
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت آمار جداول موقت'], JSON_UNESCAPED_UNICODE);
}

?>
