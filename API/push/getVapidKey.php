<?php
/**
 * Get VAPID Public Key API
 * Returns the public key for client-side subscription
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db_init.php';
require_once __DIR__ . '/../../vendor/autoload.php';

try {
    // Try to get existing VAPID public key from Config
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'VAPID_PUBLIC_KEY'");
    $stmt->execute();
    $publicKey = $stmt->fetchColumn();

    if (!$publicKey) {
        // Generate new VAPID keys if not exists
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();

        // Save to Config
        $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('VAPID_PUBLIC_KEY', ?) ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)")
            ->execute([$keys['publicKey']]);
        $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('VAPID_PRIVATE_KEY', ?) ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)")
            ->execute([$keys['privateKey']]);
        $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('VAPID_SUBJECT', ?) ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)")
            ->execute(['mailto:admin@example.com']);

        $publicKey = $keys['publicKey'];
    }

    echo json_encode([
        'success' => true,
        'publicKey' => $publicKey
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Get VAPID key error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'خطا در دریافت کلید'], JSON_UNESCAPED_UNICODE);
}
