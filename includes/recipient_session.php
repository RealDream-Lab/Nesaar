<?php
/**
 * Session helpers for Recipient dashboard users.
 */

declare(strict_types=1);

require_once __DIR__ . '/session_tokens.php';

const RECIPIENT_SESSION_COOKIE = 'recipientSession';
const RECIPIENT_SESSION_SCOPE  = 'recipient-session-v1';
const RECIPIENT_SESSION_TTL    = 12 * 60 * 60; // 12 hours

function recipient_session_set(PDO $pdo, array $payload, int $ttlSeconds = RECIPIENT_SESSION_TTL): void
{
    $session              = $payload;
    $session['type']      = 'recipient';
    $session['issued_at'] = $session['issued_at'] ?? time();

    session_cookie_set($pdo, RECIPIENT_SESSION_COOKIE, RECIPIENT_SESSION_SCOPE, $session, $ttlSeconds);
}

function recipient_session_get(PDO $pdo): ?array
{
    $session = session_cookie_get($pdo, RECIPIENT_SESSION_COOKIE, RECIPIENT_SESSION_SCOPE);
    if (!is_array($session) || ($session['type'] ?? '') !== 'recipient') {
        return null;
    }
    return $session;
}

function recipient_session_require(PDO $pdo): array
{
    $session = recipient_session_get($pdo);
    if ($session === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $session;
}

function recipient_session_clear(): void
{
    session_cookie_clear(RECIPIENT_SESSION_COOKIE);
}
