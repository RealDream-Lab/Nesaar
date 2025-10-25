<?php
/**
 * Get License Cache API
 * 
 * دریافت اطلاعات cache شده لایسنس برای Grace Period
 */

require_once __DIR__ . '/../includes/license_guard.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Enforce license validation before serving cache
license_guard_enforce_api();

require_once 'db_init.php';

try {
    // $pdo is already defined in db_init.php
    
    // دریافت وضعیت لایسنس از جدول Config
    $stmt = $pdo->query("
        SELECT ConfigName, ConfigValue 
        FROM Config 
        WHERE ConfigName IN (
            'LicenseLastStatus',
            'LicenseLastSuccess',
            'LicenseLastSuccessCheck', 
            'LicenseLastChecked',
            'LicenseCurrentType'
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
            'lastSuccessCheck' => $cache['LicenseLastSuccess'] ?? $cache['LicenseLastSuccessCheck'] ?? null,
            'lastChecked' => $cache['LicenseLastChecked'] ?? null,
            'currentType' => $cache['LicenseCurrentType'] ?? null
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
