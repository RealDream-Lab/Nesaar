<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/recipient_session.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/login_guard.php';
require_once __DIR__ . '/../includes/captcha_math.php';
require_once 'db_init.php';

try {
    csrf_enforce();
} catch (Throwable $e) {
    exit;
}

$license = license_guard_validate();
if ($license['valid'] !== true) {
    license_guard_respond_forbidden($license['message'] ?? 'License validation failed');
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'درخواست نامعتبر است']);
    exit;
}

$username      = trim((string)($payload['username'] ?? ''));
$password      = trim((string)($payload['password'] ?? ''));
$captchaToken  = trim((string)($payload['captchaToken'] ?? ''));
$captchaAnswer = trim((string)($payload['captchaAnswer'] ?? ''));
if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'نام کاربری و رمز عبور الزامی است']);
    exit;
}

$normalizedInput  = mb_strtolower($username, 'UTF-8');
$ipAddress        = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$loginIdentifiers = login_guard_get_identifiers($normalizedInput, $ipAddress);
$guardState       = login_guard_collect($pdo, $loginIdentifiers);
$lockedUntil      = $guardState['locked_until'];
if ($lockedUntil > time()) {
    $seconds = login_guard_seconds_until_unlock($lockedUntil);
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'به دلیل تلاش‌های متعدد ناموفق، ورود موقتاً مسدود شده است. چند دقیقه بعد دوباره امتحان کنید.',
        'lockExpiresAt' => $lockedUntil,
        'retryAfterSeconds' => $seconds,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$captchaRequired = $guardState['max_failures'] >= LOGIN_CAPTCHA_THRESHOLD;
if ($captchaRequired) {
    if (!captcha_math_verify($pdo, $captchaToken, $captchaAnswer)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'برای ادامه لطفاً سؤال امنیتی نمایش داده شده را حل کنید.',
            'captchaRequired' => true,
            'captcha' => captcha_math_generate($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$configStmt = $pdo->prepare("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ('SaadCode','LicenseToken','AdminNickName','BossNickName','HeadOfEDU','Chairman')");
$configStmt->execute();
$config = [];
while ($row = $configStmt->fetch(PDO::FETCH_ASSOC)) {
    $config[$row['ConfigName']] = $row['ConfigValue'];
}

$saadCode     = trim((string)($config['SaadCode'] ?? ''));
$licenseToken = trim((string)($config['LicenseToken'] ?? ''));

if ($saadCode === '' || $licenseToken === '') {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'سیستم هنوز راه‌اندازی نشده است']);
    exit;
}

$expectedUsername = 'admin' . $saadCode;
$expectedPassword = strrev($licenseToken);

$recipientUsername = 'Recipient';
$chars             = preg_split('//u', $expectedPassword, -1, PREG_SPLIT_NO_EMPTY);
$recipientPassword = '';
foreach ($chars as $index => $ch) {
    if ($index % 2 === 0) {
        $recipientPassword .= $ch;
    }
}

$normalizedAdmin     = mb_strtolower($expectedUsername, 'UTF-8');
$normalizedRecipient = mb_strtolower($recipientUsername, 'UTF-8');

$isAdminAttempt     = hash_equals($normalizedAdmin, $normalizedInput);
$isRecipientAttempt = hash_equals($normalizedRecipient, $normalizedInput);

if (!$isAdminAttempt && !$isRecipientAttempt) {
    audit_log_auth($pdo, 'admin_login', false, $username);
    login_guard_record_failure_for($pdo, $loginIdentifiers);
    $state    = login_guard_collect($pdo, $loginIdentifiers);
    $response = ['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است'];
    if ($state['max_failures'] >= LOGIN_CAPTCHA_THRESHOLD) {
        $response['captchaRequired'] = true;
        $response['captcha']         = captcha_math_generate($pdo);
    }
    http_response_code(401);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$expectedPasswordForAttempt = $isAdminAttempt ? $expectedPassword : $recipientPassword;
$auditKey                   = $isAdminAttempt ? 'admin_login' : 'recipient_login';
$canonicalUsername          = $isAdminAttempt ? $expectedUsername : $recipientUsername;

if (!hash_equals($expectedPasswordForAttempt, $password)) {
    audit_log_auth($pdo, $auditKey, false, $username);
    login_guard_record_failure_for($pdo, $loginIdentifiers);
    $state    = login_guard_collect($pdo, $loginIdentifiers);
    $response = ['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است'];
    if ($state['max_failures'] >= LOGIN_CAPTCHA_THRESHOLD) {
        $response['captchaRequired'] = true;
        $response['captcha']         = captcha_math_generate($pdo);
    }
    http_response_code(401);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

audit_log_auth($pdo, $auditKey, true, $canonicalUsername);
login_guard_reset_for($pdo, $loginIdentifiers);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
session_regenerate_id(true);

if ($isAdminAttempt) {
    recipient_session_clear();
    admin_session_set($pdo, [
        'username' => $canonicalUsername,
        'actor' => 'admin',
    ]);
} else {
    admin_session_clear();
    recipient_session_set($pdo, [
        'username' => $canonicalUsername,
        'actor' => 'recipient',
    ]);
}

$missing = [];
if ($isAdminAttempt) {
    if (empty($config['AdminNickName']))
        $missing[] = 'AdminNickName';
    if (empty($config['BossNickName']))
        $missing[] = 'BossNickName';
    if (empty($config['HeadOfEDU']))
        $missing[] = 'HeadOfEDU';
    if (empty($config['Chairman']))
        $missing[] = 'Chairman';
}

$displayName = $isAdminAttempt ? ($config['AdminNickName'] ?? '') : 'Recipient';

echo json_encode([
    'success' => true,
    'username' => $canonicalUsername,
    'displayName' => $displayName,
    'actor' => $isAdminAttempt ? 'admin' : 'recipient',
    'missingFields' => $missing,
    'ttlSeconds' => ADMIN_SESSION_TTL,
], JSON_UNESCAPED_UNICODE);
