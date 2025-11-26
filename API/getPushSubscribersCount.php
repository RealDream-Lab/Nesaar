<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/license_guard.php';
require_once 'db_init.php';

license_guard_enforce_api();

try {
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

} catch (Exception $e) {
    error_log("Error fetching push subscribers count: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>