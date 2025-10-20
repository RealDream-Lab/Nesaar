<?php
require_once 'jdf.php';
$current_persian_date = jdate('Y/m/d', '', '', 'Asia/Tehran', 'en');
$current_time = jdate('H:i', '', '', 'Asia/Tehran', 'en');
header('Content-Type: application/json; charset=utf-8');

// Load .env file when present so Docker/local configs stay in sync
(function () {
    $root = realpath(__DIR__ . '/../');
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
        $key = trim($key);
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

function decryptData($encryptedData, $key) {
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

// تابع بررسی اعتبار لایسنس
function checkLicense($pdo) {
    try {
        // دریافت LicenseToken از کانفیگ
        $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'LicenseToken'");
        $stmt->execute();
        $row = $stmt->fetch();
        $licenseToken = $row ? $row['ConfigValue'] : '';
        
        if (empty($licenseToken)) {
            return ['valid' => false, 'message' => 'توکن لایسنس یافت نشد'];
        }
        
        // فراخوانی وب‌هوک
        $webhookUrl = 'https://wfa.pnubijar.ac.ir/webhook/LC';
        $query = http_build_query(['LicenseToken' => $licenseToken]);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 4,
                'header' => "Accept: application/json\r\n"
            ]
        ]);
        
        $webhookResp = @file_get_contents($webhookUrl . '?' . $query, false, $ctx);
        
        if ($webhookResp === false || strlen(trim($webhookResp)) === 0) {
            return ['valid' => true, 'message' => 'عدم دسترسی به سرور لایسنس'];
        }
        
        $licenseData = json_decode($webhookResp, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($licenseData)) {
            return ['valid' => true, 'message' => 'پاسخ نامعتبر از سرور لایسنس'];
        }
        
        // بررسی نوع لایسنس
        $licenceType = $licenseData['LicenceType'] ?? '';
        $exp = $licenseData['Exp'] ?? '';
        
        if ($licenceType === 'Licenced') {
            // برنامه فعال است و نیازی به چک تاریخ انقضا نیست
            return ['valid' => true, 'message' => 'لایسنس فعال'];
        }
        
        if ($licenceType === 'FullLicenced') {
            // بررسی تاریخ انقضا
            if (empty($exp)) {
                return ['valid' => false, 'message' => 'تاریخ انقضا یافت نشد'];
            }
            
            // تبدیل تاریخ انقضا به timestamp
            $expTimestamp = strtotime($exp);
            $currentTimestamp = time();
            
            if ($expTimestamp > $currentTimestamp) {
                // هنوز منقضی نشده
                return ['valid' => true, 'message' => 'دوره آزمایشی فعال'];
            } else {
                // منقضی شده
                return ['valid' => false, 'message' => 'در صورتی که کاربر این سامانه هستید لطفاً به ادمین اطلاع دهید تا نسبت به فعال‌سازی لایسنس اقدام نماید.'];
            }
        }
        
        // اگر نوع لایسنس شناخته شده نباشد
        return ['valid' => false, 'message' => 'نوع لایسنس نامعتبر است'];
        
    } catch (Exception $e) {
        error_log('License check failed: ' . $e->getMessage());
        return ['valid' => true, 'message' => 'خطا در بررسی لایسنس'];
    }
}

// بررسی اعتبار لایسنس
$licenseCheck = checkLicense($pdo);
if (!$licenseCheck['valid']) {
    echo json_encode(['error' => 'license_expired', 'message' => $licenseCheck['message']]);
    exit;
}

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

$student_id = $credentials['student_id'];
$national_id = $credentials['national_id'];

// کوئری امن
$sql = "
SELECT 
    s.student_id AS student_id,
    s.national_id AS national_id,
    s.first_name AS first_name,
    s.last_name AS last_name,
    s.degree AS degree,
    c.course_code AS course_code,
    c.course_name AS course_name,
    c.exam_date AS exam_date,
    c.exam_time AS exam_time,
    c.exam_type AS exam_type,
    c.course_type AS course_type,
    e.seat_number AS seat_number,
    e.building AS building,
    e.class_name AS class_name,
    e.seat_row AS seat_row
FROM 
    exam_seats e
JOIN 
    students s ON e.student_id = s.student_id
JOIN 
    courses c ON e.course_code = c.course_code
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

// بررسی زمان امتحان برای مخفی کردن اطلاعات
foreach ($results as &$row) {
    if (!empty($row['exam_date']) && !empty($row['exam_time'])) {
        // مقایسه ساده تاریخ امتحان با تاریخ جاری
        if ($row['exam_date'] == $current_persian_date) {
            // اگر امتحان امروز است، بررسی ساعت
            $exam_time_parts = explode(':', $row['exam_time']);
            $current_time_parts = explode(':', $current_time);
            
            if (count($exam_time_parts) >= 2 && count($current_time_parts) >= 2) {
                $exam_hour = (int)$exam_time_parts[0];
                $exam_minute = (int)$exam_time_parts[1];
                $current_hour = (int)$current_time_parts[0];
                $current_minute = (int)$current_time_parts[1];
                
                $exam_total_minutes = ($exam_hour * 60) + $exam_minute;
                $current_total_minutes = ($current_hour * 60) + $current_minute;
                $minutes_difference = $exam_total_minutes - $current_total_minutes;
                
                // اگر بیشتر از 30 دقیقه تا امتحان باقی مانده
                if ($minutes_difference > 30) {
                    $exam_datetime = DateTime::createFromFormat('H:i', $row['exam_time']);
                    $exam_datetime->modify('-30 minutes');
                    $visible_time = $exam_datetime->format('H:i');
                    $row['seat_number'] = 'شماره صندلی شما تا ساعت ' . $visible_time . ' همان روز مخفی می‌باشد.';
                    $row['building'] = '';
                    $row['class_name'] = '';
                    $row['seat_row'] = '';
                }
            }
        } elseif ($row['exam_date'] > $current_persian_date) {
            // اگر امتحان در آینده است (تاریخ بعدی)
            $exam_datetime = DateTime::createFromFormat('H:i', $row['exam_time']);
            $exam_datetime->modify('-30 minutes');
            $visible_time = $exam_datetime->format('H:i');
            $row['seat_number'] = 'شماره صندلی شما تا ساعت ' . $visible_time . ' همان روز مخفی می‌باشد.';
            $row['building'] = '';
            $row['class_name'] = '';
            $row['seat_row'] = '';
        }
    }
    // اضافه کردن روز هفته
    $row['exam_day'] = getPersianDayOfWeek($row['exam_date']);
}

// تابع برای گرفتن روز هفته پارسی
function getPersianDayOfWeek($jalali_date) {
    list($jy, $jm, $jd) = explode('/', $jalali_date);
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    $timestamp = mktime(0, 0, 0, $gm, $gd, $gy);
    $day = date('l', $timestamp);
    $days = [
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