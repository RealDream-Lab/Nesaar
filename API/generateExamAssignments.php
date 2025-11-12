<?php
// Generate ExamAssignments table rows from ExamsDetil required_proctors
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/db_init.php';

try {
    csrf_enforce();
    license_guard_enforce_api();

    $session = admin_session_require($pdo);

    $rateLimitKey = 'generate_assignments:' . ($session['username'] ?? 'unknown');
    // Allow higher rate for generating assignments during bulk operations
    rate_limit_enforce($pdo, $rateLimitKey, 100, 120);

    // Drop (preview refresh) and recreate table with the same schema used by assignScattered apply
    $pdo->exec("DROP TABLE IF EXISTS `ExamAssignments`");
    $pdo->exec("CREATE TABLE `ExamAssignments` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `exam_date` CHAR(10) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `exam_time` CHAR(5) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `proctor_id` INT NULL,
        `proctor_name` VARCHAR(120) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Keep the same unique key as apply path; NULL proctor_id allows multiple preview rows per session
    try {
        $pdo->exec("ALTER TABLE `ExamAssignments` ADD UNIQUE KEY `uniq_session_proctor` (`exam_date`,`exam_time`,`proctor_id`)");
    } catch (Throwable $e) { /* ignore if index exists or cannot be added now */ }

    // Fetch exams detail
    $stmt = $pdo->query("SELECT exam_date, exam_time, required_proctors FROM `ExamsDetil` ORDER BY exam_date, exam_time");
    $exams = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // For preview we leave proctor_id NULL and proctor_name empty.
    $insertStmt = $pdo->prepare("INSERT INTO `ExamAssignments` (exam_date, exam_time, proctor_id, proctor_name) VALUES (?, ?, NULL, '')");

    $totalInserted = 0;

    foreach ($exams as $ex) {
        $date = $ex['exam_date'] ?? '';
        $time = $ex['exam_time'] ?? '';
        $rp = intval($ex['required_proctors'] ?? 0);

        // Insert rp rows for this session
        for ($i = 0; $i < $rp; $i++) {
            $insertStmt->execute([$date, $time]);
            $totalInserted++;
        }
    }

    echo json_encode(['success' => true, 'inserted' => $totalInserted], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>