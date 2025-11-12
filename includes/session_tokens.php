<?php
/**
 * Utilities for signing and verifying session cookies using HMAC tokens.
 */

declare(strict_types=1);

/**
 * Retrieve (or lazily create) the shared HMAC secret stored in the Config table.
 */
function session_tokens_get_secret(PDO $pdo): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'SessionHmacKey' LIMIT 1");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    if (is_string($value) && strlen($value) >= 64) {
        $cached = $value;
        return $cached;
    }

    $newSecret = bin2hex(random_bytes(32));
    $insert = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('SessionHmacKey', :val)
        ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)");
    $insert->execute([':val' => $newSecret]);

    $cached = $newSecret;
    return $cached;
}

function session_tokens_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function session_tokens_base64url_decode(string $data)
{
    $translated = strtr($data, '-_', '+/');
    $padding = strlen($translated) % 4;
    if ($padding > 0) {
        $translated .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($translated, true);
}

/**
 * Build a signed token for the provided payload and scope.
 */
function session_token_build(PDO $pdo, string $scope, array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode session payload');
    }

    $body = session_tokens_base64url_encode($json);
    $secret = session_tokens_get_secret($pdo);
    $signature = hash_hmac('sha256', $scope . '.' . $body, $secret, true);
    $sigEncoded = session_tokens_base64url_encode($signature);

    return $body . '.' . $sigEncoded;
}

/**
 * Verify a token and return the decoded payload when valid.
 */
function session_token_parse(PDO $pdo, string $scope, string $token): ?array
{
    if ($token === '' || strlen($token) > 4096) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }

    [$body, $signature] = $parts;
    if ($body === '' || $signature === '') {
        return null;
    }

    $payloadJson = session_tokens_base64url_decode($body);
    $providedSignature = session_tokens_base64url_decode($signature);
    if ($payloadJson === false || $providedSignature === false) {
        return null;
    }

    $secret = session_tokens_get_secret($pdo);
    $expectedSignature = hash_hmac('sha256', $scope . '.' . $body, $secret, true);
    if (!hash_equals($expectedSignature, $providedSignature)) {
        return null;
    }

    $decoded = json_decode($payloadJson, true);
    return is_array($decoded) ? $decoded : null;
}

function session_cookie_env_flag(string $name): bool
{
    $value = $_ENV[$name] ?? getenv($name);
    if ($value === false || $value === null) {
        return false;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function session_cookie_should_use_secure(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === 'https') {
        $cached = true;
        return $cached;
    }

    $forwardedSsl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    if ($forwardedSsl === 'on') {
        $cached = true;
        return $cached;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

    if ($isLocalHost || session_cookie_env_flag('SESSION_COOKIE_ALLOW_INSECURE')) {
        $cached = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        return $cached;
    }

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $cached = true;
        return $cached;
    }

    error_log('session_cookie_set: HTTPS is not enabled but Secure flag is enforced; cookie may be discarded by the browser.');
    $cached = true;
    return $cached;
}

/**
 * Set a signed HttpOnly cookie for the given payload.
 */
function session_cookie_set(PDO $pdo, string $name, string $scope, array $payload, int $ttlSeconds, bool $httpOnly = true, string $sameSite = 'Strict'): void
{
    if ($ttlSeconds <= 0) {
        session_cookie_clear($name, $httpOnly, $sameSite);
        return;
    }

    $token = session_token_build($pdo, $scope, $payload);
    $secure = session_cookie_should_use_secure();

    setcookie($name, $token, [
        'expires' => time() + $ttlSeconds,
        'path' => '/',
        'secure' => $secure,
        'httponly' => $httpOnly,
        'samesite' => $sameSite,
    ]);

    $_COOKIE[$name] = $token;
}

/**
 * Retrieve and validate a signed cookie.
 */
function session_cookie_get(PDO $pdo, string $name, string $scope): ?array
{
    if (!isset($_COOKIE[$name]) || !is_string($_COOKIE[$name]) || $_COOKIE[$name] === '') {
        return null;
    }

    return session_token_parse($pdo, $scope, $_COOKIE[$name]);
}

/**
 * Clear a cookie by expiring it immediately.
 */
function session_cookie_clear(string $name, bool $httpOnly = true, string $sameSite = 'Strict'): void
{
    $secure = session_cookie_should_use_secure();

    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => $httpOnly,
        'samesite' => $sameSite,
    ]);

    unset($_COOKIE[$name]);
}
