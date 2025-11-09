<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    $proctorId = isset($_GET['proctor_id']) ? (int)$_GET['proctor_id'] : 0;
    if ($proctorId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_proctor_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ProctorRestrictions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `proctor_id` INT UNSIGNED NOT NULL,
        `exam_date` VARCHAR(10) NOT NULL,
        `exam_time` VARCHAR(5) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `ux_proctor_date_time` (`proctor_id`,`exam_date`,`exam_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $pdo->prepare('SELECT exam_date, exam_time FROM ProctorRestrictions WHERE proctor_id = ? ORDER BY exam_date, exam_time');
    $stmt->execute([$proctorId]);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    echo json_encode(['success' => true, 'restrictions' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}

?>