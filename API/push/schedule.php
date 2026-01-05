<?php
/**
 * API endpoint for scheduling push notifications
 * Saves scheduled notifications to database for later processing by cron
 */
header('Content-Type: application/json; charset=utf-8');

// Set timezone to Iran for correct time comparison
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../../includes/privileged_session.php';
require_once __DIR__ . '/../db_init.php';
require_once __DIR__ . '/../jdf.php';

// Require admin session
$session = privileged_session_require($pdo);

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$title       = trim($input['title'] ?? '');
$body        = trim($input['body'] ?? '');
$icon        = trim($input['icon'] ?? '/pwa-icons/icon-192.png');
$userTypes   = $input['user_types'] ?? [];
$scheduledAt = trim($input['scheduled_at'] ?? '');

// Validate inputs
if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'عنوان پیام الزامی است']);
    exit;
}

if (empty($body)) {
    echo json_encode(['success' => false, 'error' => 'متن پیام الزامی است']);
    exit;
}

if (empty($userTypes) || !is_array($userTypes)) {
    echo json_encode(['success' => false, 'error' => 'حداقل یک گروه گیرنده باید انتخاب شود']);
    exit;
}

if (empty($scheduledAt)) {
    echo json_encode(['success' => false, 'error' => 'تاریخ و ساعت ارسال الزامی است']);
    exit;
}

// Parse Jalali datetime (format: 1404/10/14 15:30)
// Convert Persian digits to English
$scheduledAt = str_replace(
    ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
    ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
    $scheduledAt
);

// Parse date and time (supports with or without seconds: 1404/10/14 15:30 or 1404/10/14 15:30:00)
if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})\s+(\d{1,2}):(\d{2})(:\d{2})?$/', $scheduledAt, $matches)) {
    $jYear  = (int)$matches[1];
    $jMonth = (int)$matches[2];
    $jDay   = (int)$matches[3];
    $hour   = (int)$matches[4];
    $minute = (int)$matches[5];

    // Convert Jalali to Gregorian
    list($gYear, $gMonth, $gDay) = jalali_to_gregorian($jYear, $jMonth, $jDay);

    // Create datetime string
    $scheduledDatetime = sprintf('%04d-%02d-%02d %02d:%02d:00', $gYear, $gMonth, $gDay, $hour, $minute);

    // Validate the datetime is in the future
    $scheduledTimestamp = strtotime($scheduledDatetime);
    if ($scheduledTimestamp === false || $scheduledTimestamp <= time()) {
        echo json_encode(['success' => false, 'error' => 'تاریخ و ساعت باید در آینده باشد']);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'error' => 'فرمت تاریخ و ساعت نامعتبر است']);
    exit;
}

// Ensure scheduled_push_notifications table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scheduled_push_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            icon VARCHAR(255) DEFAULT '/pwa-icons/icon-192.png',
            user_types VARCHAR(100) NOT NULL,
            scheduled_at DATETIME NOT NULL,
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME DEFAULT NULL,
            sent_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            INDEX idx_status_scheduled (status, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    error_log('Failed to create scheduled_push_notifications table: ' . $e->getMessage());
}

// Insert scheduled notification
try {
    $stmt = $pdo->prepare("
        INSERT INTO scheduled_push_notifications (title, body, icon, user_types, scheduled_at)
        VALUES (?, ?, ?, ?, ?)
    ");

    $userTypesStr = implode(',', $userTypes);
    $stmt->execute([$title, $body, $icon, $userTypesStr, $scheduledDatetime]);

    echo json_encode([
        'success' => true,
        'message' => 'اعلان با موفقیت زمان‌بندی شد',
        'scheduled_id' => $pdo->lastInsertId(),
        'scheduled_at' => $scheduledAt
    ]);
} catch (Exception $e) {
    error_log('Failed to schedule push notification: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'خطا در ذخیره‌سازی زمان‌بندی']);
}
