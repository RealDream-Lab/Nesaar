<?php
// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/privileged_session.php';
require_once __DIR__ . '/db_init.php';

header('Content-Type: application/json; charset=utf-8');

// Validate license
$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    echo json_encode([
        'error' => true,
        'message' => $licenseStatus['message'] ?? 'دسترسی به این API به دلیل مشکل لایسنس ممکن نیست.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$session = privileged_session_require($pdo);

// Get exam date and time from query parameters
$examDate = $_GET['exam_date'] ?? '';
$examTime = $_GET['exam_time'] ?? '';

if (empty($examDate) || empty($examTime)) {
    echo json_encode(['error' => 'تاریخ و ساعت آزمون الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Get all courses with this date and time with student count
    // Get all courses with this date and time with student count
    // exam_type is now stored per-seat in exam_seats; derive a course-level value from seats (if any)
    $stmt = $pdo->prepare("
            SELECT 
                c.course_code, 
                c.course_name, 
                c.exam_date, 
                c.exam_time, 
                MAX(es.exam_type) AS exam_type, 
                c.course_type,
                COUNT(es.student_id) as student_count
            FROM courses c
            LEFT JOIN exam_seats es ON c.course_code = es.course_code
            WHERE c.exam_date = ? AND c.exam_time = ?
            GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
            ORDER BY c.course_code
        ");
    $stmt = $pdo->prepare("
            SELECT 
                c.course_code, 
                c.course_name, 
                c.exam_date, 
                c.exam_time, 
                MAX(es.exam_type) AS exam_type, 
                c.course_type,
                COUNT(es.student_id) as student_count
            FROM courses c
            LEFT JOIN exam_seats es ON c.course_code = es.course_code
            WHERE c.exam_date = ? AND c.exam_time = ?
            GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.course_type
            ORDER BY c.course_code
        ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses)) {
        echo json_encode(['error' => 'آزمونی با این تاریخ و ساعت یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Get all course codes for this exam time
    $courseCodes  = array_column($courses, 'course_code');
    $placeholders = str_repeat('?,', count($courseCodes) - 1) . '?';

    // Get all students for these courses with seat info
    $stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            s.national_id,
            s.first_name,
            s.last_name,
            s.degree,
            es.seat_number,
            es.building,
            es.class_name,
            es.seat_row,
            c.course_code,
            c.course_name,
            c.course_type,
                es.exam_type
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE es.course_code IN ($placeholders)
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute($courseCodes);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute counts grouped by course_type for the header breakdown
    $courseTypeCounts = [];
    try {
        $ctStmt = $pdo->prepare("SELECT c.course_type, COUNT(es.student_id) as cnt FROM courses c LEFT JOIN exam_seats es ON c.course_code = es.course_code WHERE c.exam_date = ? AND c.exam_time = ? GROUP BY c.course_type");
        $ctStmt->execute([$examDate, $examTime]);
        $rows = $ctStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $type                    = trim($r['course_type']) ?: 'نامشخص';
            $courseTypeCounts[$type] = (int)$r['cnt'];
        }
    } catch (Exception $e) {
        error_log('getNextExamReport: failed to compute course type counts: ' . $e->getMessage());
    }

    // Compute counts grouped by exam_type for the header breakdown (e.g., کتبی / الکترونیکی)
    $examTypeCounts = [];
    try {
        // Prefer exam_type stored in exam_seats (seat-level). If absent for a seat, it will be ignored in the count.
        $etStmt = $pdo->prepare("SELECT COALESCE(es.exam_type, '') AS exam_type, COUNT(es.student_id) as cnt FROM exam_seats es JOIN courses c ON es.course_code = c.course_code WHERE c.exam_date = ? AND c.exam_time = ? GROUP BY COALESCE(es.exam_type, '')");
        $etStmt->execute([$examDate, $examTime]);
        $erows = $etStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($erows as $r) {
            $type                  = trim($r['exam_type']) ?: 'نامشخص';
            $examTypeCounts[$type] = (int)$r['cnt'];
        }
    } catch (Exception $e) {
        error_log('getNextExamReport: failed to compute exam type counts: ' . $e->getMessage());
    }

    // Check for multi-exam students (students with more than one course in this session)
    $multiExamStudentCount = 0;
    try {
        $multiStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT student_id) as cnt
            FROM (
                SELECT es.student_id
                FROM exam_seats es
                JOIN courses c ON es.course_code = c.course_code
                WHERE c.exam_date = ? AND c.exam_time = ?
                GROUP BY es.student_id
                HAVING COUNT(es.course_code) > 1
            ) multi
        ");
        $multiStmt->execute([$examDate, $examTime]);
        $multiRow              = $multiStmt->fetch(PDO::FETCH_ASSOC);
        $multiExamStudentCount = (int)($multiRow['cnt'] ?? 0);
    } catch (Exception $e) {
        error_log('getNextExamReport: failed to compute multi-exam student count: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'exam_date' => $examDate,
        'exam_time' => $examTime,
        'courses' => $courses,
        'students' => $students,
        'courseTypeCounts' => $courseTypeCounts,
        'examTypeCounts' => $examTypeCounts,
        'multiExamStudentCount' => $multiExamStudentCount
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت اطلاعات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
