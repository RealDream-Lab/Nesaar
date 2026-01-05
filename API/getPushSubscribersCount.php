<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/license_guard.php';
require_once 'db_init.php';

license_guard_enforce_api();

try {
    // Check for filters in query parameters
    // Support multiple values via comma-separated strings
    $filterDates   = isset($_GET['dates']) ? array_filter(explode(',', $_GET['dates'])) : [];
    $filterSession = isset($_GET['session']) ? trim($_GET['session']) : '';
    $filterCourses = isset($_GET['courses']) ? array_filter(explode(',', $_GET['courses'])) : [];
    $filterMode    = isset($_GET['mode']) ? trim($_GET['mode']) : 'session'; // 'session' or 'course'

    $hasFilters = !empty($filterDates) || !empty($filterSession) || !empty($filterCourses);

    if ($hasFilters) {
        // Count filtered students based on exam_seats
        // We need students who have subscribed AND have exams matching the filter
        $query  = "SELECT COUNT(DISTINCT ps.user_id) as count 
                  FROM push_subscriptions ps
                  INNER JOIN exam_seats es ON ps.user_id = es.student_number
                  WHERE ps.user_type = 'student' AND ps.is_active = 1";
        $params = [];

        if ($filterMode === 'session') {
            // Session mode: filter by dates and optionally session time
            if (!empty($filterDates)) {
                $placeholders  = implode(',', array_fill(0, count($filterDates), '?'));
                $query        .= " AND es.date IN ($placeholders)";
                $params        = array_merge($params, $filterDates);
            }

            if (!empty($filterSession)) {
                // Session format: "date|time" - extract time part
                $parts = explode('|', $filterSession);
                if (count($parts) === 2) {
                    $query    .= " AND es.date = ? AND es.session = ?";
                    $params[]  = $parts[0];
                    $params[]  = $parts[1];
                }
            }
        } else {
            // Course mode: filter by course codes
            if (!empty($filterCourses)) {
                $placeholders  = implode(',', array_fill(0, count($filterCourses), '?'));
                $query        .= " AND es.course_code IN ($placeholders)";
                $params        = array_merge($params, $filterCourses);
            }
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $filteredCount = $stmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'filtered_students' => (int)$filteredCount
        ]);
    } else {
        // Count active students
        $stmtStudent = $pdo->prepare("SELECT COUNT(*) as count FROM push_subscriptions WHERE user_type = 'student' AND is_active = 1");
        $stmtStudent->execute();
        $studentCount = $stmtStudent->fetchColumn();

        // Count active proctors
        $stmtProctor = $pdo->prepare("SELECT COUNT(*) as count FROM push_subscriptions WHERE user_type = 'proctor' AND is_active = 1");
        $stmtProctor->execute();
        $proctorCount = $stmtProctor->fetchColumn();

        echo json_encode([
            'success' => true,
            'students' => (int)$studentCount,
            'proctors' => (int)$proctorCount
        ]);
    }

} catch (Exception $e) {
    error_log("Error fetching push subscribers count: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>