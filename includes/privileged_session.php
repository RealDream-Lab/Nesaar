<?php
/**
 * Helper to allow endpoints to accept either admin or recipient dashboard sessions.
 */

declare(strict_types=1);

require_once __DIR__ . '/admin_session.php';
require_once __DIR__ . '/recipient_session.php';

function privileged_session_get(PDO $pdo): ?array
{
    $admin = admin_session_get($pdo);
    if ($admin) {
        $admin['actor'] = 'admin';
        return $admin;
    }

    $recipient = recipient_session_get($pdo);
    if ($recipient) {
        $recipient['actor'] = 'recipient';
        return $recipient;
    }

    return null;
}

function privileged_session_require(PDO $pdo): array
{
    $session = privileged_session_get($pdo);
    if ($session !== null) {
        return $session;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
