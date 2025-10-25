<?php
/**
 * Audit Logging Helper
 * 
 * ثبت تمام فعالیت‌های حساس برای بررسی و امنیت
 */

declare(strict_types=1);

/**
 * Log an audit event
 */
function audit_log(
    PDO $pdo,
    string $eventType,
    string $description,
    ?string $userId = null,
    ?array $metadata = null
): void {
    try {
        // ایجاد جدول اگر وجود نداشته باشد
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS AuditLogs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                user_id VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_created_at (created_at),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO AuditLogs (event_type, description, user_id, ip_address, user_agent, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $eventType,
            $description,
            $userId,
            $ip,
            $userAgent,
            $metadataJson
        ]);
        
    } catch (PDOException $e) {
        // Log to error log if database fails
        error_log("Audit log failed: " . $e->getMessage());
    }
}

/**
 * Log license-related events
 */
function audit_log_license(PDO $pdo, string $action, string $status, ?array $details = null): void
{
    audit_log(
        $pdo,
        'license_check',
        "License check: $action - $status",
        null,
        array_merge(['action' => $action, 'status' => $status], $details ?? [])
    );
}

/**
 * Log authentication attempts
 */
function audit_log_auth(PDO $pdo, string $action, bool $success, ?string $userId = null): void
{
    audit_log(
        $pdo,
        'authentication',
        $success ? "Auth successful: $action" : "Auth failed: $action",
        $userId,
        ['action' => $action, 'success' => $success]
    );
}

/**
 * Log configuration changes
 */
function audit_log_config(PDO $pdo, string $configKey, $oldValue, $newValue, ?string $userId = null): void
{
    audit_log(
        $pdo,
        'config_change',
        "Config changed: $configKey",
        $userId,
        [
            'config_key' => $configKey,
            'old_value' => $oldValue,
            'new_value' => $newValue
        ]
    );
}

/**
 * Log API access
 */
function audit_log_api(PDO $pdo, string $endpoint, int $httpStatus, ?float $duration = null): void
{
    audit_log(
        $pdo,
        'api_access',
        "API access: $endpoint - HTTP $httpStatus",
        null,
        [
            'endpoint' => $endpoint,
            'http_status' => $httpStatus,
            'duration_ms' => $duration
        ]
    );
}

/**
 * Clean up old audit logs (older than specified days)
 */
function audit_cleanup(PDO $pdo, int $keepDays = 90): int
{
    try {
        $stmt = $pdo->prepare("
            DELETE FROM AuditLogs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$keepDays]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Audit cleanup failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get recent audit logs
 */
function audit_get_recent(PDO $pdo, int $limit = 100, ?string $eventType = null): array
{
    try {
        if ($eventType) {
            $stmt = $pdo->prepare("
                SELECT * FROM AuditLogs 
                WHERE event_type = ?
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$eventType, $limit]);
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM AuditLogs 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to get audit logs: " . $e->getMessage());
        return [];
    }
}

?>
