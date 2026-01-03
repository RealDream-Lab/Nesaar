<?php
/**
 * Cron job script for sending scheduled push notifications
 * Run every minute: * * * * * php /var/www/html/API/push/cron_send_scheduled.php
 */

// Set timezone to Iran
date_default_timezone_set('Asia/Tehran');

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from CLI');
}

require_once __DIR__ . '/../db_init.php';

// Get pending notifications that are due
try {
    $stmt = $pdo->prepare("
        SELECT * FROM scheduled_push_notifications 
        WHERE status = 'pending' 
        AND scheduled_at <= NOW()
        ORDER BY scheduled_at ASC
        LIMIT 10
    ");
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($notifications)) {
        exit; // No pending notifications
    }

    // Load web-push library
    require_once __DIR__ . '/../../vendor/autoload.php';

    // Get VAPID keys from config
    $configStmt = $pdo->prepare("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ('VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY')");
    $configStmt->execute();
    $config = [];
    while ($row = $configStmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }

    $vapidPublicKey  = $config['VAPID_PUBLIC_KEY'] ?? '';
    $vapidPrivateKey = $config['VAPID_PRIVATE_KEY'] ?? '';

    if (empty($vapidPublicKey) || empty($vapidPrivateKey)) {
        error_log('[Push Cron] VAPID keys not configured');
        exit;
    }

    $auth = [
        'VAPID' => [
            'subject' => 'mailto:admin@pnu.ac.ir',
            'publicKey' => $vapidPublicKey,
            'privateKey' => $vapidPrivateKey,
        ],
    ];

    $webPush = new \Minishlink\WebPush\WebPush($auth);

    foreach ($notifications as $notification) {
        $id        = $notification['id'];
        $title     = $notification['title'];
        $body      = $notification['body'];
        $icon      = $notification['icon'];
        $userTypes = explode(',', $notification['user_types']);

        $totalSent   = 0;
        $totalFailed = 0;

        // Get subscribers for each user type
        foreach ($userTypes as $userType) {
            $userType = trim($userType);

            $subStmt = $pdo->prepare("
                SELECT * FROM push_subscriptions 
                WHERE user_type = ? 
                AND is_active = 1
            ");
            $subStmt->execute([$userType]);
            $subscriptions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subscriptions as $sub) {
                try {
                    $subscription = \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'publicKey' => $sub['p256dh'],
                        'authToken' => $sub['auth'],
                    ]);

                    $payload = json_encode([
                        'title' => $title,
                        'body' => $body,
                        'icon' => $icon,
                        'tag' => 'scheduled-' . $id,
                        'data' => [
                            'url' => '/',
                            'scheduled_id' => $id,
                        ],
                    ]);

                    $webPush->queueNotification($subscription, $payload);
                } catch (Exception $e) {
                    $totalFailed++;
                    error_log('[Push Cron] Failed to queue notification: ' . $e->getMessage());
                }
            }
        }

        // Send all queued notifications
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $totalSent++;
            } else {
                $totalFailed++;

                // Remove expired subscriptions
                if ($report->isSubscriptionExpired()) {
                    $endpoint = $report->getEndpoint();
                    $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
                }
            }
        }

        // Update notification status
        $status     = ($totalSent > 0 || $totalFailed === 0) ? 'sent' : 'failed';
        $updateStmt = $pdo->prepare("
            UPDATE scheduled_push_notifications 
            SET status = ?, sent_at = NOW(), sent_count = ?, failed_count = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$status, $totalSent, $totalFailed, $id]);

        echo "[Push Cron] Notification $id: Sent=$totalSent, Failed=$totalFailed, Status=$status\n";
    }

} catch (Exception $e) {
    error_log('[Push Cron] Error: ' . $e->getMessage());
    exit(1);
}
