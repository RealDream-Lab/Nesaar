<?php
/**
 * getPushStats.php
 * 
 * API endpoint for push notification statistics.
 * Returns counts of sent push notifications by type (manual, scheduled, auto).
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/../includes/admin_session.php';

// Only GET allowed
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Check if tables exist
    $tablesExist = true;

    // Initialize stats
    $stats = [
        'manual' => ['sent' => 0, 'success' => 0, 'failed' => 0],
        'scheduled' => ['sent' => 0, 'success' => 0, 'failed' => 0],
        'auto' => ['sent' => 0, 'success' => 0, 'failed' => 0],
        'total' => ['sent' => 0, 'success' => 0, 'failed' => 0],
        'subscribers' => ['student' => 0, 'proctor' => 0, 'total' => 0]
    ];

    // Get subscriber counts
    try {
        $subStmt = $pdo->query("
            SELECT user_type, COUNT(*) as count 
            FROM push_subscriptions 
            WHERE is_active = 1 
            GROUP BY user_type
        ");
        foreach ($subStmt->fetchAll() as $row) {
            if ($row['user_type'] === 'student') {
                $stats['subscribers']['student'] = (int)$row['count'];
            } elseif ($row['user_type'] === 'proctor') {
                $stats['subscribers']['proctor'] = (int)$row['count'];
            }
        }
        $stats['subscribers']['total'] = $stats['subscribers']['student'] + $stats['subscribers']['proctor'];
    } catch (PDOException $e) {
        // Table might not exist
    }

    // Get push_manual_log stats (manual notifications from send.php)
    try {
        $manualStmt = $pdo->query("
            SELECT 
                COUNT(DISTINCT COALESCE(batch_id, id)) as broadcast_count,
                COALESCE(SUM(sent_count), 0) as delivered_count,
                COALESCE(SUM(failed_count), 0) + COALESCE(SUM(expired_count), 0) as fail_count
            FROM push_manual_log
        ");

        $manualRow = $manualStmt->fetch();
        if ($manualRow) {
            // 'sent' = number of broadcasts (unique batch_ids = how many times admin clicked send)
            // 'success' = total messages delivered to devices
            // 'failed' = failed deliveries
            $stats['manual']['sent']    = (int)$manualRow['broadcast_count'];
            $stats['manual']['success'] = (int)$manualRow['delivered_count'];
            $stats['manual']['failed']  = (int)$manualRow['fail_count'];
        }
    } catch (PDOException $e) {
        // Table might not exist
    }

    // Get scheduled_push_notifications stats
    try {
        $schedStmt = $pdo->query("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(sent_count), 0) as success_count,
                COALESCE(SUM(failed_count), 0) as fail_count
            FROM scheduled_push_notifications
            WHERE status = 'sent'
        ");

        $schedRow = $schedStmt->fetch();
        if ($schedRow) {
            $stats['scheduled']['sent']    = (int)$schedRow['count'];
            $stats['scheduled']['success'] = (int)$schedRow['success_count'];
            $stats['scheduled']['failed']  = (int)$schedRow['fail_count'];
        }
    } catch (PDOException $e) {
        // Table might not exist
    }

    // Get push_scheduled stats (exam reminders)
    try {
        $autoSchedStmt = $pdo->query("
            SELECT 
                status,
                COUNT(*) as count
            FROM push_scheduled
            GROUP BY status
        ");

        foreach ($autoSchedStmt->fetchAll() as $row) {
            $status = $row['status'];
            $count  = (int)$row['count'];

            $stats['auto']['sent'] += $count;
            if ($status === 'sent') {
                $stats['auto']['success'] += $count;
            } elseif ($status === 'failed' || $status === 'cancelled') {
                $stats['auto']['failed'] += $count;
            }
        }
    } catch (PDOException $e) {
        // Table might not exist
    }

    // Calculate totals
    $stats['total']['sent']    = $stats['manual']['sent'] + $stats['scheduled']['sent'] + $stats['auto']['sent'];
    $stats['total']['success'] = $stats['manual']['success'] + $stats['scheduled']['success'] + $stats['auto']['success'];
    $stats['total']['failed']  = $stats['manual']['failed'] + $stats['scheduled']['failed'] + $stats['auto']['failed'];

    echo json_encode([
        'success' => true,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('getPushStats error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
