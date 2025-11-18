<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/recipient_session.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once 'db_init.php';

try {
    csrf_enforce();
} catch (Throwable $e) {
    exit;
}

$license = license_guard_validate(false);
if ($license['valid'] !== true) {
    license_guard_respond_forbidden($license['message'] ?? 'License validation failed');
}

$session = recipient_session_get($pdo);
if ($session) {
    audit_log_auth($pdo, 'recipient_logout', true, (string)($session['username'] ?? 'Recipient'));
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION = [];
session_regenerate_id(true);
session_destroy();

recipient_session_clear();
admin_session_clear();

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
