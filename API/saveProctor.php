<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';

function toEnglishDigits($value)
{
    $persian = '۰۱۲۳۴۵۶۷۸۹';
    $arabic  = '٠١٢٣٤٥٦٧٨٩';
    return str_replace(array_merge(str_split($persian), str_split($arabic)), ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $value);
}

try {
    csrf_enforce();
    license_guard_enforce_api();
    $session = admin_session_require($pdo);

    // Ensure table exists with UNIQUE constraints
    $pdo->exec("CREATE TABLE IF NOT EXISTS `Proctors` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `gender` VARCHAR(3) DEFAULT '',
        `first_name` VARCHAR(40) DEFAULT '',
        `last_name` VARCHAR(40) DEFAULT '',
        `national_id` CHAR(10) NOT NULL DEFAULT '',
        `phone` VARCHAR(11) DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_national_id` (`national_id`),
        UNIQUE KEY `uk_phone` (`phone`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure national_id column exists
    $col = $pdo->query("SHOW COLUMNS FROM `Proctors` LIKE 'national_id'");
    if (!$col || $col->rowCount() === 0) {
        $pdo->exec("ALTER TABLE `Proctors` ADD `national_id` CHAR(10) NOT NULL DEFAULT '' AFTER `last_name`");
    }

    // Add UNIQUE constraints if they don't exist
    try {
        $pdo->exec("ALTER TABLE `Proctors` ADD UNIQUE KEY `uk_national_id` (`national_id`)");
    } catch (Throwable $e) {
        // Ignore if already exists
    }
    try {
        $pdo->exec("ALTER TABLE `Proctors` ADD UNIQUE KEY `uk_phone` (`phone`)");
    } catch (Throwable $e) {
        // Ignore if already exists
    }

    $input       = json_decode(file_get_contents('php://input'), true);
    $id          = isset($input['id']) ? (int)$input['id'] : 0;
    $gender      = isset($input['gender']) ? trim((string)$input['gender']) : '';
    $first_name  = isset($input['first_name']) ? preg_replace('/\s+/', ' ', trim((string)$input['first_name'])) : '';
    $last_name   = isset($input['last_name']) ? preg_replace('/\s+/', ' ', trim((string)$input['last_name'])) : '';
    $national_id = isset($input['national_id']) ? preg_replace('/\s+/', ' ', trim((string)$input['national_id'])) : '';
    $phone       = isset($input['phone']) ? preg_replace('/\s+/', ' ', trim((string)$input['phone'])) : '';

    // Truncate to max lengths
    $first_name  = substr($first_name, 0, 40);
    $last_name   = substr($last_name, 0, 40);
    $national_id = toEnglishDigits($national_id);
    $national_id = preg_replace('/[^0-9]/', '', $national_id);
    $national_id = substr($national_id, 0, 10);
    $phone       = toEnglishDigits($phone);
    $phone       = preg_replace('/[^0-9]/', '', $phone); // Keep only digits
    $phone       = substr($phone, 0, 11);

    // Validate
    if (empty($first_name) || empty($last_name) || empty($phone) || empty($national_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'نام، نام خانوادگی، شماره ملی و شماره همراه الزامی است'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($gender, ['زن', 'مرد', ''])) {
        http_response_code(400);
        echo json_encode(['error' => 'جنسیت باید زن یا مرد باشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^\d{10}$/', $national_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'شماره ملی باید ۱۰ رقم باشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^\d{11}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'شماره همراه باید ۱۱ رقم باشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Check for duplicate national_id
    if ($id > 0) {
        // Update: check if another proctor has this national_id
        $checkStmt = $pdo->prepare('SELECT id FROM `Proctors` WHERE national_id = ? AND id != ?');
        $checkStmt->execute([$national_id, $id]);
    } else {
        // Insert: check if any proctor has this national_id
        $checkStmt = $pdo->prepare('SELECT id FROM `Proctors` WHERE national_id = ?');
        $checkStmt->execute([$national_id]);
    }
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'این شماره ملی قبلاً ثبت شده است', 'field' => 'national_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Check for duplicate phone
    if ($id > 0) {
        // Update: check if another proctor has this phone
        $checkStmt = $pdo->prepare('SELECT id FROM `Proctors` WHERE phone = ? AND id != ?');
        $checkStmt->execute([$phone, $id]);
    } else {
        // Insert: check if any proctor has this phone
        $checkStmt = $pdo->prepare('SELECT id FROM `Proctors` WHERE phone = ?');
        $checkStmt->execute([$phone]);
    }
    if ($checkStmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'این شماره همراه قبلاً ثبت شده است', 'field' => 'phone'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($id > 0) {
        // Update
        $stmt = $pdo->prepare('UPDATE `Proctors` SET gender = ?, first_name = ?, last_name = ?, national_id = ?, phone = ? WHERE id = ?');
        $ok   = $stmt->execute([$gender, $first_name, $last_name, $national_id, $phone, $id]);
    } else {
        // Insert
        $stmt = $pdo->prepare('INSERT INTO `Proctors` (gender, first_name, last_name, national_id, phone) VALUES (?, ?, ?, ?, ?)');
        $ok   = $stmt->execute([$gender, $first_name, $last_name, $national_id, $phone]);
        $id   = $pdo->lastInsertId();
    }

    if ($ok) {
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'خطا در ذخیره'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>