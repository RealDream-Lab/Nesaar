<?php
/**
 * Initialize Push Notification database tables
 * Run this once to create necessary tables
 */

require_once __DIR__ . '/../db_init.php';

try {
    // Create push_subscriptions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_type` ENUM('student', 'proctor', 'admin') NOT NULL,
        `user_id` VARCHAR(20) NOT NULL COMMENT 'student_id, proctor_id, or admin username',
        `endpoint` TEXT NOT NULL,
        `p256dh` VARCHAR(255) NOT NULL COMMENT 'Public key for encryption',
        `auth` VARCHAR(255) NOT NULL COMMENT 'Auth secret',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY `ux_user_endpoint` (`user_type`, `user_id`, `endpoint`(255)),
        INDEX `idx_user` (`user_type`, `user_id`),
        INDEX `idx_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Create push_notifications_log table for tracking sent notifications
    $pdo->exec("CREATE TABLE IF NOT EXISTS `push_notifications_log` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `subscription_id` INT UNSIGNED NOT NULL,
        `notification_type` VARCHAR(50) NOT NULL COMMENT 'exam_reminder, assignment, etc.',
        `title` VARCHAR(255) NOT NULL,
        `body` TEXT,
        `data` JSON,
        `status` ENUM('pending', 'sent', 'failed', 'expired') NOT NULL DEFAULT 'pending',
        `error_message` TEXT,
        `sent_at` TIMESTAMP NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_subscription` (`subscription_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_type` (`notification_type`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Create push_scheduled table for scheduling notifications
    $pdo->exec("CREATE TABLE IF NOT EXISTS `push_scheduled` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_type` ENUM('student', 'proctor') NOT NULL,
        `user_id` VARCHAR(20) NOT NULL,
        `exam_date` VARCHAR(10) NOT NULL COMMENT 'Shamsi date like 1404/08/24',
        `exam_time` VARCHAR(5) NOT NULL COMMENT 'Time like 09:30',
        `scheduled_send_time` DATETIME NOT NULL COMMENT 'When to send (30 min before exam)',
        `notification_type` VARCHAR(50) NOT NULL DEFAULT 'exam_reminder',
        `title` VARCHAR(255) NOT NULL,
        `body` TEXT,
        `data` JSON,
        `status` ENUM('pending', 'sent', 'cancelled') NOT NULL DEFAULT 'pending',
        `sent_at` TIMESTAMP NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `ux_user_exam` (`user_type`, `user_id`, `exam_date`, `exam_time`),
        INDEX `idx_scheduled_time` (`scheduled_send_time`, `status`),
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add VAPID keys to Config table if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Config WHERE ConfigName = 'VAPID_PUBLIC_KEY'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        // Generate VAPID keys
        require_once __DIR__ . '/../../vendor/autoload.php';

        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();

        $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('VAPID_PUBLIC_KEY', ?)")
            ->execute([$keys['publicKey']]);
        $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('VAPID_PRIVATE_KEY', ?)")
            ->execute([$keys['privateKey']]);
        $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('VAPID_SUBJECT', ?)")
            ->execute(['mailto:admin@example.com']);

        echo "VAPID keys generated successfully.\n";
        echo "Public Key: " . $keys['publicKey'] . "\n";
    }

    echo json_encode([
        'success' => true,
        'message' => 'Push notification tables created successfully'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Failed to create tables: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
