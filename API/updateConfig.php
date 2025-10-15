<?php
require_once 'db_init.php';

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['Order'], $input['University'])) {
    echo json_encode(['error' => 'داده‌های نامعتبر']);
    exit;
}

$order = trim($input['Order']);
$university = trim($input['University']);

if (empty($order) || empty($university)) {
    echo json_encode(['error' => 'مقادیر نمی‌توانند خالی باشند']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'Order'");
    $stmt->execute([$order]);

    $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'University'");
    $stmt->execute([$university]);

    $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = 'YES' WHERE ConfigName = 'IsInit'");
    $stmt->execute();

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'خطا در آپدیت']);
}
?>