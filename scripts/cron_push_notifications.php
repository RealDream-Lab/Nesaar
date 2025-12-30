<?php
/**
 * Cron Job: Send Exam Reminder Push Notifications
 * Sends notifications X minutes before exam starts (configurable via PushReminderMinutes in Config table, default 30)
 * 
 * Add to crontab (run every minute to catch all exam times):
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

function toPersianDigits($text)
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, $text);
}

pushLog("Starting push notification cron job...");

try {
    // Ensure sent_push_notifications table exists for duplicate prevention
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sent_push_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_key VARCHAR(255) NOT NULL UNIQUE,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notification_key (notification_key),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Get VAPID keys and Push Reminder config
    $stmt   = $pdo->query("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ('VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT', 'PushReminderMinutes')");
    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }

    if (empty($config['VAPID_PUBLIC_KEY']) || empty($config['VAPID_PRIVATE_KEY'])) {
        pushLog("ERROR: VAPID keys not configured");
        exit(1);
    }

    // Get reminder minutes from config (default 30)
    $reminderMinutes = 30;
    if (!empty($config['PushReminderMinutes'])) {
        $configMinutes = (int)$config['PushReminderMinutes'];
        if ($configMinutes >= 30 && $configMinutes <= 180) {
            $reminderMinutes = $configMinutes;
        }
    }
    pushLog("Using reminder time: {$reminderMinutes} minutes before exam");

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
    $targetTimestamp  = $currentTimestamp + ($reminderMinutes * 60); // X minutes from now

    // Get Shamsi date/time for target (with English digits for DB matching)
    $targetShamsiDate = jdate('Y/m/d', $targetTimestamp, '', 'Asia/Tehran', 'en');
    $targetShamsiTime = jdate('H:i', $targetTimestamp, '', 'Asia/Tehran', 'en');

    // Also check current minute (for exams starting in X minutes)
    $currentShamsiDate = jdate('Y/m/d', $currentTimestamp, '', 'Asia/Tehran', 'en');
    $currentShamsiTime = jdate('H:i', $currentTimestamp, '', 'Asia/Tehran', 'en');

    pushLog("Current Shamsi: {$currentShamsiDate} {$currentShamsiTime}");
    pushLog("Target Shamsi ({$reminderMinutes} min later): {$targetShamsiDate} {$targetShamsiTime}");

    // Find exams starting in X minutes (with 1-minute tolerance)
    // We check for exams where exam_time matches the time X minutes from now
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.course_code, c.course_name, c.exam_date, c.exam_time
        FROM courses c
        WHERE c.exam_date = ?
        AND c.exam_time = ?
    ");
    $stmt->execute([$targetShamsiDate, $targetShamsiTime]);
    $upcomingExams = $stmt->fetchAll();

    if (empty($upcomingExams)) {
        pushLog("No exams starting in {$reminderMinutes} minutes");
        exit(0);
    }

    pushLog("Found " . count($upcomingExams) . " exam(s) starting in {$reminderMinutes} minutes");

    $totalSent    = 0;
    $totalFailed  = 0;
    $totalExpired = 0;
    $totalSkipped = 0;

    // Helper function to check if notification was already sent
    $checkAndMarkSent = function ($notificationKey) use ($pdo) {
        try {
            // Try to insert - if duplicate key, it was already sent
            $stmt = $pdo->prepare("INSERT IGNORE INTO sent_push_notifications (notification_key) VALUES (?)");
            $stmt->execute([$notificationKey]);
            return $stmt->rowCount() > 0; // Returns true if inserted (not duplicate)
        } catch (Exception $e) {
            return false;
        }
    };

    // Send in batches to avoid connection pool exhaustion
    // Collect all subscriptions first, then send in chunks
    pushLog("Collecting notifications to send...");

    $allNotifications = [];

    foreach ($upcomingExams as $exam) {
        pushLog("Processing exam: {$exam['course_name']} at {$exam['exam_time']}");

        // =====================================================
        // Collect Student Notifications
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
            $subStmt = $pdo->prepare("
                SELECT * FROM push_subscriptions 
                WHERE user_type = 'student' 
                AND user_id = ? 
                AND is_active = 1
            ");
            $subStmt->execute([$student['student_id']]);
            $subscriptions = $subStmt->fetchAll();


            if (empty($subscriptions)) {
                pushLog("No active push subscription for student_id={$student['student_id']} ({$student['first_name']} {$student['last_name']})");
                continue;
            }

            // Create unique key for duplicate prevention: student + course + date + time
            $notificationKey = "student_{$student['student_id']}_{$exam['course_code']}_{$exam['exam_date']}_{$exam['exam_time']}";

            // Check if already sent
            if (!$checkAndMarkSent($notificationKey)) {
                $totalSkipped++;
                continue;
            }

            $payload = json_encode([
                'title' => '⏰ یادآوری آزمون - ' . toPersianDigits($exam['course_name']),
                'body' => toPersianDigits("آزمون شما ساعت {$exam['exam_time']} شروع می‌شود\nمکان: {$student['building']} - {$student['class_name']}\nشماره صندلی: {$student['seat_number']}"),
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
                pushLog("Queueing push for student_id={$student['student_id']} endpoint=" . substr($sub['endpoint'], 0, 40));
                $allNotifications[] = ['sub' => $sub, 'payload' => $payload];
            }
        }

        // =====================================================
        // Collect Proctor Notifications
        // =====================================================
        $stmt = $pdo->prepare("
            SELECT DISTINCT ea.proctor_id, ea.proctor_name, es.building, es.class_name
            FROM ExamAssignments ea
            JOIN courses c ON c.exam_date COLLATE utf8mb4_unicode_ci = ea.exam_date 
                          AND c.exam_time COLLATE utf8mb4_unicode_ci = ea.exam_time
            JOIN exam_seats es ON es.course_code COLLATE utf8mb4_unicode_ci = c.course_code
            WHERE ea.exam_date = ? AND ea.exam_time = ?
            AND ea.proctor_id IS NOT NULL AND ea.proctor_id > 0
        ");
        $stmt->execute([$exam['exam_date'], $exam['exam_time']]);
        $proctors = $stmt->fetchAll();

        foreach ($proctors as $proctor) {
            // Map internal proctor id to national_id stored in push_subscriptions.user_id
            $pNational = null;
            try {
                $pStmt = $pdo->prepare("SELECT national_id FROM Proctors WHERE id = ? LIMIT 1");
                $pStmt->execute([$proctor['proctor_id']]);
                $pRow = $pStmt->fetch();
                if ($pRow && !empty($pRow['national_id'])) {
                    $pNational = $pRow['national_id'];
                } else {
                    pushLog("No national_id found for proctor_id={$proctor['proctor_id']} ({$proctor['proctor_name']})");
                    continue;
                }
            } catch (Throwable $e) {
                pushLog("Error fetching proctor national_id for proctor_id={$proctor['proctor_id']}: " . $e->getMessage());
                continue;
            }
            $subStmt = $pdo->prepare("
                    SELECT * FROM push_subscriptions 
                    WHERE user_type = 'proctor' 
                    AND user_id = ? 
                    AND is_active = 1
                ");
            $subStmt->execute([$pNational]);
            $subscriptions = $subStmt->fetchAll();

            if (empty($subscriptions))
                continue;
            try {
                $pStmt = $pdo->prepare("SELECT national_id FROM Proctors WHERE id = ? LIMIT 1");
                $pStmt->execute([$proctor['proctor_id']]);
                $pRow = $pStmt->fetch();
                if ($pRow && !empty($pRow['national_id'])) {
                    $pNational = $pRow['national_id'];
                } else {
                    pushLog("No national_id found for proctor_id={$proctor['proctor_id']} ({$proctor['proctor_name']})");
                    continue;
                }
            } catch (Throwable $e) {
                pushLog("Error fetching proctor national_id for proctor_id={$proctor['proctor_id']}: " . $e->getMessage());
                continue;
            }

            $subStmt = $pdo->prepare("
                    SELECT * FROM push_subscriptions 
                    WHERE user_type = 'proctor' 
                    AND user_id = ? 
                    AND is_active = 1
                ");
            $subStmt->execute([$pNational]);
            $subscriptions = $subStmt->fetchAll();

            $notificationKey = "proctor_{$pNational}_{$exam['exam_date']}_{$exam['exam_time']}";

            // Check if already sent
            if (!$checkAndMarkSent($notificationKey)) {
                $totalSkipped++;
                continue;
            }

            $payload = json_encode([
                'title' => '⏰ یادآوری مراقبت - ' . toPersianDigits($exam['exam_time']),
                'body' => toPersianDigits("شیفت مراقبت شما ساعت {$exam['exam_time']} شروع می‌شود.\n\n  برای مکان استقرار خود با مسئول جلسه هماهنگ کنید."),
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
                $allNotifications[] = ['sub' => $sub, 'payload' => $payload];
            }
        }
    }

    // Send notifications in batches
    $batchSize          = 50;
    $totalNotifications = count($allNotifications);
    pushLog("Total notifications to send: {$totalNotifications} (skipped {$totalSkipped} duplicates)");

    $chunks = array_chunk($allNotifications, $batchSize);

    foreach ($chunks as $chunkIndex => $chunk) {
        // Queue this batch
        foreach ($chunk as $notification) {
            try {
                $sub          = $notification['sub'];
                $subscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'publicKey' => $sub['p256dh'],
                    'authToken' => $sub['auth'],
                ]);
                $webPush->queueNotification($subscription, $notification['payload']);
            } catch (Throwable $e) {
                pushLog("Error queuing notification: " . $e->getMessage());
            }
        }

        // Send this batch
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $totalSent++;
            } else {
                if ($report->isSubscriptionExpired()) {
                    $totalExpired++;
                    $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?")
                        ->execute([$endpoint]);
                    pushLog("Subscription expired and deactivated: " . substr($endpoint, 0, 50) . "...");
                } else {
                    $totalFailed++;
                    pushLog("Failed: " . $report->getReason());
                }
            }
        }

        // Small delay between batches
        if ($chunkIndex < count($chunks) - 1) {
            usleep(100000); // 100ms delay
        }

        pushLog("Batch " . ($chunkIndex + 1) . "/" . count($chunks) . " completed");
    }

    pushLog("Completed: Sent={$totalSent}, Failed={$totalFailed}, Expired={$totalExpired}, Skipped={$totalSkipped}");

    // Cleanup: Delete inactive subscriptions older than 7 days
    $cleanupStmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE is_active = 0 AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $cleanupStmt->execute();
    $deletedCount = $cleanupStmt->rowCount();
    if ($deletedCount > 0) {
        pushLog("Cleanup: Deleted {$deletedCount} old inactive subscriptions");
    }

    // Cleanup: Delete old sent notification records (older than 3 days)
    $cleanupSentStmt = $pdo->prepare("DELETE FROM sent_push_notifications WHERE sent_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
    $cleanupSentStmt->execute();
    $deletedSentCount = $cleanupSentStmt->rowCount();
    if ($deletedSentCount > 0) {
        pushLog("Cleanup: Deleted {$deletedSentCount} old sent notification records");
    }

} catch (Throwable $e) {
    pushLog("FATAL ERROR: " . $e->getMessage());
    pushLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

pushLog("Cron job finished successfully");
exit(0);
