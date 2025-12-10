<?php
/**
 * Push Notification Helper
 * 
 * توابع کمکی برای ارسال پوش نوتیفیکیشن به کاربران
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Send push notification to a specific user
 * 
 * @param PDO $pdo Database connection
 * @param string $userId User ID (student_id or proctor_id)
 * @param string $title Notification title
 * @param string $body Notification body
 * @param array $data Additional data
 * @return array Result with sent/failed counts
 */
function send_push_to_user(
    PDO $pdo,
    string $userId,
    string $title,
    string $body,
    array $data = []
): array {
    try {
        // Get VAPID keys from Config
        $stmt   = $pdo->query("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ('VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT')");
        $config = [];
        while ($row = $stmt->fetch()) {
            $config[$row['ConfigName']] = $row['ConfigValue'];
        }

        if (empty($config['VAPID_PUBLIC_KEY']) || empty($config['VAPID_PRIVATE_KEY'])) {
            return ['success' => false, 'error' => 'VAPID keys not configured', 'sent' => 0];
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

        // Get user subscriptions
        $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll();

        if (empty($subscriptions)) {
            return ['success' => true, 'message' => 'No active subscription found', 'sent' => 0];
        }

        // Prepare notification payload
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/pwa-icons/icon-192.png',
            'badge' => '/pwa-icons/icon-192.png',
            'data' => $data,
            'tag' => 'photo-review-' . $userId,
            'requireInteraction' => false
        ], JSON_UNESCAPED_UNICODE);

        $sent   = 0;
        $failed = 0;

        // Queue notifications
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh'],
                'authToken' => $sub['auth'],
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        // Send all notifications
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $sent++;
            } else {
                if ($report->isSubscriptionExpired()) {
                    // Deactivate expired subscription
                    $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?")
                        ->execute([$endpoint]);
                }
                $failed++;
                error_log("Push failed for {$endpoint}: " . $report->getReason());
            }
        }

        return [
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'total' => count($subscriptions)
        ];

    } catch (Throwable $e) {
        error_log('Push helper error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage(), 'sent' => 0];
    }
}
