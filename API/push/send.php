<?php
/**
 * Send Push Notification API
 * Sends push notifications to subscribed users
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/license_guard.php';
require_once __DIR__ . '/../../includes/csrf_protection.php';
require_once __DIR__ . '/../../includes/admin_session.php';
require_once __DIR__ . '/../db_init.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Validate license
$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => $licenseStatus['message'] ?? 'دسترسی ممنوع'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Admin authentication required for manual push
$sessionData = admin_session_require($pdo);

// CSRF protection
try {
    csrf_enforce();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'CSRF token invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Invalid JSON input'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate required fields
if (empty($input['title'])) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'عنوان الزامی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Get VAPID keys from Config
    $stmt   = $pdo->query("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ('VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT')");
    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }

    if (empty($config['VAPID_PUBLIC_KEY']) || empty($config['VAPID_PRIVATE_KEY'])) {
        http_response_code(500);
        echo json_encode(['error' => true, 'message' => 'VAPID keys not configured'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Initialize WebPush
    $auth = [
        'VAPID' => [
            'subject' => $config['VAPID_SUBJECT'] ?? 'mailto:admin@example.com',
            'publicKey' => $config['VAPID_PUBLIC_KEY'],
            'privateKey' => $config['VAPID_PRIVATE_KEY'],
        ],
    ];

    $webPush = new WebPush($auth);

    // Build query for subscriptions
    $query  = "SELECT ps.* FROM push_subscriptions ps WHERE ps.is_active = 1";
    $params = [];

    // Filter by user type if specified
    if (!empty($input['user_type'])) {
        $query    .= " AND ps.user_type = ?";
        $params[]  = $input['user_type'];
    }

    // Filter by specific user if specified
    if (!empty($input['user_id'])) {
        $query    .= " AND ps.user_id = ?";
        $params[]  = $input['user_id'];
    }

    // Filter students by date/session/course if specified
    if (!empty($input['filters']) && $input['user_type'] === 'student') {
        $filters = $input['filters'];

        // Support both array format (dates, sessions, courses) and single value format
        $hasDates    = !empty($filters['dates']) && is_array($filters['dates']);
        $hasSessions = !empty($filters['sessions']) && is_array($filters['sessions']);
        $hasCourses  = !empty($filters['courses']) && is_array($filters['courses']);
        $hasFilter   = $hasDates || $hasSessions || $hasCourses;

        if ($hasFilter) {
            // Join with exam_seats to filter by exam criteria
            $query  = "SELECT DISTINCT ps.* FROM push_subscriptions ps
                      INNER JOIN exam_seats es ON ps.user_id = es.student_number
                      WHERE ps.is_active = 1 AND ps.user_type = 'student'";
            $params = [];

            // Filter by dates (array of date strings)
            if ($hasDates) {
                $placeholders  = implode(',', array_fill(0, count($filters['dates']), '?'));
                $query        .= " AND es.date IN ($placeholders)";
                $params        = array_merge($params, $filters['dates']);
            }

            // Filter by sessions (array of {exam_date, exam_time} objects)
            if ($hasSessions) {
                $sessionConditions = [];
                foreach ($filters['sessions'] as $session) {
                    if (!empty($session['exam_date']) && !empty($session['exam_time'])) {
                        $sessionConditions[] = "(es.date = ? AND es.session = ?)";
                        $params[]            = $session['exam_date'];
                        $params[]            = $session['exam_time'];
                    }
                }
                if (!empty($sessionConditions)) {
                    $query .= " AND (" . implode(' OR ', $sessionConditions) . ")";
                }
            }

            // Filter by courses (array of course codes)
            if ($hasCourses) {
                $placeholders  = implode(',', array_fill(0, count($filters['courses']), '?'));
                $query        .= " AND es.course_code IN ($placeholders)";
                $params        = array_merge($params, $filters['courses']);
            }
        }
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll();

    if (empty($subscriptions)) {
        echo json_encode([
            'success' => true,
            'message' => 'هیچ اشتراک فعالی یافت نشد',
            'sent' => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Prepare notification payload
    $payload = json_encode([
        'title' => $input['title'],
        'body' => $input['body'] ?? '',
        'icon' => $input['icon'] ?? '/pwa-icons/icon-192.png',
        'badge' => $input['badge'] ?? '/pwa-icons/icon-192.png',
        'data' => $input['data'] ?? [],
        'tag' => $input['tag'] ?? 'default',
        'requireInteraction' => $input['requireInteraction'] ?? false
    ], JSON_UNESCAPED_UNICODE);

    $sent        = 0;
    $failed      = 0;
    $expired     = 0;
    $failReasons = [];

    // Send in batches to avoid connection pool exhaustion
    $batchSize = 50;
    $chunks    = array_chunk($subscriptions, $batchSize);

    foreach ($chunks as $chunkIndex => $chunk) {
        // Queue this batch of notifications
        foreach ($chunk as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh'],
                'authToken' => $sub['auth'],
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        // Send this batch
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $sent++;
            } else {
                // Check if subscription expired
                if ($report->isSubscriptionExpired()) {
                    $expired++;
                    // Deactivate expired subscription
                    $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?")
                        ->execute([$endpoint]);
                } else {
                    $failed++;
                    $reason               = $report->getReason();
                    $failReasons[$reason] = ($failReasons[$reason] ?? 0) + 1;
                    error_log("Push failed for {$endpoint}: " . $reason);
                }
            }
        }

        // Small delay between batches to prevent connection exhaustion
        if ($chunkIndex < count($chunks) - 1) {
            usleep(100000); // 100ms delay between batches
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'ارسال انجام شد',
        'sent' => $sent,
        'failed' => $failed,
        'expired' => $expired,
        'total' => count($subscriptions),
        'failReasons' => $failReasons
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('Send push error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'خطا در ارسال نوتیفیکیشن: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
