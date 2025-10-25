<?php
/**
 * Rate Limiting Helper
 * 
 * محافظت در برابر abuse و brute force attacks
 */

declare(strict_types=1);

/**
 * Check if request should be rate limited
 * 
 * @param PDO $pdo Database connection
 * @param string $key Unique identifier (e.g., 'license_check', 'login_attempt')
 * @param int $maxAttempts Maximum allowed attempts
 * @param int $windowSeconds Time window in seconds
 * @return bool True if rate limit exceeded
 */
function rate_limit_check(PDO $pdo, string $key, int $maxAttempts = 10, int $windowSeconds = 60): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $identifier = $key . ':' . $ip;
    $now = time();
    $windowStart = $now - $windowSeconds;
    
    try {
        // ایجاد جدول اگر وجود نداشته باشد
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS RateLimits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                timestamp INT NOT NULL,
                INDEX idx_identifier_timestamp (identifier, timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // پاک کردن رکوردهای قدیمی (بیش از 1 ساعت)
        $stmt = $pdo->prepare("DELETE FROM RateLimits WHERE timestamp < ?");
        $stmt->execute([$now - 3600]);
        
        // شمارش تلاش‌ها در بازه زمانی
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempt_count 
            FROM RateLimits 
            WHERE identifier = ? AND timestamp >= ?
        ");
        $stmt->execute([$identifier, $windowStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $attemptCount = intval($row['attempt_count'] ?? 0);
        
        // ثبت تلاش جدید
        $stmt = $pdo->prepare("INSERT INTO RateLimits (identifier, timestamp) VALUES (?, ?)");
        $stmt->execute([$identifier, $now]);
        
        // بررسی محدودیت
        return $attemptCount >= $maxAttempts;
        
    } catch (PDOException $e) {
        error_log("Rate limit check failed: " . $e->getMessage());
        // در صورت خطا، اجازه درخواست را می‌دهیم (fail-open)
        return false;
    }
}

/**
 * Enforce rate limiting with 429 response
 */
function rate_limit_enforce(PDO $pdo, string $key, int $maxAttempts = 10, int $windowSeconds = 60): void
{
    if (rate_limit_check($pdo, $key, $maxAttempts, $windowSeconds)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $windowSeconds);
        echo json_encode([
            'error' => 'rate_limit_exceeded',
            'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً چند لحظه صبر کنید.',
            'retry_after' => $windowSeconds
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Get remaining attempts before rate limit
 */
function rate_limit_remaining(PDO $pdo, string $key, int $maxAttempts = 10, int $windowSeconds = 60): int
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $identifier = $key . ':' . $ip;
    $now = time();
    $windowStart = $now - $windowSeconds;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempt_count 
            FROM RateLimits 
            WHERE identifier = ? AND timestamp >= ?
        ");
        $stmt->execute([$identifier, $windowStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $attemptCount = intval($row['attempt_count'] ?? 0);
        
        return max(0, $maxAttempts - $attemptCount);
        
    } catch (PDOException $e) {
        error_log("Rate limit check failed: " . $e->getMessage());
        return $maxAttempts;
    }
}

/**
 * Clear rate limit for a specific identifier
 */
function rate_limit_clear(PDO $pdo, string $key): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $identifier = $key . ':' . $ip;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM RateLimits WHERE identifier = ?");
        $stmt->execute([$identifier]);
    } catch (PDOException $e) {
        error_log("Rate limit clear failed: " . $e->getMessage());
    }
}

?>
