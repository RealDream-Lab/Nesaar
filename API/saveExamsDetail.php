<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();
    // Enforce CSRF for mutations
    csrf_enforce();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['sessions']) || !is_array($input['sessions'])) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_input'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sessions = $input['sessions'];
    if (!count($sessions)) {
        echo json_encode(['success' => true, 'inserted' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Create table if not exists. Use VARCHAR for exam_date (10 chars) and exam_time (5 chars)
    // because the project stores dates as strings in various formats.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `ExamsDetil` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `exam_date` VARCHAR(10) DEFAULT NULL,
        `exam_time` VARCHAR(5) DEFAULT NULL,
        `required_proctors` INT DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure column types are as expected even if table existed previously
    try {
        $pdo->exec("ALTER TABLE `ExamsDetil` MODIFY COLUMN `exam_date` VARCHAR(10) DEFAULT NULL, MODIFY COLUMN `exam_time` VARCHAR(5) DEFAULT NULL");
    } catch (Throwable $e) {
        // ignore alter errors (older MySQL versions or permissions)
    }

    // Prepare insert and a delete-to-replace per (date,time)
    $ins = $pdo->prepare('INSERT INTO `ExamsDetil` (`exam_date`, `exam_time`, `required_proctors`) VALUES (?, ?, ?)');
    $del = $pdo->prepare('DELETE FROM `ExamsDetil` WHERE `exam_date` = ? AND `exam_time` = ?');

    $inserted = 0;
    foreach ($sessions as $s) {
        $d = isset($s['exam_date']) ? trim((string)$s['exam_date']) : null;
        $t = isset($s['exam_time']) ? trim((string)$s['exam_time']) : null;
        $p = isset($s['proctors']) ? (int)$s['proctors'] : 0;

        // Truncate fields to match schema limits (safeguard)
        if ($d !== null) $d = mb_substr($d, 0, 10);
        if ($t !== null) $t = mb_substr($t, 0, 5);

        // Remove any existing row for same date/time to avoid duplicates
        try {
            $del->execute([$d, $t]);
        } catch (Throwable $e) {
            // ignore delete errors and continue to insert
        }

        $ok = $ins->execute([$d, $t, $p]);
        if ($ok) $inserted++;
    }

    echo json_encode(['success' => true, 'inserted' => $inserted], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
