<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once 'db_init.php';

$license = license_guard_validate(false);
if ($license['valid'] !== true) {
    license_guard_respond_forbidden($license['message'] ?? 'License validation failed');
}

$session = admin_session_get($pdo);
if (!$session) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$displayName = '';
try {
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'AdminNickName' LIMIT 1");
    $stmt->execute();
    $displayName = (string)($stmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    $displayName = '';
}

echo json_encode([
    'username' => (string)($session['username'] ?? 'admin'),
    'displayName' => $displayName,
    'issuedAt' => (int)($session['issued_at'] ?? 0),
], JSON_UNESCAPED_UNICODE);
