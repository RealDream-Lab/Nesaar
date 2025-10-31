<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/license_guard.php';
require_once 'db_init.php';

license_guard_enforce_api();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'داده‌های نامعتبر']);
    exit;
}

// Allow only specific config keys to be written via this endpoint
$allowed = ['AdminNickName', 'BossNickName', 'HeadOfEDU', 'Chairman'];

$toSave = [];
foreach ($allowed as $k) {
    if (isset($input[$k])) {
        $val = trim($input[$k]);
        if ($val !== '') $toSave[$k] = $val;
    }
}

if (empty($toSave)) {
    echo json_encode(['success' => false, 'error' => 'مقادیر مناسبی برای ذخیره ارسال نشده است']);
    exit;
}

try {
    foreach ($toSave as $name => $value) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if ($row && intval($row['cnt']) > 0) {
            $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = ?");
            $stmt->execute([$value, $name]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES (?, ?)");
            $stmt->execute([$name, $value]);
        }
    }
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('saveConfig error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'خطا در ذخیره تنظیمات'], JSON_UNESCAPED_UNICODE);
}

?>
