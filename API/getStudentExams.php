<?php
require_once 'jdf.php';
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/user_session.php';
$current_persian_date = jdate('Y/m/d', '', '', 'Asia/Tehran', 'en');
$current_time         = jdate('H:i', '', '', 'Asia/Tehran', 'en');
header('Content-Type: application/json; charset=utf-8');

// Enforce license validity before proceeding
license_guard_enforce_api();

// Load .env file when present so Docker/local configs stay in sync
(function () {
    $root    = realpath(__DIR__ . '/../');
    $envFile = $root ? $root . '/.env' : null;
    if (!$envFile || !is_file($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
            continue;
        }
        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$key, $value] = $parts;
        $key           = trim($key);
        if ($key === '') {
            continue;
        }
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
})();

// Encryption settings
const ENCRYPTION_KEY = 'PNU_EXAM_SEAT_2025_SECRET_KEY'; // Should match frontend

function decryptData($encryptedData, $key)
{
    try {
        // Simple Base64 decoding for compatibility with frontend
        $decoded = base64_decode($encryptedData);
        if ($decoded === false) {
            return null;
        }

        return json_decode($decoded, true);
    } catch (Exception $e) {
        error_log('Decryption failed: ' . $e->getMessage());
        return null;
    }
}

// Database initialization and connection
require_once 'db_init.php';

// License guard enforced at the top of this file to prevent bypass attempts

// دریافت و رمزگشایی ورودی
$encrypted_data = $_POST['encrypted_data'] ?? $_GET['encrypted_data'] ?? '';

if (empty($encrypted_data)) {
    echo json_encode(['error' => 'داده‌های رمزگذاری شده یافت نشد']);
    exit;
}

$credentials = decryptData($encrypted_data, ENCRYPTION_KEY);

if (!$credentials || !isset($credentials['student_id'], $credentials['national_id'])) {
    echo json_encode(['error' => 'خطا در رمزگشایی اطلاعات']);
    exit;
}

$student_id  = $credentials['student_id'];
$national_id = $credentials['national_id'];

// کوئری امن
$seatHasExamType   = false;
$courseHasExamType = false;
try {
    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_seats' AND COLUMN_NAME = 'exam_type'");
    $colStmt->execute();
    $seatHasExamType = (bool)$colStmt->fetchColumn();
    $colStmt2        = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'exam_type'");
    $colStmt2->execute();
    $courseHasExamType = (bool)$colStmt2->fetchColumn();
} catch (Exception $e) {
    // ignore and proceed with defaults
}

// Build select for exam_type depending on schema
$examTypeSelect = "'' AS exam_type";
if ($seatHasExamType) {
    $examTypeSelect = "es.exam_type AS exam_type";
} elseif ($courseHasExamType) {
    $examTypeSelect = "c.exam_type AS exam_type";
}

$sql  = "SELECT 
    s.student_id AS student_id,
    s.national_id AS national_id,
    s.first_name AS first_name,
    s.last_name AS last_name,
    s.degree AS degree,
    c.course_code AS course_code,
    c.course_name AS course_name,
    c.exam_date AS exam_date,
    c.exam_time AS exam_time,
    " . $examTypeSelect . ",
    c.course_type AS course_type,
    es.seat_number AS seat_number,
    es.building AS building,
    es.class_name AS class_name,
    es.seat_row AS seat_row
FROM 
    exam_seats es
JOIN 
    students s ON es.student_id = s.student_id
JOIN 
    courses c ON es.course_code = c.course_code
WHERE 
    s.student_id = :student_id
    AND s.national_id = :national_id
";
$stmt = $pdo->prepare($sql);
$stmt->execute(['student_id' => $student_id, 'national_id' => $national_id]);
$results = $stmt->fetchAll();

// بررسی وجود نتیجه
if (empty($results)) {
    echo json_encode(['error' => 'اطلاعاتی برای این شماره دانشجویی پیدا نشد']);
    exit;
}

// Check MultiExamMode config and apply primary seat for multi-exam sessions
try {
    $configStmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'MultiExamMode'");
    $configStmt->execute();
    $multiExamMode = strtoupper($configStmt->fetchColumn() ?: 'NO');

    if ($multiExamMode === 'YES') {
        // Group exams by session (date + time)
        $sessionExams = [];
        foreach ($results as $idx => $row) {
            $sessionKey = $row['exam_date'] . '|' . $row['exam_time'];
            if (!isset($sessionExams[$sessionKey])) {
                $sessionExams[$sessionKey] = [];
            }
            $sessionExams[$sessionKey][] = ['idx' => $idx, 'seat' => $row['seat_number']];
        }

        // For sessions with multiple exams, find primary seat and apply to all
        foreach ($sessionExams as $sessionKey => $exams) {
            if (count($exams) > 1) {
                // Parse seat numbers and find minimum
                $seatNumbers = [];
                foreach ($exams as $exam) {
                    $raw = $exam['seat'];
                    if (preg_match('/(\d+)/', $raw, $m)) {
                        $seatNumbers[$exam['idx']] = (int)$m[1];
                    }
                }
                if (!empty($seatNumbers)) {
                    $primarySeat = min($seatNumbers);
                    // Apply primary seat to all exams in this session
                    foreach ($exams as $exam) {
                        $results[$exam['idx']]['seat_number'] = (string)$primarySeat;
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    // If config check fails, proceed without modification
    error_log('MultiExamMode check failed: ' . $e->getMessage());
}

try {
    user_session_set($pdo, [
        'student_id' => $student_id,
        'national_id' => $national_id,
        'first_name' => $results[0]['first_name'] ?? '',
        'last_name' => $results[0]['last_name'] ?? '',
    ]);
} catch (Throwable $e) {
    error_log('Failed to set user session cookie: ' . $e->getMessage());
}

// Get reminder minutes from config (default 30)
$reminderMinutes = 30;
try {
    $reminderStmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'PushReminderMinutes'");
    $reminderStmt->execute();
    $reminderVal = $reminderStmt->fetchColumn();
    if ($reminderVal !== false && (int)$reminderVal >= 30 && (int)$reminderVal <= 180) {
        $reminderMinutes = (int)$reminderVal;
    }
} catch (Exception $e) {
    // Use default if config fetch fails
}

// بررسی زمان امتحان برای مخفی کردن اطلاعات
foreach ($results as &$row) {
    if (!empty($row['exam_date']) && !empty($row['exam_time'])) {
        // مقایسه ساده تاریخ امتحان با تاریخ جاری
        if ($row['exam_date'] == $current_persian_date) {
            // اگر امتحان امروز است، بررسی ساعت
            $exam_time_parts    = explode(':', $row['exam_time']);
            $current_time_parts = explode(':', $current_time);

            if (count($exam_time_parts) >= 2 && count($current_time_parts) >= 2) {
                $exam_hour      = (int)$exam_time_parts[0];
                $exam_minute    = (int)$exam_time_parts[1];
                $current_hour   = (int)$current_time_parts[0];
                $current_minute = (int)$current_time_parts[1];

                $exam_total_minutes    = ($exam_hour * 60) + $exam_minute;
                $current_total_minutes = ($current_hour * 60) + $current_minute;
                $minutes_difference    = $exam_total_minutes - $current_total_minutes;

                // اگر بیشتر از X دقیقه تا امتحان باقی مانده (بر اساس تنظیمات)
                if ($minutes_difference > $reminderMinutes) {
                    $exam_datetime = DateTime::createFromFormat('H:i', $row['exam_time']);
                    $exam_datetime->modify("-{$reminderMinutes} minutes");
                    $visible_time       = $exam_datetime->format('H:i');
                    $row['seat_number'] = 'شماره صندلی شما تا ساعت ' . $visible_time . ' همان روز مخفی می‌باشد.';
                    $row['building']    = '';
                    $row['class_name']  = '';
                    $row['seat_row']    = '';
                }
            }
        } elseif ($row['exam_date'] > $current_persian_date) {
            // اگر امتحان در آینده است (تاریخ بعدی)
            $exam_datetime = DateTime::createFromFormat('H:i', $row['exam_time']);
            $exam_datetime->modify("-{$reminderMinutes} minutes");
            $visible_time       = $exam_datetime->format('H:i');
            $row['seat_number'] = 'شماره صندلی شما تا ساعت ' . $visible_time . ' همان روز مخفی می‌باشد.';
            $row['building']    = '';
            $row['class_name']  = '';
            $row['seat_row']    = '';
        }
    }
    // اضافه کردن روز هفته
    $row['exam_day'] = getPersianDayOfWeek($row['exam_date']);
}

// تابع برای گرفتن روز هفته پارسی
function getPersianDayOfWeek($jalali_date)
{
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    $timestamp          = mktime(0, 0, 0, $gm, $gd, $gy);
    $day                = date('l', $timestamp);
    $days               = [
        'Saturday' => 'شنبه',
        'Sunday' => 'یکشنبه',
        'Monday' => 'دوشنبه',
        'Tuesday' => 'سه‌شنبه',
        'Wednesday' => 'چهارشنبه',
        'Thursday' => 'پنج‌شنبه',
        'Friday' => 'جمعه'
    ];
    return $days[$day] ?? '';
}

// خروجی JSON
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>