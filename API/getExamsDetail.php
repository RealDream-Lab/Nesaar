<?php
// Returns rows from ExamsDetil
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    $stmt = $pdo->query('SELECT id, exam_date, exam_time, required_proctors, students_count, created_at FROM `ExamsDetil` ORDER BY exam_date ASC, exam_time ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    echo json_encode(['success' => true, 'exams' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}

?>