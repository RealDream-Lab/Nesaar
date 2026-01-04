<?php
/**
 * API endpoint for searching student seat information for active exam sessions
 * Used by proctors to quickly find a student's seat during an active exam
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/license_guard.php';
require_once 'db_init.php';
require_once 'jdf.php';

// Enforce license validity
license_guard_enforce_api();

// No session requirement - accessible by coworkers from public app

// Get student_id from request
$studentId = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';

// Validate: only digits, max 9 characters
if ($studentId === '' || !preg_match('/^\d{1,9}$/', $studentId)) {
    echo json_encode([
        'success' => false,
        'error' => 'شماره دانشجویی باید حداکثر ۹ رقم باشد'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Get current date and time in Persian format
$currentDate = jdate('Y/m/d', '', '', 'Asia/Tehran', 'en');
$currentTime = jdate('H:i', '', '', 'Asia/Tehran', 'en');

// Parse current time to minutes for comparison
$currentParts   = explode(':', $currentTime);
$currentMinutes = (int)$currentParts[0] * 60 + (int)$currentParts[1];

try {
    // Check MultiExamMode config
    $configStmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'MultiExamMode'");
    $configStmt->execute();
    $multiExamMode = strtoupper($configStmt->fetchColumn() ?: 'NO');

    // Fetch all exams for this student today
    $stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            c.course_code,
            c.course_name,
            c.exam_date,
            c.exam_time,
            es.seat_number,
            es.building,
            es.class_name
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE s.student_id = ? AND c.exam_date = ?
        ORDER BY c.exam_time ASC, c.course_code ASC
    ");
    $stmt->execute([$studentId, $currentDate]);
    $allExams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($allExams)) {
        echo json_encode([
            'success' => false,
            'error' => 'دانشجویی با این شماره در آزمون‌های امروز یافت نشد'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Filter exams that are currently active (30 minutes before to 30 minutes after exam time)
    $activeExams = [];
    foreach ($allExams as $exam) {
        $examTimeParts    = explode(':', $exam['exam_time']);
        $examStartMinutes = (int)$examTimeParts[0] * 60 + (int)$examTimeParts[1];
        $windowStart      = $examStartMinutes - 30; // 30 minutes before
        $windowEnd        = $examStartMinutes + 30; // 30 minutes after

        // Check if current time is within the active window (-30 to +30 minutes)
        if ($currentMinutes >= $windowStart && $currentMinutes <= $windowEnd) {
            $activeExams[] = $exam;
        }
    }

    if (empty($activeExams)) {
        // No active exam right now, show message with exam times
        $examTimes = array_unique(array_column($allExams, 'exam_time'));
        $timesStr  = implode('، ', array_map(function ($t) {
            $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
            return str_replace($english, $persian, $t);
        }, $examTimes));

        echo json_encode([
            'success' => false,
            'error' => 'شماره صندلی فقط از ۳۰ دقیقه قبل تا ۳۰ دقیقه بعد از شروع آزمون قابل مشاهده است. آزمون‌های امروز: ساعت ' . $timesStr
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Get student info from first exam
    $studentInfo = [
        'student_id' => $activeExams[0]['student_id'],
        'first_name' => $activeExams[0]['first_name'],
        'last_name' => $activeExams[0]['last_name'],
        'full_name' => $activeExams[0]['first_name'] . ' ' . $activeExams[0]['last_name']
    ];

    // If MultiExamMode is enabled, find the primary (lowest) seat number
    $primarySeat = null;
    if ($multiExamMode === 'YES' && count($activeExams) > 1) {
        $seatNumbers = [];
        foreach ($activeExams as $exam) {
            $raw = $exam['seat_number'];
            if (preg_match('/(\d+)/', $raw, $m)) {
                $seatNumbers[] = (int)$m[1];
            }
        }
        if (!empty($seatNumbers)) {
            $primarySeat = min($seatNumbers);
        }
    }

    // Build exam list (only show primary seat for multi-exam students)
    $examList = [];
    $seenSeat = false;
    foreach ($activeExams as $exam) {
        $seatToShow = $exam['seat_number'];

        // If multi-exam mode and this is a secondary exam, don't show individual seat
        if ($multiExamMode === 'YES' && $primarySeat !== null) {
            $seatToShow = (string)$primarySeat;
            // Only add once if same primary seat
            if ($seenSeat) {
                // Still add course info but with same seat
            }
            $seenSeat = true;
        }

        $examList[] = [
            'course_code' => $exam['course_code'],
            'course_name' => $exam['course_name'],
            'exam_time' => $exam['exam_time'],
            'seat_number' => $seatToShow,
            'building' => $exam['building'],
            'class_name' => $exam['class_name'],
            'location' => trim($exam['building'] . ' - ' . $exam['class_name'])
        ];
    }

    // Return first seat only for display
    $displaySeat = $primarySeat !== null ? $primarySeat : $activeExams[0]['seat_number'];

    echo json_encode([
        'success' => true,
        'student' => $studentInfo,
        'seat_number' => $displaySeat,
        'exams' => $examList,
        'is_multi_exam' => count($activeExams) > 1
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('searchStudentSeat error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'خطا در جستجو'
    ], JSON_UNESCAPED_UNICODE);
}
