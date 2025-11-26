<?php
/**
 * Cron Job: Send Exam Reminder Push Notifications
 * Sends notifications 30 minutes before exam starts
 * 
 * Add to crontab (run every minute):
 * * * * * * php /var/www/html/scripts/cron_push_notifications.php >> /var/log/push_cron.log 2>&1
 */

// This script can run from CLI or be called internally
if (php_sapi_name() !== 'cli' && !defined('INTERNAL_PUSH_CRON')) {
    // Verify internal auth for web calls
    require_once __DIR__ . '/../includes/internal_auth.php';
    internal_auth_enforce();
}

require_once __DIR__ . '/../API/db_init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Log function
function pushLog($message)
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
    error_log("[Push Cron] {$message}");
}

pushLog("Starting push notification cron job...");

try {
    // Get VAPID keys from Config
    $stmt   = $pdo->query("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ('VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT')");
    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }

    if (empty($config['VAPID_PUBLIC_KEY']) || empty($config['VAPID_PRIVATE_KEY'])) {
        pushLog("ERROR: VAPID keys not configured");
        exit(1);
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
    $webPush->setReuseVAPIDHeaders(true);

    // Get current Shamsi date and time
    $currentTimestamp = time();
    $targetTimestamp  = $currentTimestamp + (30 * 60); // 30 minutes from now

    // Get Shamsi date/time for target (with English digits for DB matching)
    $targetShamsiDate = jdate('Y/m/d', $targetTimestamp, '', 'Asia/Tehran', 'en');
    $targetShamsiTime = jdate('H:i', $targetTimestamp, '', 'Asia/Tehran', 'en');

    // Also check current minute (for exams starting in exactly 30 minutes)
    $currentShamsiDate = jdate('Y/m/d', $currentTimestamp, '', 'Asia/Tehran', 'en');
    $currentShamsiTime = jdate('H:i', $currentTimestamp, '', 'Asia/Tehran', 'en');

    pushLog("Current Shamsi: {$currentShamsiDate} {$currentShamsiTime}");
    pushLog("Target Shamsi (30 min later): {$targetShamsiDate} {$targetShamsiTime}");

    // Find exams starting in 30 minutes (with 1-minute tolerance)
    // We check for exams where exam_time matches the time 30 minutes from now
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.course_code, c.course_name, c.exam_date, c.exam_time
        FROM courses c
        WHERE c.exam_date = ?
        AND c.exam_time = ?
    ");
    $stmt->execute([$targetShamsiDate, $targetShamsiTime]);
    $upcomingExams = $stmt->fetchAll();

    if (empty($upcomingExams)) {
        pushLog("No exams starting in 30 minutes");
        exit(0);
    }

    pushLog("Found " . count($upcomingExams) . " exam(s) starting in 30 minutes");

    $totalSent    = 0;
    $totalFailed  = 0;
    $totalExpired = 0;

    foreach ($upcomingExams as $exam) {
        pushLog("Processing exam: {$exam['course_name']} at {$exam['exam_time']}");

        // =====================================================
        // Send to Students
        // =====================================================
        $stmt = $pdo->prepare("
            SELECT DISTINCT es.student_id, s.first_name, s.last_name,
                   es.building, es.class_name, es.seat_number
            FROM exam_seats es
            JOIN students s ON s.student_id = es.student_id
            JOIN courses c ON c.course_code = es.course_code
            WHERE c.course_code = ?
        ");
        $stmt->execute([$exam['course_code']]);
        $students = $stmt->fetchAll();

        foreach ($students as $student) {
            // Get push subscriptions for this student
            $subStmt = $pdo->prepare("
                SELECT * FROM push_subscriptions 
                WHERE user_type = 'student' 
                AND user_id = ? 
                AND is_active = 1
            ");
            $subStmt->execute([$student['student_id']]);
            $subscriptions = $subStmt->fetchAll();

            if (empty($subscriptions))
                continue;

            $payload = json_encode([
                'title' => '⏰ یادآوری آزمون - ' . $exam['course_name'],
                'body' => "آزمون شما ساعت {$exam['exam_time']} شروع می‌شود\nمکان: {$student['building']} - {$student['class_name']}\nشماره صندلی: {$student['seat_number']}",
                'icon' => '/pwa-icons/icon-192.png',
                'badge' => '/pwa-icons/icon-192.png',
                'tag' => 'exam-' . $exam['course_code'],
                'requireInteraction' => true,
                'data' => [
                    'type' => 'exam_reminder',
                    'user_type' => 'student',
                    'student_id' => $student['student_id'],
                    'course_code' => $exam['course_code'],
                    'exam_date' => $exam['exam_date'],
                    'exam_time' => $exam['exam_time']
                ]
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $sub) {
                try {
                    $subscription = Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'publicKey' => $sub['p256dh'],
                        'authToken' => $sub['auth'],
                    ]);

                    $webPush->queueNotification($subscription, $payload);
                } catch (Throwable $e) {
                    pushLog("Error queuing notification: " . $e->getMessage());
                }
            }
        }

        // =====================================================
        // Send to Proctors
        // =====================================================
        $stmt = $pdo->prepare("
            SELECT DISTINCT ea.proctor_id, ea.proctor_name, ea.building, ea.class_name
            FROM ExamAssignments ea
            WHERE ea.exam_date = ? AND ea.exam_time = ?
            AND ea.proctor_id IS NOT NULL AND ea.proctor_id > 0
        ");
        $stmt->execute([$exam['exam_date'], $exam['exam_time']]);
        $proctors = $stmt->fetchAll();

        foreach ($proctors as $proctor) {
            // Get push subscriptions for this proctor
            $subStmt = $pdo->prepare("
                SELECT * FROM push_subscriptions 
                WHERE user_type = 'proctor' 
                AND user_id = ? 
                AND is_active = 1
            ");
            $subStmt->execute([$proctor['proctor_id']]);
            $subscriptions = $subStmt->fetchAll();

            if (empty($subscriptions))
                continue;

            $payload = json_encode([
                'title' => '⏰ یادآوری مراقبت - ' . $exam['exam_time'],
                'body' => "شیفت مراقبت شما ساعت {$exam['exam_time']} شروع می‌شود\nمکان: {$proctor['building']} - {$proctor['class_name']}",
                'icon' => '/pwa-icons/icon-192.png',
                'badge' => '/pwa-icons/icon-192.png',
                'tag' => 'proctor-' . $exam['exam_date'] . '-' . $exam['exam_time'],
                'requireInteraction' => true,
                'data' => [
                    'type' => 'exam_reminder',
                    'user_type' => 'proctor',
                    'proctor_id' => $proctor['proctor_id'],
                    'exam_date' => $exam['exam_date'],
                    'exam_time' => $exam['exam_time']
                ]
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $sub) {
                try {
                    $subscription = Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'publicKey' => $sub['p256dh'],
                        'authToken' => $sub['auth'],
                    ]);

                    $webPush->queueNotification($subscription, $payload);
                } catch (Throwable $e) {
                    pushLog("Error queuing notification: " . $e->getMessage());
                }
            }
        }
    }

    // Flush all queued notifications
    pushLog("Sending queued notifications...");

    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();

        if ($report->isSuccess()) {
            $totalSent++;
        } else {
            if ($report->isSubscriptionExpired()) {
                $totalExpired++;
                // Deactivate expired subscription
                $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?")
                    ->execute([$endpoint]);
                pushLog("Subscription expired and deactivated: " . substr($endpoint, 0, 50) . "...");
            } else {
                $totalFailed++;
                pushLog("Failed: " . $report->getReason());
            }
        }
    }

    pushLog("Completed: Sent={$totalSent}, Failed={$totalFailed}, Expired={$totalExpired}");

    // Cleanup: Delete inactive subscriptions older than 7 days
    $cleanupStmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE is_active = 0 AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $cleanupStmt->execute();
    $deletedCount = $cleanupStmt->rowCount();
    if ($deletedCount > 0) {
        pushLog("Cleanup: Deleted {$deletedCount} old inactive subscriptions");
    }

} catch (Throwable $e) {
    pushLog("FATAL ERROR: " . $e->getMessage());
    pushLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

pushLog("Cron job finished successfully");
exit(0);
