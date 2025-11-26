<?php
/**
 * Push Notification Unsubscribe API
 * Removes or deactivates user's push subscription
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/license_guard.php';
require_once __DIR__ . '/../../includes/csrf_protection.php';
require_once __DIR__ . '/../db_init.php';

// Validate license
$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => $licenseStatus['message'] ?? 'دسترسی ممنوع'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF protection
try {
    csrf_enforce();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'CSRF token invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['endpoint'])) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Endpoint is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Deactivate subscription by endpoint
    $stmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?");
    $stmt->execute([$input['endpoint']]);

    $affected = $stmt->rowCount();

    if ($affected > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'اشتراک با موفقیت لغو شد'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'اشتراکی یافت نشد'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log('Push unsubscribe error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'خطا در لغو اشتراک'], JSON_UNESCAPED_UNICODE);
}
