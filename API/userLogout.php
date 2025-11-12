<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/user_session.php';

try {
    csrf_enforce();
} catch (Throwable $e) {
    exit;
}

$license = license_guard_validate(false);
if ($license['valid'] !== true) {
    license_guard_respond_forbidden($license['message'] ?? 'License validation failed');
}

user_session_clear();

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
