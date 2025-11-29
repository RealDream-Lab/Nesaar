<?php
/**
 * Returns locations with student counts for ALL exam sessions at once
 * This is more efficient than fetching per-session when loading the page
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    // Get all unique sessions (date + time combinations)
    $sessionsStmt = $pdo->query("
        SELECT DISTINCT exam_date, exam_time 
        FROM courses 
        WHERE exam_date IS NOT NULL AND exam_time IS NOT NULL
        ORDER BY exam_date, exam_time
    ");
    $sessions     = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get locations with student counts for all sessions in one query
    // Group by date, time, building, class
    $stmt         = $pdo->query("
        SELECT 
            c.exam_date,
            c.exam_time,
            es.building,
            es.class_name,
            COUNT(DISTINCT es.student_id) AS student_count,
            COALESCE(MAX(l.required_proctors), 0) AS required_proctors
        FROM exam_seats es
        JOIN courses c ON es.course_code COLLATE utf8mb4_unicode_ci = c.course_code COLLATE utf8mb4_unicode_ci
        LEFT JOIN locations l ON es.building COLLATE utf8mb4_unicode_ci = l.building COLLATE utf8mb4_unicode_ci 
            AND es.class_name COLLATE utf8mb4_unicode_ci = l.class_name COLLATE utf8mb4_unicode_ci
        WHERE c.exam_date IS NOT NULL AND c.exam_time IS NOT NULL
        GROUP BY c.exam_date, c.exam_time, es.building, es.class_name
        ORDER BY c.exam_date, c.exam_time, es.building, es.class_name
    ");
    $allLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get max capacity per location (max students ever in that location across all sessions)
    $capacityStmt = $pdo->query("
        SELECT 
            es.building,
            es.class_name,
            MAX(session_count) AS max_capacity
        FROM (
            SELECT 
                es2.building,
                es2.class_name,
                c2.exam_date,
                c2.exam_time,
                COUNT(DISTINCT es2.student_id) AS session_count
            FROM exam_seats es2
            JOIN courses c2 ON es2.course_code COLLATE utf8mb4_unicode_ci = c2.course_code COLLATE utf8mb4_unicode_ci
            GROUP BY es2.building, es2.class_name, c2.exam_date, c2.exam_time
        ) AS es
        GROUP BY es.building, es.class_name
    ");
    $capacities   = $capacityStmt->fetchAll(PDO::FETCH_ASSOC);

    // Create a lookup map for capacities
    $capacityMap = [];
    foreach ($capacities as $cap) {
        $key               = $cap['building'] . '|' . $cap['class_name'];
        $capacityMap[$key] = (int)$cap['max_capacity'];
    }

    // Group locations by session (date|time)
    $result = [];
    foreach ($allLocations as $loc) {
        $sessionKey = $loc['exam_date'] . '|' . $loc['exam_time'];
        if (!isset($result[$sessionKey])) {
            $result[$sessionKey] = [];
        }

        // Add max_capacity from the lookup map
        $locKey              = $loc['building'] . '|' . $loc['class_name'];
        $loc['max_capacity'] = $capacityMap[$locKey] ?? 0;

        $result[$sessionKey][] = [
            'building' => $loc['building'],
            'class_name' => $loc['class_name'],
            'student_count' => (int)$loc['student_count'],
            'required_proctors' => (int)$loc['required_proctors'],
            'max_capacity' => (int)$loc['max_capacity']
        ];
    }

    echo json_encode([
        'success' => true,
        'sessions' => $result
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("getAllSessionLocations error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
