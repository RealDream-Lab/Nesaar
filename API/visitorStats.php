<?php
/**
 * visitorStats.php
 * 
 * API endpoint for visitor tracking and statistics.
 * Supports two modes:
 *   - POST: Record a visitor heartbeat (ping)
 *   - GET: Retrieve visitor statistics (for admin dashboard)
 * 
 * User types: 'student', 'proctor', 'admin'
 * 
 * A user is considered "online" if their last heartbeat was within 3 minutes.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_init.php';

// Create visitor_logs table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `visitor_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `session_id` VARCHAR(64) NOT NULL,
        `user_type` ENUM('student', 'proctor', 'admin', 'anonymous') NOT NULL DEFAULT 'student',
        `user_id` VARCHAR(50) DEFAULT NULL,
        `ip_address` VARCHAR(45) DEFAULT NULL,
        `user_agent` VARCHAR(512) DEFAULT NULL,
        `page` VARCHAR(255) DEFAULT NULL,
        `first_visit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `visit_count` INT UNSIGNED NOT NULL DEFAULT 1,
        UNIQUE KEY `uniq_session` (`session_id`),
        INDEX `idx_user_type` (`user_type`),
        INDEX `idx_last_activity` (`last_activity`),
        INDEX `idx_first_visit` (`first_visit`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Alter table to add 'anonymous' if it doesn't exist in the enum
    // This handles existing tables that don't have 'anonymous' type
    $pdo->exec("ALTER TABLE `visitor_logs` MODIFY COLUMN `user_type` ENUM('student', 'proctor', 'admin', 'anonymous') NOT NULL DEFAULT 'student'");
} catch (PDOException $e) {
    // Ignore errors - table might already exist with correct schema
    error_log('visitor_logs table creation/alter: ' . $e->getMessage());
}

/**
 * Handle POST request - Record visitor heartbeat
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $sessionId = $input['session_id'] ?? '';
        $userType  = $input['user_type'] ?? 'student';
        $userId    = $input['user_id'] ?? null;
        $page      = $input['page'] ?? null;

        // Validate session_id
        if (empty($sessionId) || strlen($sessionId) < 10) {
            echo json_encode(['success' => false, 'error' => 'Invalid session_id']);
            exit;
        }

        // Validate user_type
        $validTypes = ['student', 'proctor', 'admin', 'anonymous'];
        if (!in_array($userType, $validTypes)) {
            $userType = 'anonymous';
        }

        // Get client info
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ipAddress && strpos($ipAddress, ',') !== false) {
            $ipAddress = trim(explode(',', $ipAddress)[0]);
        }
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

        // Upsert visitor record
        $stmt = $pdo->prepare("
            INSERT INTO visitor_logs (session_id, user_type, user_id, ip_address, user_agent, page, first_visit, last_activity, visit_count)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
            ON DUPLICATE KEY UPDATE 
                last_activity = NOW(),
                visit_count = visit_count + 1,
                page = COALESCE(VALUES(page), page),
                user_id = COALESCE(VALUES(user_id), user_id)
        ");
        $stmt->execute([$sessionId, $userType, $userId, $ipAddress, $userAgent, $page]);

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        error_log('visitorStats POST error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
    exit;
}

/**
 * Handle GET request - Retrieve visitor statistics
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Optional: Add admin authentication check here
    // For now, we'll allow read access for stats display

    try {
        // Online threshold: 3 minutes (180 seconds)
        $onlineThreshold = 180;

        // Current online users by type (exclude anonymous from separate stats)
        $onlineStmt = $pdo->prepare("
            SELECT user_type, COUNT(DISTINCT session_id) as count
            FROM visitor_logs
            WHERE last_activity >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            GROUP BY user_type
        ");
        $onlineStmt->execute([$onlineThreshold]);
        $onlineByType = ['student' => 0, 'proctor' => 0, 'admin' => 0, 'anonymous' => 0];
        foreach ($onlineStmt->fetchAll() as $row) {
            $onlineByType[$row['user_type']] = (int)$row['count'];
        }

        // Last 24 hours unique visitors by type
        $last24Stmt = $pdo->prepare("
            SELECT user_type, COUNT(DISTINCT session_id) as count
            FROM visitor_logs
            WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY user_type
        ");
        $last24Stmt->execute();
        $last24ByType = ['student' => 0, 'proctor' => 0, 'admin' => 0, 'anonymous' => 0];
        foreach ($last24Stmt->fetchAll() as $row) {
            $last24ByType[$row['user_type']] = (int)$row['count'];
        }

        // Total unique visitors (all time) by type
        $totalStmt   = $pdo->query("
            SELECT user_type, COUNT(DISTINCT session_id) as count
            FROM visitor_logs
            GROUP BY user_type
        ");
        $totalByType = ['student' => 0, 'proctor' => 0, 'admin' => 0, 'anonymous' => 0];
        foreach ($totalStmt->fetchAll() as $row) {
            $totalByType[$row['user_type']] = (int)$row['count'];
        }

        // Today's visitors (based on first_visit)
        $todayStmt   = $pdo->query("
            SELECT user_type, COUNT(DISTINCT session_id) as count
            FROM visitor_logs
            WHERE DATE(first_visit) = CURDATE()
            GROUP BY user_type
        ");
        $todayByType = ['student' => 0, 'proctor' => 0, 'admin' => 0, 'anonymous' => 0];
        foreach ($todayStmt->fetchAll() as $row) {
            $todayByType[$row['user_type']] = (int)$row['count'];
        }

        // Calculate totals (include anonymous in totals)
        $onlineTotal   = array_sum($onlineByType);
        $last24Total   = array_sum($last24ByType);
        $totalVisitors = array_sum($totalByType);
        $todayTotal    = array_sum($todayByType);

        echo json_encode([
            'success' => true,
            'online' => [
                'total' => $onlineTotal,
                'student' => $onlineByType['student'],
                'proctor' => $onlineByType['proctor'],
                'admin' => $onlineByType['admin']
            ],
            'last24h' => [
                'total' => $last24Total,
                'student' => $last24ByType['student'],
                'proctor' => $last24ByType['proctor'],
                'admin' => $last24ByType['admin']
            ],
            'today' => [
                'total' => $todayTotal,
                'student' => $todayByType['student'],
                'proctor' => $todayByType['proctor'],
                'admin' => $todayByType['admin']
            ],
            'allTime' => [
                'total' => $totalVisitors,
                'student' => $totalByType['student'],
                'proctor' => $totalByType['proctor'],
                'admin' => $totalByType['admin']
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log('visitorStats GET error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
