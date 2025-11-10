<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();
    csrf_enforce();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id الزامی'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM `Proctors` WHERE id = ?');
    $ok = $stmt->execute([$id]);

    if ($ok) {
        // Ensure ProctorRestrictions table exists (safe no-op if already present)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ProctorRestrictions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `proctor_id` INT UNSIGNED NOT NULL,
            `exam_date` VARCHAR(10) NOT NULL,
            `exam_time` VARCHAR(5) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `ux_proctor_date_time` (`proctor_id`,`exam_date`,`exam_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Delete any restrictions associated with this proctor to keep DB consistent
        try {
            $dstmt = $pdo->prepare('DELETE FROM `ProctorRestrictions` WHERE proctor_id = ?');
            $dstmt->execute([$id]);
        } catch (Throwable $inner) {
            // ignore — table may not exist or other non-fatal issue; continue
        }

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'خطا در حذف'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
?>