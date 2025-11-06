<?php
// Suppress all PHP errors and warnings to prevent HTML output in JSON responses
ini_set('display_errors', 0);
ini_set('error_reporting', 0);
error_reporting(0);

require_once __DIR__ . '/../includes/license_guard.php';
require_once 'db_init.php';

header('Content-Type: application/json; charset=utf-8');

license_guard_enforce_api();

try {
    $stmt = $pdo->query("SELECT ConfigName, ConfigValue FROM Config");
    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }
    echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    echo json_encode(['error' => 'خطا در دریافت تنظیمات']);
}
?>