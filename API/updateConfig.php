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

try {
    $pdo->beginTransaction();

    // Store SaadCode in Config (new key: SaadCode). Create it if not exists.
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = 'SaadCode'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && intval($row['cnt']) > 0) {
        $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'SaadCode'");
        $stmt->execute([$saad]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('SaadCode', ?)");
        $stmt->execute([$saad]);
    }

    $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'University'");
    $stmt->execute([$university]);

    $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = 'YES' WHERE ConfigName = 'IsInit'");
    $stmt->execute();

    // After commit, fire a GET webhook to notify external system
    $pdo->commit();

    // Fire-and-forget GET request to webhook with SaadCode and Center (University)
    $webhookUrl = 'https://wfa.pnubijar.ac.ir/webhook/Licence';
    // Normalize SaadCode to ASCII digits (Persian/Arabic digits -> 0-9)
    $map = [
        '\x{06F0}' => '0','\x{06F1}' => '1','\x{06F2}' => '2','\x{06F3}' => '3','\x{06F4}' => '4',
        '\x{06F5}' => '5','\x{06F6}' => '6','\x{06F7}' => '7','\x{06F8}' => '8','\x{06F9}' => '9',
        '\x{0660}' => '0','\x{0661}' => '1','\x{0662}' => '2','\x{0663}' => '3','\x{0664}' => '4',
        '\x{0665}' => '5','\x{0666}' => '6','\x{0667}' => '7','\x{0668}' => '8','\x{0669}' => '9'
    ];
    $normalizedSaad = preg_replace(array_keys($map), array_values($map), $saad);
    $query = http_build_query(['SaadCode' => $normalizedSaad, 'Center' => $university]);
    // Use non-blocking stream context (short timeout)
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2
        ]
    ]);
    @file_get_contents($webhookUrl . '?' . $query, false, $ctx);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'خطا در آپدیت']);
}
?>