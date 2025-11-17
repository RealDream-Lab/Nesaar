<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/audit_log.php';
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

$username = trim((string)($payload['username'] ?? ''));
$password = trim((string)($payload['password'] ?? ''));
if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'نام کاربری و رمز عبور الزامی است']);
    exit;
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

$normalizedInput     = mb_strtolower($username, 'UTF-8');
$normalizedAdmin     = mb_strtolower($expectedUsername, 'UTF-8');
$normalizedRecipient = mb_strtolower($recipientUsername, 'UTF-8');

$isAdminAttempt     = hash_equals($normalizedAdmin, $normalizedInput);
$isRecipientAttempt = hash_equals($normalizedRecipient, $normalizedInput);

if (!$isAdminAttempt && !$isRecipientAttempt) {
    audit_log_auth($pdo, 'admin_login', false, $username);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است']);
    exit;
}

$expectedPasswordForAttempt = $isAdminAttempt ? $expectedPassword : $recipientPassword;
$auditKey                   = $isAdminAttempt ? 'admin_login' : 'recipient_login';
$canonicalUsername          = $isAdminAttempt ? $expectedUsername : $recipientUsername;

if (!hash_equals($expectedPasswordForAttempt, $password)) {
    audit_log_auth($pdo, $auditKey, false, $username);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است']);
    exit;
}

audit_log_auth($pdo, $auditKey, true, $canonicalUsername);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
session_regenerate_id(true);

admin_session_set($pdo, [
    'username' => $canonicalUsername,
    'actor' => $isAdminAttempt ? 'admin' : 'recipient',
]);

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
    'missingFields' => $missing,
    'ttlSeconds' => ADMIN_SESSION_TTL,
], JSON_UNESCAPED_UNICODE);
