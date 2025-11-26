<?php
/**
 * Push Notification Subscribe API
 * Saves user's push subscription to database
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

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid JSON input'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate required fields
$required = ['user_type', 'user_id', 'endpoint', 'keys'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => true, 'message' => "Missing required field: {$field}"], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Validate user_type
$validTypes = ['student', 'proctor', 'admin'];
if (!in_array($input['user_type'], $validTypes)) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid user_type'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate keys
if (empty($input['keys']['p256dh']) || empty($input['keys']['auth'])) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Missing encryption keys'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_type` ENUM('student', 'proctor', 'admin') NOT NULL,
        `user_id` VARCHAR(20) NOT NULL,
        `endpoint` TEXT NOT NULL,
        `p256dh` VARCHAR(255) NOT NULL,
        `auth` VARCHAR(255) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        INDEX `idx_user` (`user_type`, `user_id`),
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Check if subscription already exists (by endpoint)
    $stmt = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ? LIMIT 1");
    $stmt->execute([$input['endpoint']]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update existing subscription
        $stmt = $pdo->prepare("UPDATE push_subscriptions SET 
            user_type = ?,
            user_id = ?,
            p256dh = ?,
            auth = ?,
            is_active = 1,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = ?");
        $stmt->execute([
            $input['user_type'],
            $input['user_id'],
            $input['keys']['p256dh'],
            $input['keys']['auth'],
            $existing['id']
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'اشتراک به‌روزرسانی شد',
            'subscription_id' => $existing['id']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Insert new subscription
        $stmt = $pdo->prepare("INSERT INTO push_subscriptions 
            (user_type, user_id, endpoint, p256dh, auth, is_active) 
            VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $input['user_type'],
            $input['user_id'],
            $input['endpoint'],
            $input['keys']['p256dh'],
            $input['keys']['auth']
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'اشتراک با موفقیت ثبت شد',
            'subscription_id' => $pdo->lastInsertId()
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    error_log('Push subscribe error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'خطا در ثبت اشتراک'], JSON_UNESCAPED_UNICODE);
}
