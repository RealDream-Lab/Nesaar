<?php
/**
 * Returns locations with student counts for a specific exam session (date + time)
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    $examDate = $_GET['exam_date'] ?? '';
    $examTime = $_GET['exam_time'] ?? '';

    if (empty($examDate) || empty($examTime)) {
        echo json_encode(['success' => false, 'error' => 'missing_params'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Get locations with student counts for this session
    // Join exam_seats with courses to get students per building/class for this date/time
    $stmt = $pdo->prepare("
        SELECT 
            es.building,
            es.class_name,
            COUNT(DISTINCT es.student_id) AS student_count,
            COALESCE(MAX(l.required_proctors), 0) AS required_proctors
        FROM exam_seats es
        JOIN courses c ON es.course_code COLLATE utf8mb4_unicode_ci = c.course_code COLLATE utf8mb4_unicode_ci
        LEFT JOIN locations l ON es.building COLLATE utf8mb4_unicode_ci = l.building COLLATE utf8mb4_unicode_ci 
            AND es.class_name COLLATE utf8mb4_unicode_ci = l.class_name COLLATE utf8mb4_unicode_ci
        WHERE c.exam_date = ? AND c.exam_time = ?
        GROUP BY es.building, es.class_name
        ORDER BY es.building ASC, es.class_name ASC
    ");
    $stmt->execute([$examDate, $examTime]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'locations' => $locations
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("getSessionLocations error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
