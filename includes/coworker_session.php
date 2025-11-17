<?php
/**
 * Helper functions for coworker (staff) session cookies.
 */

declare(strict_types=1);

require_once __DIR__ . '/session_tokens.php';

const COWORKER_SESSION_COOKIE = 'coworkerSession';
const COWORKER_SESSION_SCOPE  = 'coworker-session-v1';
const COWORKER_SESSION_TTL    = 14 * 24 * 60 * 60; // 14 days

function coworker_session_set(PDO $pdo, array $payload, int $ttlSeconds = COWORKER_SESSION_TTL): void
{
    $session              = $payload;
    $session['type']      = 'coworker';
    $session['issued_at'] = $session['issued_at'] ?? time();

    session_cookie_set($pdo, COWORKER_SESSION_COOKIE, COWORKER_SESSION_SCOPE, $session, $ttlSeconds, true, 'Lax');
}

function coworker_session_get(PDO $pdo): ?array
{
    $session = session_cookie_get($pdo, COWORKER_SESSION_COOKIE, COWORKER_SESSION_SCOPE);
    if (!is_array($session) || ($session['type'] ?? '') !== 'coworker') {
        return null;
    }
    return $session;
}

function coworker_session_clear(): void
{
    session_cookie_clear(COWORKER_SESSION_COOKIE);
}
