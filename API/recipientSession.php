<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/recipient_session.php';
require_once 'db_init.php';

$license = license_guard_validate(false);
if ($license['valid'] !== true) {
    license_guard_respond_forbidden($license['message'] ?? 'License validation failed');
}

$session = recipient_session_get($pdo);
if (!$session) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'username' => (string)($session['username'] ?? 'Recipient'),
    'displayName' => "کاربر گزارش\u{200c}گیری",
    'issuedAt' => (int)($session['issued_at'] ?? 0),
], JSON_UNESCAPED_UNICODE);
