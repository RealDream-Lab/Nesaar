<?php
/**
 * Get all courses for push notification filtering
 * Returns course_code and course_name for all courses in exam_seats
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once 'db_init.php';

// Validate license
$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'License invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin authentication required
$session = admin_session_require($pdo);

try {
    // Get distinct courses from exam_seats with course info
    $stmt = $pdo->query("
        SELECT DISTINCT 
            es.course_code,
            c.course_name,
            c.exam_date,
            c.exam_time
        FROM exam_seats es
        LEFT JOIN courses c ON es.course_code = c.course_code
        WHERE es.course_code IS NOT NULL AND es.course_code != ''
        ORDER BY c.course_name, es.course_code
    ");

    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'courses' => $courses
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("getCoursesForPush error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ], JSON_UNESCAPED_UNICODE);
}
