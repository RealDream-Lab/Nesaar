<?php
/**
 * Update License Status API
 * 
 * ذخیره وضعیت بررسی لایسنس (موفق/ناموفق) و تاریخ آخرین بررسی موفق
 * برای پیاده‌سازی Grace Period و Cache سیستم لایسنس
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'db_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing status parameter']);
    exit;
}

$status = $data['status']; // 'valid' | 'invalid' | 'error'
$now = date('Y-m-d H:i:s');

try {
    $pdo = getDBConnection();
    
    // اگر وضعیت موفق است، تاریخ آخرین بررسی موفق رو آپدیت می‌کنیم
    if ($status === 'valid') {
        // ذخیره تاریخ آخرین بررسی موفق
        $stmt = $pdo->prepare("
            INSERT INTO Config (ConfigName, ConfigValue) 
            VALUES ('LicenseLastSuccessCheck', :now)
            ON DUPLICATE KEY UPDATE ConfigValue = :now
        ");
        $stmt->execute(['now' => $now]);
    }
    
    // ذخیره وضعیت آخرین بررسی (موفق، ناموفق یا خطا)
    $stmt = $pdo->prepare("
        INSERT INTO Config (ConfigName, ConfigValue) 
        VALUES ('LicenseLastStatus', :status)
        ON DUPLICATE KEY UPDATE ConfigValue = :status
    ");
    $stmt->execute(['status' => $status]);
    
    // اگر تاریخ انقضا ارسال شده، اون رو هم ذخیره می‌کنیم
    if (isset($data['expiry']) && $data['expiry']) {
        $stmt = $pdo->prepare("
            INSERT INTO Config (ConfigName, ConfigValue) 
            VALUES ('LicenseExpiry', :expiry)
            ON DUPLICATE KEY UPDATE ConfigValue = :expiry
        ");
        $stmt->execute(['expiry' => $data['expiry']]);
    }
    
    echo json_encode([
        'success' => true,
        'status' => $status,
        'timestamp' => $now
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
