<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();
    csrf_enforce();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id الزامی'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM `Proctors` WHERE id = ?');
    $ok = $stmt->execute([$id]);

    if ($ok) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'خطا در حذف'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
?>