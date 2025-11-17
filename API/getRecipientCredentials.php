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

admin_session_require($pdo);

try {
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'LicenseToken' LIMIT 1");
    $stmt->execute();
    $licenseToken = trim((string)($stmt->fetchColumn() ?: ''));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'خطا در دریافت تنظیمات'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($licenseToken === '') {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'توکن لایسنس تنظیم نشده است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$adminPassword     = strrev($licenseToken);
$chars             = preg_split('//u', $adminPassword, -1, PREG_SPLIT_NO_EMPTY);
$recipientPassword = '';
foreach ($chars as $index => $ch) {
    if ($index % 2 === 0) { // 1-based odd positions -> 0,2,4...
        $recipientPassword .= $ch;
    }
}

if ($recipientPassword === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'امکان تولید رمز Recipient وجود ندارد'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'username' => 'Recipient',
    'password' => $recipientPassword,
], JSON_UNESCAPED_UNICODE);
