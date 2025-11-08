<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();
    csrf_enforce();

    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $gender = isset($input['gender']) ? trim((string)$input['gender']) : '';
    $first_name = isset($input['first_name']) ? trim((string)$input['first_name']) : '';
    $last_name = isset($input['last_name']) ? trim((string)$input['last_name']) : '';
    $phone = isset($input['phone']) ? trim((string)$input['phone']) : '';

    // Validate
    if (empty($first_name) || empty($last_name) || empty($phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'نام، نام خانوادگی و شماره همراه الزامی است'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($gender, ['زن', 'مرد', ''])) {
        http_response_code(400);
        echo json_encode(['error' => 'جنسیت باید زن یا مرد باشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($first_name) > 40 || strlen($last_name) > 40 || strlen($phone) > 11) {
        http_response_code(400);
        echo json_encode(['error' => 'طول فیلدها بیش از حد مجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `Proctors` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `gender` VARCHAR(3) DEFAULT '',
        `first_name` VARCHAR(40) DEFAULT '',
        `last_name` VARCHAR(40) DEFAULT '',
        `phone` VARCHAR(11) DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($id > 0) {
        // Update
        $stmt = $pdo->prepare('UPDATE `Proctors` SET gender = ?, first_name = ?, last_name = ?, phone = ? WHERE id = ?');
        $ok = $stmt->execute([$gender, $first_name, $last_name, $phone, $id]);
    } else {
        // Insert
        $stmt = $pdo->prepare('INSERT INTO `Proctors` (gender, first_name, last_name, phone) VALUES (?, ?, ?, ?)');
        $ok = $stmt->execute([$gender, $first_name, $last_name, $phone]);
        $id = $pdo->lastInsertId();
    }

    if ($ok) {
        echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'خطا در ذخیره'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
?>