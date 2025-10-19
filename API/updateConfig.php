<?php
require_once 'db_init.php';

header('Content-Type: application/json; charset=utf-8');


$input = json_decode(file_get_contents('php://input'), true);

// Expect SaadCode and University instead of Order
if (!$input || !isset($input['SaadCode'], $input['University'])) {
    echo json_encode(['error' => 'داده‌های نامعتبر']);
    exit;
}

$saad = trim($input['SaadCode']);
$university = trim($input['University']);

if (empty($saad) || empty($university)) {
    echo json_encode(['error' => 'مقادیر نمی‌توانند خالی باشند']);
    exit;
}

// Normalize SaadCode early so DB stores ASCII digits
// Map Persian/Arabic digits to ASCII 0-9
$map = [
    '۰' => '0','۱' => '1','۲' => '2','۳' => '3','۴' => '4','۵' => '5','۶' => '6','۷' => '7','۸' => '8','۹' => '9',
    '٠' => '0','١' => '1','٢' => '2','٣' => '3','٤' => '4','٥' => '5','٦' => '6','٧' => '7','٨' => '8','٩' => '9'
];
$normalizedSaad = strtr($saad, $map);
$normalizedSaad = preg_replace('/\s+/u', '', $normalizedSaad);

// Enforce exactly 4 ASCII digits
if (!preg_match('/^\d{4}$/', $normalizedSaad)) {
    echo json_encode(['error' => 'کد ساد باید دقیقاً ۴ رقم باشد']);
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

    // After commit, call the webhook and include its response in our API response
    $pdo->commit();

    $webhookUrl = 'https://wfa.pnubijar.ac.ir/webhook/Licence';
    $query = http_build_query(['SaadCode' => $normalizedSaad, 'Center' => $university]);
    // Blocking-ish request but with a short timeout
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 4,
            'header' => "Accept: application/json\r\n"
        ]
    ]);
    $webhookResp = @file_get_contents($webhookUrl . '?' . $query, false, $ctx);

    $responsePayload = ['success' => true];
    if ($webhookResp !== false && strlen(trim($webhookResp)) > 0) {
        $decoded = json_decode($webhookResp, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $responsePayload['webhook'] = $decoded;
        } else {
            // Fallback: include raw text
            $responsePayload['webhook'] = ['raw' => $webhookResp];
        }
    }

    echo json_encode($responsePayload);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'خطا در آپدیت']);
}
?>