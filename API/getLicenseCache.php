<?php
/**
 * Get License Cache API
 * 
 * دریافت اطلاعات cache شده لایسنس برای Grace Period
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_init.php';

try {
    $pdo = getDBConnection();
    
    // دریافت آخرین وضعیت موفق، تاریخ بررسی موفق و تاریخ انقضا
    $stmt = $pdo->query("
        SELECT ConfigName, ConfigValue 
        FROM Config 
        WHERE ConfigName IN (
            'LicenseLastStatus',
            'LicenseLastSuccessCheck', 
            'LicenseExpiry',
            'LicenseLastChecked'
        )
    ");
    
    $cache = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cache[$row['ConfigName']] = $row['ConfigValue'];
    }
    
    echo json_encode([
        'success' => true,
        'cache' => [
            'lastStatus' => $cache['LicenseLastStatus'] ?? null,
            'lastSuccessCheck' => $cache['LicenseLastSuccessCheck'] ?? null,
            'lastChecked' => $cache['LicenseLastChecked'] ?? null,
            'expiry' => $cache['LicenseExpiry'] ?? null
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
