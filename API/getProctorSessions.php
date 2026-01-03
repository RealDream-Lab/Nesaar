<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/privileged_session.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/jdf.php';

try {
    $licenseStatus = license_guard_validate();
    if (($licenseStatus['valid'] ?? false) !== true) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'license_invalid',
            'message' => $licenseStatus['message'] ?? 'دسترسی به دلیل مشکل لایسنس ممکن نیست.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sessionData = privileged_session_require($pdo);

    $proctorId = isset($_GET['proctor_id']) ? (int)$_GET['proctor_id'] : 0;
    if ($proctorId <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'invalid_proctor_id',
            'message' => 'شناسه مراقب معتبر نیست.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('SELECT exam_date, exam_time, proctor_name FROM `ExamAssignments` WHERE proctor_id = ? ORDER BY exam_date ASC, exam_time ASC');
    $stmt->execute([$proctorId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode([
            'success' => true,
            'proctor_id' => $proctorId,
            'proctor_name' => '',
            'sessions' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $timezone = new DateTimeZone('Asia/Tehran');
    $now      = new DateTimeImmutable('now', $timezone);

    $sessions = [];
    foreach ($rows as $row) {
        $examDate = trim((string)($row['exam_date'] ?? ''));
        $examTime = trim((string)($row['exam_time'] ?? ''));
        if ($examDate === '') {
            continue;
        }
        if ($examTime === '') {
            $examTime = '00:00';
        }

        $status           = 'unknown';
        $sessionTimestamp = null;

        $dateParts = explode('/', $examDate);
        $timeParts = explode(':', $examTime);
        if (count($dateParts) === 3) {
            $jYear     = (int)$dateParts[0];
            $jMonth    = (int)$dateParts[1];
            $jDay      = (int)$dateParts[2];
            $hour      = isset($timeParts[0]) ? (int)$timeParts[0] : 0;
            $minute    = isset($timeParts[1]) ? (int)$timeParts[1] : 0;
            $gregorian = jalali_to_gregorian($jYear, $jMonth, $jDay);
            if (is_array($gregorian) && count($gregorian) === 3) {
                [$gYear, $gMonth, $gDay] = $gregorian;
                $sessionDateTime         = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i',
                    sprintf('%04d-%02d-%02d %02d:%02d', $gYear, $gMonth, $gDay, $hour, $minute),
                    $timezone
                );
                if ($sessionDateTime instanceof DateTimeImmutable) {
                    $sessionTimestamp = $sessionDateTime->getTimestamp();
                    $status           = $sessionTimestamp < $now->getTimestamp() ? 'past' : 'upcoming';
                }
            }
        }

        $sessions[] = [
            'exam_date' => $examDate,
            'exam_time' => $examTime,
            'status' => $status,
            'timestamp' => $sessionTimestamp
        ];
    }

    $proctorName = trim((string)($rows[0]['proctor_name'] ?? ''));

    echo json_encode([
        'success' => true,
        'proctor_id' => $proctorId,
        'proctor_name' => $proctorName,
        'sessions' => $sessions
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
