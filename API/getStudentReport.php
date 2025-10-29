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

// Get student ID from query parameter
$studentId = $_GET['student_id'] ?? '';

if (empty($studentId)) {
    echo json_encode(['error' => 'شماره دانشجویی الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/db_init.php';
    
    // Get student info
    $stmt = $pdo->prepare("
        SELECT student_id, national_id, first_name, last_name, degree, 
               source_center, destination_center
        FROM students 
        WHERE student_id = ?
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['error' => 'دانشجویی با این شماره یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Get student exams with seat and course info
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code,
            c.course_name,
            c.exam_date,
            c.exam_time,
            es.exam_type AS exam_type,
            c.course_type,
            es.seat_number,
            es.building,
            es.class_name,
            es.seat_row
        FROM exam_seats es
        JOIN courses c ON es.course_code = c.course_code
        WHERE es.student_id = ?
        ORDER BY c.exam_date, c.exam_time
    ");
    $stmt->execute([$studentId]);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'student' => $student,
        'exams' => $exams
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در دریافت اطلاعات: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
