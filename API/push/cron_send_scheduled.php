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
        $filters   = !empty($notification['filters']) ? json_decode($notification['filters'], true) : null;

        $totalSent   = 0;
        $totalFailed = 0;

        // Get subscribers for each user type
        foreach ($userTypes as $userType) {
            $userType = trim($userType);

            // Build query based on filters (for students)
            if ($userType === 'student' && $filters) {
                $filterMode  = $filters['mode'] ?? 'session';
                $hasDates    = !empty($filters['dates']) && is_array($filters['dates']);
                $hasSessions = !empty($filters['sessions']) && is_array($filters['sessions']);
                $hasCourses  = !empty($filters['courses']) && is_array($filters['courses']);
                
                if ($hasDates || $hasSessions || $hasCourses) {
                    $query  = "SELECT DISTINCT ps.* FROM push_subscriptions ps
                              INNER JOIN exam_seats es ON ps.user_id = es.student_number
                              WHERE ps.is_active = 1 AND ps.user_type = 'student'";
                    $params = [];
                    
                    if ($filterMode === 'session') {
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
                        } elseif ($hasDates) {
                            $placeholders = implode(',', array_fill(0, count($filters['dates']), '?'));
                            $query       .= " AND es.date IN ($placeholders)";
                            $params       = array_merge($params, $filters['dates']);
                        }
                    } else {
                        if ($hasCourses) {
                            $placeholders = implode(',', array_fill(0, count($filters['courses']), '?'));
                            $query       .= " AND es.course_code IN ($placeholders)";
                            $params       = array_merge($params, $filters['courses']);
                        }
                    }
                    
                    $subStmt = $pdo->prepare($query);
                    $subStmt->execute($params);
                    $subscriptions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // No valid filters, send to all students
                    $subStmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_type = ? AND is_active = 1");
                    $subStmt->execute([$userType]);
                    $subscriptions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } else {
                // No filters or not student - get all subscribers for this type
                $subStmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_type = ? AND is_active = 1");
                $subStmt->execute([$userType]);
                $subscriptions = $subStmt->fetchAll(PDO::FETCH_ASSOC);
            }

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
