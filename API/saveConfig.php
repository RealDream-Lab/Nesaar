<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once 'db_init.php';

csrf_enforce();
license_guard_enforce_api();
$session = admin_session_require($pdo);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'داده‌های نامعتبر']);
    exit;
}

// Allow only specific config keys to be written via this endpoint
// Note: GroupByCourse is a YES/NO toggle used for report grouping preferences
// Note: GroupAttendanceByCourse is a YES/NO toggle for grouping attendance sheet by course code
$allowed = [
    'AdminNickName',
    'BossNickName',
    'HeadOfEDU',
    'Chairman',
    'GroupByCourse',
    'GroupAttendanceByCourse',
    'PaperSaving',
    'ObserversLastCard',
    // Add SendSMS toggle (YES/NO) and SmsApiKey to allow storing SMS provider key
    'SendSMS',
    'SmsApiKey',
    'rptDownload',
    // WavesAnimation toggle (YES/NO) to enable/disable background waves animation
    'WavesAnimation',
    // DailyTestLabels: 'YES' to generate daily A4 portrait scanned test envelope labels instead of per-session
    'DailyTestLabels',
    // ReproductionReportMode: 'course' (default) or 'location' for reproduction room report style
    'ReproductionReportMode',
    // MultiExamMode: 'YES' to unify seat numbers for multi-exam students
    'MultiExamMode',
    // QuickSessionView: 'YES' to skip modal and go directly to full report on calendar click
    'QuickSessionView',
    // PushReminderMinutes: minutes before exam to send push notification (30-180, default 30)
    'PushReminderMinutes',
    // SeatReportSortBy: 'seat_number' | 'student_id' | 'last_name' for seat report sorting
    'SeatReportSortBy',
    // SeatReportSeparateBuilding: YES/NO - separate pages per building in seat report
    'SeatReportSeparateBuilding',
    // SeatReportGroupByCourse: YES/NO - group by course before applying sort
    'SeatReportGroupByCourse'
];

$toSave = [];
foreach ($allowed as $k) {
    if (!array_key_exists($k, $input))
        continue; // skip keys not provided

    if ($k === 'GroupByCourse' || $k === 'PaperSaving' || $k === 'WavesAnimation' || $k === 'MultiExamMode' || $k === 'QuickSessionView' || $k === 'DailyTestLabels' || $k === 'GroupAttendanceByCourse' || $k === 'SeatReportSeparateBuilding' || $k === 'SeatReportGroupByCourse') {
        // Normalize any value to strict YES/NO (default NO for most, YES for WavesAnimation)
        $raw = is_string($input[$k]) ? $input[$k] : '';
        $val = strtoupper(trim($raw));
        if ($val !== 'YES' && $val !== 'NO') {
            $val = ($k === 'WavesAnimation') ? 'YES' : 'NO';
        }
        $toSave[$k] = $val; // always save toggle even if NO
        continue;
    }

    if ($k === 'SeatReportSortBy') {
        // Validate sort option
        $raw          = is_string($input[$k]) ? strtolower(trim($input[$k])) : '';
        $validOptions = ['seat_number', 'student_id', 'last_name'];
        if (!in_array($raw, $validOptions, true)) {
            $raw = 'last_name'; // default
        }
        $toSave[$k] = $raw;
        continue;
    }

    if ($k === 'SendSMS') {
        // Normalize to strict YES/NO
        $raw = is_string($input[$k]) ? $input[$k] : '';
        $val = strtoupper(trim($raw));
        if ($val !== 'YES' && $val !== 'NO') {
            $val = 'NO';
        }
        $toSave[$k] = $val;
        continue;
    }

    if ($k === 'ObserversLastCard') {
        $raw = is_string($input[$k]) ? trim($input[$k]) : '';
        if ($raw === '') {
            continue;
        }
        $allowedCards = [
            'sessionStatsCard',
            'locationsCard',
            'examsDetailCard',
            'proctorsCard',
            'assignmentCard'
        ];
        if (!in_array($raw, $allowedCards, true)) {
            continue;
        }
        $toSave[$k] = $raw;
        continue;
    }

    if ($k === 'ReproductionReportMode') {
        // Normalize to 'course' (default) or 'location'
        $raw        = is_string($input[$k]) ? strtolower(trim($input[$k])) : '';
        $val        = ($raw === 'location') ? 'location' : 'course';
        $toSave[$k] = $val;
        continue;
    }

    if ($k === 'PushReminderMinutes') {
        // Validate: must be 30-180 in steps of 30
        $raw         = is_numeric($input[$k]) ? (int)$input[$k] : 30;
        $validValues = [30, 60, 90, 120, 150, 180];
        if (!in_array($raw, $validValues, true)) {
            $raw = 30; // default
        }
        $toSave[$k] = (string)$raw;
        continue;
    }

    // Other text fields: ignore empty strings
    $val = trim((string)$input[$k]);
    if ($val !== '') {
        $toSave[$k] = $val;
    }
}

if (empty($toSave)) {
    echo json_encode(['success' => false, 'error' => 'مقادیر مناسبی برای ذخیره ارسال نشده است']);
    exit;
}

try {
    foreach ($toSave as $name => $value) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if ($row && intval($row['cnt']) > 0) {
            $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = ?");
            $stmt->execute([$value, $name]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES (?, ?)");
            $stmt->execute([$name, $value]);
        }
    }
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('saveConfig error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'خطا در ذخیره تنظیمات'], JSON_UNESCAPED_UNICODE);
}

?>