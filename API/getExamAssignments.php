<?php
// Return proctors assigned for a given exam_date & exam_time from ExamAssignments
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/privileged_session.php';
require_once __DIR__ . '/db_init.php';

$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => $licenseStatus['message'] ?? 'دسترسی ممنوع'], JSON_UNESCAPED_UNICODE);
    exit;
}

$session = privileged_session_require($pdo);

// Quick check mode - just verify if any assignments exist
$checkOnly = isset($_GET['check_only']) && $_GET['check_only'] === '1';
if ($checkOnly) {
    try {
        $stmt  = $pdo->query('SELECT COUNT(*) FROM `ExamAssignments` WHERE TRIM(IFNULL(proctor_name, "")) != ""');
        $count = (int)$stmt->fetchColumn();
        echo json_encode(['success' => true, 'hasAssignments' => $count > 0, 'count' => $count], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'hasAssignments' => false], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$examDate = $_GET['exam_date'] ?? '';
$examTime = $_GET['exam_time'] ?? '';
if (empty($examDate) || empty($examTime)) {
    echo json_encode(['error' => 'تاریخ و ساعت آزمون الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Ensure table exists
    $stmt = $pdo->prepare('SELECT proctor_id, proctor_name FROM `ExamAssignments` WHERE exam_date = ? AND exam_time = ? AND TRIM(IFNULL(proctor_name, "")) != "" ORDER BY proctor_name');
    $stmt->execute([$examDate, $examTime]);
    $rows     = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $proctors = [];
    foreach ($rows as $r) {
        $proctors[] = ['proctor_id' => $r['proctor_id'], 'proctor_name' => $r['proctor_name']];
    }
    $assignedTotal = count($proctors);

    $requiredTotal = 0;
    try {
        $sumStmt = $pdo->prepare('SELECT SUM(required_proctors) AS total FROM `ExamsDetil` WHERE exam_date = ? AND exam_time = ?');
        $sumStmt->execute([$examDate, $examTime]);
        $val = $sumStmt->fetchColumn();
        if ($val !== false && $val !== null) {
            $requiredTotal = (int)$val;
        }
    } catch (PDOException $sumErr) {
        error_log('getExamAssignments: failed to compute required proctor total: ' . $sumErr->getMessage());
    }

    // Calculate total proctors and restricted proctors for this session
    $totalProctors   = 0;
    $restrictedCount = 0;
    try {
        // Total proctors
        $tpStmt        = $pdo->query('SELECT COUNT(*) FROM `Proctors`');
        $totalProctors = (int)$tpStmt->fetchColumn();

        // Restricted proctors for this session
        // Ensure table exists first (lazy check)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ProctorRestrictions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `proctor_id` INT UNSIGNED NOT NULL,
            `exam_date` VARCHAR(10) NOT NULL,
            `exam_time` VARCHAR(5) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `ux_proctor_date_time` (`proctor_id`,`exam_date`,`exam_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $rcStmt = $pdo->prepare('SELECT COUNT(*) FROM `ProctorRestrictions` WHERE exam_date = ? AND exam_time = ?');
        $rcStmt->execute([$examDate, $examTime]);
        $restrictedCount = (int)$rcStmt->fetchColumn();
    } catch (Throwable $e) {
        // Ignore errors, default to 0
    }

    echo json_encode([
        'success' => true,
        'exam_date' => $examDate,
        'exam_time' => $examTime,
        'proctors' => $proctors,
        'assigned_total' => $assignedTotal,
        'required_total' => $requiredTotal,
        'total_proctors' => $totalProctors,
        'restricted_count' => $restrictedCount
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت تخصیص‌ها: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
