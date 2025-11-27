<?php
/**
 * Restore Proctors from ProctorsBackup table
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';

try {
    csrf_enforce();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

license_guard_enforce_api();
admin_session_require($pdo);

try {
    // Check if ProctorsBackup table exists and has data
    $checkStmt   = $pdo->query("SELECT COUNT(*) AS cnt FROM `ProctorsBackup`");
    $backupCount = (int)($checkStmt->fetchColumn() ?: 0);

    if ($backupCount === 0) {
        echo json_encode([
            'success' => false,
            'error' => 'no_backup',
            'message' => 'نسخه پشتیبانی برای بازیابی وجود ندارد.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Begin transaction
    $pdo->beginTransaction();

    // Get existing national_ids to avoid duplicates
    $existingStmt = $pdo->query("SELECT national_id FROM `Proctors` WHERE national_id IS NOT NULL AND national_id != ''");
    $existingIds  = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
    $existingSet  = array_flip($existingIds);

    // Get backup proctors
    $backupStmt = $pdo->query("SELECT * FROM `ProctorsBackup`");
    $backups    = $backupStmt->fetchAll(PDO::FETCH_ASSOC);

    $insertedCount = 0;
    $skippedCount  = 0;

    // Insert only non-duplicate proctors
    $insertStmt = $pdo->prepare("INSERT INTO `Proctors` (gender, first_name, last_name, national_id, phone) VALUES (?, ?, ?, ?, ?)");

    foreach ($backups as $proctor) {
        $nationalId = $proctor['national_id'] ?? '';
        if (empty($nationalId))
            continue;

        // Skip if already exists
        if (isset($existingSet[$nationalId])) {
            $skippedCount++;
            continue;
        }

        $insertStmt->execute([
            $proctor['gender'] ?? '',
            $proctor['first_name'] ?? '',
            $proctor['last_name'] ?? '',
            $nationalId,
            $proctor['phone'] ?? ''
        ]);
        $insertedCount++;
        $existingSet[$nationalId] = true; // Mark as added
    }

    $pdo->commit();

    // Get total count
    $totalStmt  = $pdo->query("SELECT COUNT(*) AS cnt FROM `Proctors`");
    $totalCount = (int)($totalStmt->fetchColumn() ?: 0);

    $message = "بازیابی موفق: {$insertedCount} مراقب جدید اضافه شد.";
    if ($skippedCount > 0) {
        $message .= " ({$skippedCount} مراقب تکراری نادیده گرفته شد)";
    }

    echo json_encode([
        'success' => true,
        'restored' => $insertedCount,
        'skipped' => $skippedCount,
        'total' => $totalCount,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Check if table doesn't exist
    if (strpos($e->getMessage(), "doesn't exist") !== false) {
        echo json_encode([
            'success' => false,
            'error' => 'no_backup',
            'message' => 'نسخه پشتیبانی برای بازیابی وجود ندارد.'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        error_log('Restore proctors failed: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'server_error',
            'message' => 'خطا در بازیابی اطلاعات'
        ], JSON_UNESCAPED_UNICODE);
    }
}
