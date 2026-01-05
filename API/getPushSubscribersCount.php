<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/license_guard.php';
require_once 'db_init.php';

license_guard_enforce_api();

try {
    // Check for filters in query parameters
    $filterDate    = isset($_GET['date']) ? trim($_GET['date']) : '';
    $filterSession = isset($_GET['session']) ? trim($_GET['session']) : '';
    $filterCourse  = isset($_GET['course']) ? trim($_GET['course']) : '';

    $hasFilters = !empty($filterDate) || !empty($filterSession) || !empty($filterCourse);

    if ($hasFilters) {
        // Count filtered students based on exam_seats
        $query  = "SELECT COUNT(DISTINCT ps.user_id) as count 
                  FROM push_subscriptions ps
                  INNER JOIN exam_seats es ON ps.user_id = es.student_number
                  WHERE ps.user_type = 'student' AND ps.is_active = 1";
        $params = [];

        if (!empty($filterDate)) {
            $query    .= " AND es.date = ?";
            $params[]  = $filterDate;
        }

        if (!empty($filterSession)) {
            $query    .= " AND es.session = ?";
            $params[]  = $filterSession;
        }

        if (!empty($filterCourse)) {
            $query    .= " AND es.course_code = ?";
            $params[]  = $filterCourse;
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