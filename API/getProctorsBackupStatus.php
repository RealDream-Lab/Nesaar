<?php
/**
 * Check if ProctorsBackup table exists and has data
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';

license_guard_enforce_api();
admin_session_require($pdo);

try {
    // Check if ProctorsBackup table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE 'ProctorsBackup'");
    if ($checkTable->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'hasBackup' => false,
            'count' => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Get backup count
    $countStmt = $pdo->query("SELECT COUNT(*) AS cnt FROM `ProctorsBackup`");
    $count     = (int)($countStmt->fetchColumn() ?: 0);

    echo json_encode([
        'success' => true,
        'hasBackup' => $count > 0,
        'count' => $count
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'success' => true,
        'hasBackup' => false,
        'count' => 0
    ], JSON_UNESCAPED_UNICODE);
}
