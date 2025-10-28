<?php
// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';

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

// Check admin authentication
$adminSession = $_COOKIE['adminSession'] ?? null;
if (!$adminSession) {
    http_response_code(401);
    echo json_encode(['error' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $session = json_decode(urldecode($adminSession), true);
    if (!$session || $session['type'] !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get exam date and time from query parameters
$examDate = $_GET['exam_date'] ?? '';
$examTime = $_GET['exam_time'] ?? '';

if (empty($examDate) || empty($examTime)) {
    echo json_encode(['error' => 'تاریخ و ساعت آزمون الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/db_init.php';
    
    // Get all courses with this date and time with student count
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time, 
            c.exam_type, 
            c.course_type,
            COUNT(es.student_id) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date = ? AND c.exam_time = ?
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time, c.exam_type, c.course_type
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($courses)) {
        echo json_encode(['error' => 'آزمونی با این تاریخ و ساعت یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Get all course codes for this exam time
    $courseCodes = array_column($courses, 'course_code');
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
            c.exam_type
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE es.course_code IN ($placeholders)
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute($courseCodes);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'exam_date' => $examDate,
        'exam_time' => $examTime,
        'courses' => $courses,
        'students' => $students
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت اطلاعات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
