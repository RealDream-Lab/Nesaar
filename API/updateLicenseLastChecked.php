<?php
header('Content-Type: application/json; charset=utf-8');

// Load .env file when present
(function () {
    $root = realpath(__DIR__ . '/../');
    $envFile = $root ? $root . '/.env' : null;
    if (!$envFile || !is_file($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
            continue;
        }
        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$key, $value] = $parts;
        $key = trim($key);
        if ($key === '') {
            continue;
        }
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
})();

require_once 'db_init.php';

try {
    // Set timezone to Tehran
    date_default_timezone_set('Asia/Tehran');
    $currentTimestamp = date('Y-m-d H:i:s');
    
    // Check if LicenseLastChecked exists
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = 'LicenseLastChecked'");
    $stmt->execute();
    $row = $stmt->fetch();
    
    if ($row && intval($row['cnt']) > 0) {
        // Update existing record
        $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'LicenseLastChecked'");
        $stmt->execute([$currentTimestamp]);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('LicenseLastChecked', ?)");
        $stmt->execute([$currentTimestamp]);
    }
    
    echo json_encode(['success' => true, 'timestamp' => $currentTimestamp]);
} catch (Exception $e) {
    error_log('Failed to update LicenseLastChecked: ' . $e->getMessage());
    echo json_encode(['error' => 'خطا در آپدیت تاریخ']);
}
?>
