<?php
// Return proctors assigned for a given exam_date & exam_time from ExamAssignments
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';

$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => $licenseStatus['message'] ?? 'دسترسی ممنوع'], JSON_UNESCAPED_UNICODE);
    exit;
}

$session = admin_session_require($pdo);

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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $proctors = [];
    foreach ($rows as $r) {
        $proctors[] = ['proctor_id' => $r['proctor_id'], 'proctor_name' => $r['proctor_name']];
    }
    echo json_encode(['success' => true, 'exam_date' => $examDate, 'exam_time' => $examTime, 'proctors' => $proctors], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت تخصیص‌ها: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
