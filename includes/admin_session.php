<?php
/**
 * Helper functions for managing signed admin session cookies.
 */

declare(strict_types=1);

require_once __DIR__ . '/session_tokens.php';

const ADMIN_SESSION_COOKIE = 'adminSession';
const ADMIN_SESSION_SCOPE = 'admin-session-v1';
const ADMIN_SESSION_TTL = 12 * 60 * 60; // 12 hours

function admin_session_set(PDO $pdo, array $payload, int $ttlSeconds = ADMIN_SESSION_TTL): void
{
    $session = $payload;
    $session['type'] = 'admin';
    $session['issued_at'] = $session['issued_at'] ?? time();

    session_cookie_set($pdo, ADMIN_SESSION_COOKIE, ADMIN_SESSION_SCOPE, $session, $ttlSeconds);
}

function admin_session_get(PDO $pdo): ?array
{
    $session = session_cookie_get($pdo, ADMIN_SESSION_COOKIE, ADMIN_SESSION_SCOPE);
    if (!is_array($session) || ($session['type'] ?? '') !== 'admin') {
        return null;
    }
    return $session;
}

function admin_session_require(PDO $pdo): array
{
    $session = admin_session_get($pdo);
    if ($session === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $session;
}

function admin_session_clear(): void
{
    session_cookie_clear(ADMIN_SESSION_COOKIE);
}
