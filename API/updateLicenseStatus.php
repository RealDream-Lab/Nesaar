<?php
/**
 * Update License Status API
 * 
 * ذخیره وضعیت بررسی لایسنس (موفق/ناموفق) و تاریخ آخرین بررسی موفق
 * برای پیاده‌سازی Grace Period و Cache سیستم لایسنس
 * 
 * ⚠️ INTERNAL USE ONLY - این API فقط توسط سرور داخلی قابل فراخوانی است
 */

require_once __DIR__ . '/../includes/internal_auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-Internal-Token, X-Internal-Call');

require_once 'db_init.php';

// محافظت: فقط درخواست‌های داخلی مجاز هستند
internal_auth_enforce();

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
    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new PDOException('Database connection unavailable');
    }
    
    // اگر وضعیت موفق است، تاریخ آخرین بررسی موفق رو آپدیت می‌کنیم
    if ($status === 'valid') {
        // ذخیره تاریخ آخرین بررسی موفق
        $stmt = $pdo->prepare("
            INSERT INTO Config (ConfigName, ConfigValue) 
            VALUES ('LicenseLastSuccess', :now)
            ON DUPLICATE KEY UPDATE ConfigValue = :now
        ");
        $stmt->execute(['now' => $now]);
    }

    // Clean up legacy key if still present
    $pdo->prepare("DELETE FROM Config WHERE ConfigName = 'LicenseLastSuccessCheck'")->execute();
    
    // ذخیره وضعیت آخرین بررسی (موفق، ناموفق یا خطا)
    $stmt = $pdo->prepare("
        INSERT INTO Config (ConfigName, ConfigValue) 
        VALUES ('LicenseLastStatus', :status)
        ON DUPLICATE KEY UPDATE ConfigValue = :status
    ");
    $stmt->execute(['status' => $status]);
    
    // Clean up legacy expiry value if still stored
    $pdo->prepare("DELETE FROM Config WHERE ConfigName = 'LicenseExpiry'")->execute();
    
    // Audit log: ثبت تغییر وضعیت لایسنس
    audit_log_license($pdo, 'status_update', $status, [
        'timestamp' => $now,
        'source' => 'internal_api'
    ]);
    
    echo json_encode([
        'success' => true,
        'status' => $status,
        'timestamp' => $now
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
