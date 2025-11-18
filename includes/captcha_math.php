<?php
/**
 * Lightweight math captcha helper with signed tokens.
 */

declare(strict_types=1);

require_once __DIR__ . '/session_tokens.php';

const CAPTCHA_MATH_TTL = 300; // 5 minutes

function captcha_math_generate(PDO $pdo): array
{
    $a        = random_int(2, 9);
    $b        = random_int(2, 9);
    $question = sprintf('%d + %d = ؟', $a, $b);
    $payload  = [
        'a' => $a,
        'b' => $b,
        'answer' => $a + $b,
        'exp' => time() + CAPTCHA_MATH_TTL,
        'nonce' => bin2hex(random_bytes(8)),
    ];
    $token    = captcha_math_sign($pdo, $payload);

    return [
        'question' => $question,
        'token' => $token,
    ];
}

function captcha_math_sign(PDO $pdo, array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('captcha payload encode failed');
    }

    $secret = session_tokens_get_secret($pdo);
    $body   = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig    = hash_hmac('sha256', $body, $secret);
    return $body . '.' . $sig;
}

function captcha_math_parse(PDO $pdo, string $token): ?array
{
    if (!is_string($token) || $token === '') {
        return null;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$body, $sig] = $parts;
    $secret       = session_tokens_get_secret($pdo);
    $expected     = hash_hmac('sha256', $body, $secret);
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $padded = strtr($body, '-_', '+/');
    $padLen = strlen($padded) % 4;
    if ($padLen > 0) {
        $padded .= str_repeat('=', 4 - $padLen);
    }
    $json = base64_decode($padded, true);
    if ($json === false) {
        return null;
    }
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : null;
}

function captcha_math_verify(PDO $pdo, string $token, string $answer): bool
{
    $payload = captcha_math_parse($pdo, $token);
    if (!$payload) {
        return false;
    }
    if (($payload['exp'] ?? 0) < time()) {
        return false;
    }
    $expected = (int)($payload['answer'] ?? 0);
    $provided = (int)captcha_math_normalize_answer($answer);
    return $expected === $provided;
}

function captcha_math_normalize_answer(string $answer): string
{
    $map     = [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
    ];
    $trimmed = trim($answer);
    if ($trimmed === '') {
        return '';
    }
    $normalized = strtr($trimmed, $map);
    return preg_replace('/[^0-9\-]/', '', $normalized) ?? '';
}
