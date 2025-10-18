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
    $query = http_build_query(['SaadCode' => $saad, 'Center' => $university]);
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