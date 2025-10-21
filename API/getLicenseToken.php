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
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'LicenseToken'");
    $stmt->execute();
    $row = $stmt->fetch();
    $licenseToken = $row ? $row['ConfigValue'] : '';
    
    if (empty($licenseToken)) {
        echo json_encode(['error' => 'توکن لایسنس یافت نشد']);
        exit;
    }
    
    echo json_encode(['LicenseToken' => $licenseToken]);
} catch (Exception $e) {
    error_log('Failed to get license token: ' . $e->getMessage());
    echo json_encode(['error' => 'خطا در دریافت توکن لایسنس']);
}
?>
