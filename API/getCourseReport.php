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

// Get course code from query parameter
$courseCode = $_GET['course_code'] ?? '';

if (empty($courseCode)) {
    echo json_encode(['error' => 'کد درس الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/db_init.php';
    
    // Get course info
    $stmt = $pdo->prepare("
        SELECT course_code, course_name, exam_date, exam_time, 
               exam_type, course_type
        FROM courses 
        WHERE course_code = ?
    ");
    $stmt->execute([$courseCode]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$course) {
        echo json_encode(['error' => 'درسی با این کد یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Get students enrolled in this course with seat info
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
            es.seat_row
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        WHERE es.course_code = ?
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$courseCode]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'course' => $course,
        'students' => $students
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت اطلاعات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
