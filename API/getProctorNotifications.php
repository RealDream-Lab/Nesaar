<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/jdf.php';

function normalize_digits($value)
{
    $value = (string) $value;
    $map = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'
    ];
    if (!preg_match('/[۰-۹٠-٩]/u', $value)) {
        return $value;
    }
    return strtr($value, $map);
}

function infer_weekday($jalaliDate, $jalaliTime = '')
{
    $asciiDate = normalize_digits($jalaliDate);
    $parts = preg_split('/[\/\\-]/u', $asciiDate);
    if (count($parts) < 3) {
        return '';
    }

    $jy = (int) ($parts[0] ?? 0);
    $jm = (int) ($parts[1] ?? 0);
    $jd = (int) ($parts[2] ?? 0);
    if ($jy === 0 || $jm === 0 || $jd === 0) {
        return '';
    }

    $asciiTime = normalize_digits($jalaliTime);
    $timeParts = explode(':', $asciiTime);
    $hour = isset($timeParts[0]) ? max(0, min(23, (int) $timeParts[0])) : 0;
    $minute = isset($timeParts[1]) ? max(0, min(59, (int) $timeParts[1])) : 0;

    try {
        $greg = jalali_to_gregorian($jy, $jm, $jd);
        if (!is_array($greg) || count($greg) < 3) {
            return '';
        }

        $tz = new DateTimeZone('Asia/Tehran');
        $dt = new DateTimeImmutable('now', $tz);
        $dt = $dt->setDate((int) $greg[0], (int) $greg[1], (int) $greg[2])->setTime($hour, $minute, 0, 0);
        $weekdayIndex = (int) $dt->format('w');
        $weekdayMap = [
            0 => 'یکشنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنجشنبه',
            5 => 'جمعه',
            6 => 'شنبه'
        ];
        return $weekdayMap[$weekdayIndex] ?? '';
    } catch (Throwable $e) {
        return '';
    }
}

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

    admin_session_require($pdo);

    $tableExists = false;
    try {
        $checkStmt = $pdo->query("SHOW TABLES LIKE 'ExamAssignments'");
        $tableExists = ($checkStmt && $checkStmt->rowCount() > 0);
    } catch (Throwable $e) {
        $tableExists = false;
    }

    if (!$tableExists) {
        echo json_encode([
            'success' => true,
            'proctors' => [],
            'dates' => [],
            'times' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $genderMap = [];
    try {
        $genderStmt = $pdo->query('SELECT id, gender FROM `Proctors`');
        if ($genderStmt) {
            while ($row = $genderStmt->fetch(PDO::FETCH_ASSOC)) {
                $pid = (int) ($row['id'] ?? 0);
                if ($pid > 0) {
                    $genderMap[$pid] = trim((string) ($row['gender'] ?? ''));
                }
            }
        }
    } catch (Throwable $ignored) {
        // جدول Proctors ممکن است هنوز ایجاد نشده باشد
    }

    $assignStmt = $pdo->query('SELECT proctor_id, proctor_name, exam_date, exam_time FROM `ExamAssignments` WHERE TRIM(IFNULL(proctor_name, "")) != ""');
    $rows = $assignStmt ? $assignStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $proctors = [];
    $dates = [];
    $times = [];

    foreach ($rows as $row) {
        $pid = isset($row['proctor_id']) ? (int) $row['proctor_id'] : 0;
        $name = trim((string) ($row['proctor_name'] ?? ''));
        $examDate = trim((string) ($row['exam_date'] ?? ''));
        $examTime = trim((string) ($row['exam_time'] ?? ''));

        if ($name === '' || $examDate === '' || $examTime === '') {
            continue;
        }

        $key = $pid > 0 ? 'id:' . $pid : 'name:' . md5(mb_strtolower($name, 'UTF-8'));
        if (!isset($proctors[$key])) {
            $gender = ($pid > 0 && isset($genderMap[$pid])) ? $genderMap[$pid] : '';
            $proctors[$key] = [
                'proctor_id' => $pid,
                'proctor_name' => $name,
                'gender' => $gender,
                'sessions' => [],
                '_session_map' => []
            ];
        }

        $weekday = infer_weekday($examDate, $examTime);
        $sessionKey = $examDate . '|' . $examTime;
        if (!isset($proctors[$key]['_session_map'][$sessionKey])) {
            $proctors[$key]['sessions'][] = [
                'exam_date' => $examDate,
                'exam_time' => $examTime
            ];
            $proctors[$key]['_session_map'][$sessionKey] = true;
        }

        $dates[$examDate] = true;
        $times[$examTime] = true;
    }

    if (!$proctors) {
        echo json_encode([
            'success' => true,
            'proctors' => [],
            'dates' => [],
            'times' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $normalized = [];
    foreach ($proctors as $item) {
        unset($item['_session_map']);
        usort($item['sessions'], function ($a, $b) {
            $dateCmp = strcmp($a['exam_date'], $b['exam_date']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            return strcmp($a['exam_time'], $b['exam_time']);
        });
        $normalized[] = $item;
    }

    usort($normalized, function ($a, $b) {
        return strcmp($a['proctor_name'], $b['proctor_name']);
    });

    ksort($dates, SORT_STRING);
    ksort($times, SORT_STRING);

    echo json_encode([
        'success' => true,
        'proctors' => $normalized,
        'dates' => array_keys($dates),
        'times' => array_keys($times)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
