<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db_init.php';

try {
    // Get total students
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM students");
    $totalStudents = $stmt->fetch()['count'] ?? 0;

    // Get total courses
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM courses");
    $totalCourses = $stmt->fetch()['count'] ?? 0;

    // Get total exam seats
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM exam_seats");
    $totalSeats = $stmt->fetch()['count'] ?? 0;

    echo json_encode([
        'totalStudents' => $totalStudents,
        'totalCourses' => $totalCourses,
        'totalSeats' => $totalSeats
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Statistics error: ' . $e->getMessage());
    echo json_encode(['error' => 'خطا در دریافت آمار'], JSON_UNESCAPED_UNICODE);
}
?>
