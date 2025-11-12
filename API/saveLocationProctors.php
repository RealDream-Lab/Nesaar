<?php
// Accepts JSON: { id: int, required_proctors: int, csrf_token?: string }
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';

try {
    csrf_enforce();
    license_guard_enforce_api();
    $session = admin_session_require($pdo);

    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $req = isset($input['required_proctors']) ? (int)$input['required_proctors'] : null;

    if ($id <= 0 || $req === null || $req < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_input'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ensure the row exists
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM locations WHERE id = ?');
    $stmt->execute([$id]);
    $exists = (int)($stmt->fetch()['c'] ?? 0) > 0;
    if (!$exists) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $upd = $pdo->prepare('UPDATE locations SET required_proctors = ? WHERE id = ?');
    $ok = $upd->execute([$req, $id]);
    if ($ok) {
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'db_error'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}

?>
