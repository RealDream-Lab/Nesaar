<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    $tableExists = false;
    try {
        $check       = $pdo->query("SHOW TABLES LIKE 'ExamAssignments'");
        $tableExists = $check && $check->rowCount() > 0;
    } catch (Throwable $ignored) {
        $tableExists = false;
    }

    $count = 0;
    if ($tableExists) {
        try {
            $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM `ExamAssignments` WHERE TRIM(IFNULL(proctor_name, "")) != ""');
            if ($stmt) {
                $count = (int)($stmt->fetch()['cnt'] ?? 0);
            }
        } catch (Throwable $ignored) {
            $count = 0;
        }
    }

    echo json_encode([
        'success' => true,
        'table_exists' => $tableExists,
        'count' => $count,
        'has_assignments' => $count > 0,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'خطا در دریافت وضعیت تخصیص‌ها'
    ], JSON_UNESCAPED_UNICODE);
}

?>