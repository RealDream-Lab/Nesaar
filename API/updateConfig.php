<?php
// Start session before any output or headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once 'db_init.php';

header('Content-Type: application/json; charset=utf-8');

// CSRF Protection for configuration updates
csrf_enforce();

// امنیت: بررسی کن که آیا سیستم قبلاً راه‌اندازی شده یا نه
// اگر IsInit = YES باشد، اجازه تغییر کد ساد و دانشگاه را نده
$stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'IsInit'");
$stmt->execute();
$row    = $stmt->fetch(PDO::FETCH_ASSOC);
$isInit = $row ? $row['ConfigValue'] : 'NO';

if ($isInit === 'YES') {
    // Audit log: ثبت تلاش برای تغییر لایسنس پس از راه‌اندازی
    audit_log_license($pdo, 're_init_attempt', 'blocked', [
        'message' => 'Attempt to re-initialize system after setup',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(403);
    echo json_encode([
        'error' => 'سیستم قبلاً راه‌اندازی شده است. امکان تغییر کد ساد وجود ندارد.',
        'alreadyInitialized' => true
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Expect SaadCode and University instead of Order
if (!$input || !isset($input['SaadCode'], $input['University'])) {
    echo json_encode(['error' => 'داده‌های نامعتبر']);
    exit;
}

$saad       = trim($input['SaadCode']);
$university = trim($input['University']);

if (empty($saad) || empty($university)) {
    echo json_encode(['error' => 'مقادیر نمی‌توانند خالی باشند']);
    exit;
}

// Normalize SaadCode early so DB stores ASCII digits
// Map Persian/Arabic digits to ASCII 0-9
$map            = [
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
    '٩' => '9'
];
$normalizedSaad = strtr($saad, $map);
$normalizedSaad = preg_replace('/\s+/u', '', $normalizedSaad);

// Enforce exactly 4 ASCII digits
if (!preg_match('/^\d{4}$/', $normalizedSaad)) {
    echo json_encode(['error' => 'کد ساد باید دقیقاً ۴ رقم باشد']);
    exit;
}

// First, call the webhook to validate
$webhookUrl  = 'https://wfa.pnubijar.ac.ir/webhook/Licence';
$query       = http_build_query(['SaadCode' => $normalizedSaad, 'Center' => $university]);
$ctx         = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 4,
        'header' => "Accept: application/json\r\n"
    ]
]);
$webhookResp = @file_get_contents($webhookUrl . '?' . $query, false, $ctx);

$webhookData = null;
if ($webhookResp !== false && strlen(trim($webhookResp)) > 0) {
    $decoded = json_decode($webhookResp, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $webhookData = $decoded;
    }
}

// Check if webhook response is valid
if (!$webhookData) {
    echo json_encode(['error' => 'پاسخ سرور نامعتبر است. لطفاً دوباره تلاش کنید.']);
    exit;
}

// Get the license token first
$licenseToken = $webhookData['Code'] ?? '';

// Check if the license token is the "already registered" code
if ($licenseToken === '00000000000000000000000000000000') {
    echo json_encode([
        'error' => 'این کد ساد قبلاً در سیستم دیگری ثبت شده است. امکان ثبت مجدد وجود ندارد.',
        'alreadyRegistered' => true
    ]);
    exit;
}

// Check if the response has the exact required message
$requiredMessage = 'درخواست فعال سازی ارسال شد. از هم اکنون به مدت ۲۴ ساعت امکان استفاده از نرم افزار برای شما فراهم است. پس از انقضای این زمان باید نسبت به خرید لایسنس اقدام نمائید.';
if (!isset($webhookData['Respond']) || trim($webhookData['Respond']) !== $requiredMessage) {
    echo json_encode(['error' => 'پاسخ سرور نامعتبر است. لطفاً دوباره تلاش کنید.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Store SaadCode in Config (new key: SaadCode). Create it if not exists.
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = 'SaadCode'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && intval($row['cnt']) > 0) {
        $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'SaadCode'");
        $stmt->execute([$normalizedSaad]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('SaadCode', ?)");
        $stmt->execute([$normalizedSaad]);
    }

    $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'University'");
    $stmt->execute([$university]);

    $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = 'YES' WHERE ConfigName = 'IsInit'");
    $stmt->execute();

    // Store LicenseToken if provided
    if (!empty($licenseToken)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = 'LicenseToken'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && intval($row['cnt']) > 0) {
            $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'LicenseToken'");
            $stmt->execute([$licenseToken]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('LicenseToken', ?)");
            $stmt->execute([$licenseToken]);
        }

        // Store LicenseLastChecked
        date_default_timezone_set('Asia/Tehran');
        $currentTimestamp = date('Y-m-d H:i:s');
        $stmt             = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = 'LicenseLastChecked'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && intval($row['cnt']) > 0) {
            $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'LicenseLastChecked'");
            $stmt->execute([$currentTimestamp]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('LicenseLastChecked', ?)");
            $stmt->execute([$currentTimestamp]);
        }
    }

    $pdo->commit();

    // Audit log: ثبت تغییرات پیکربندی
    audit_log_config($pdo, 'SaadCode', null, $normalizedSaad);
    audit_log_config($pdo, 'University', null, $university);
    if (!empty($licenseToken)) {
        audit_log_license($pdo, 'license_initialized', 'success', [
            'saad_code' => $normalizedSaad,
            'university' => $university
        ]);
    }

    echo json_encode(['success' => true, 'message' => $requiredMessage]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'خطا در آپدیت']);
}
?>