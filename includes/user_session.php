<?php
/**
 * Helper functions for signed user (student) session cookies.
 */

declare(strict_types=1);

require_once __DIR__ . '/session_tokens.php';

const USER_SESSION_COOKIE = 'userSession';
const USER_SESSION_SCOPE = 'user-session-v1';
const USER_SESSION_TTL = 30 * 24 * 60 * 60; // 30 days

function user_session_set(PDO $pdo, array $payload, int $ttlSeconds = USER_SESSION_TTL): void
{
    $session = $payload;
    $session['type'] = $session['type'] ?? 'student';
    $session['issued_at'] = $session['issued_at'] ?? time();

    session_cookie_set($pdo, USER_SESSION_COOKIE, USER_SESSION_SCOPE, $session, $ttlSeconds);
}

function user_session_get(PDO $pdo): ?array
{
    $session = session_cookie_get($pdo, USER_SESSION_COOKIE, USER_SESSION_SCOPE);
    if (!is_array($session)) {
        return null;
    }
    return $session;
}

function user_session_clear(): void
{
    session_cookie_clear(USER_SESSION_COOKIE);
}
