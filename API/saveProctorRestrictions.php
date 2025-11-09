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
    $proctorId = isset($input['proctor_id']) ? (int)$input['proctor_id'] : 0;
    $sessions = isset($input['sessions']) && is_array($input['sessions']) ? $input['sessions'] : [];

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

    // Delete existing restrictions for this proctor
    $del = $pdo->prepare('DELETE FROM ProctorRestrictions WHERE proctor_id = ?');
    $del->execute([$proctorId]);

    // Insert new restrictions (if any)
    $inserted = 0;
    if (!empty($sessions)) {
        $stmt = $pdo->prepare('INSERT INTO ProctorRestrictions (proctor_id, exam_date, exam_time) VALUES (?, ?, ?)');
        foreach ($sessions as $s) {
            $d = isset($s['exam_date']) ? trim((string)$s['exam_date']) : '';
            $t = isset($s['exam_time']) ? trim((string)$s['exam_time']) : '';
            if ($d === '' || $t === '') continue;
            $ok = $stmt->execute([$proctorId, $d, $t]);
            if ($ok) $inserted++;
        }
    }

    echo json_encode(['success' => true, 'inserted' => $inserted], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

?>