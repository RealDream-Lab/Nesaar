<?php
/**
 * Check if report data exists before generating PDF
 * Returns JSON with availability status for different report types
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';

try {
    license_guard_enforce_api();

    $examDate   = $_GET['exam_date'] ?? '';
    $examTime   = $_GET['exam_time'] ?? '';
    $reportType = $_GET['report_type'] ?? '';

    if (empty($examDate) || empty($examTime) || empty($reportType)) {
        echo json_encode(['success' => false, 'error' => 'missing_params'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = ['success' => true, 'available' => false, 'count' => 0, 'message' => ''];

    switch ($reportType) {
        case 'test_labels':
            // Check for test courses with written exam students in this session
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT es.student_id) as student_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? AND c.exam_time = ? 
                  AND c.course_type LIKE '%تستی%'
                  AND es.exam_type = 'کتبی'
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['student_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد دانشجویان تستی کتبی: $count"
                : 'هیچ درس تستی با آزمون کتبی برای این جلسه یافت نشد.';
            break;

        case 'daily_test_labels':
            // Check for test courses with written exam students for entire day
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT es.student_id) as student_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? 
                  AND c.course_type LIKE '%تستی%'
                  AND es.exam_type = 'کتبی'
            ");
            $stmt->execute([$examDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['student_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد دانشجویان تستی کتبی روزانه: $count"
                : 'هیچ درس تستی با آزمون کتبی برای این روز یافت نشد.';
            break;

        case 'seat':
            // Check for students with seat assignments
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT es.student_id) as student_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? AND c.exam_time = ?
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['student_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد دانشجویان: $count"
                : 'هیچ دانشجویی برای این جلسه یافت نشد.';
            break;

        case 'session':
            // Check for courses in this session
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as course_count
                FROM courses
                WHERE exam_date = ? AND exam_time = ?
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['course_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد دروس: $count"
                : 'هیچ درسی برای این جلسه یافت نشد.';
            break;

        case 'secretary':
            // Check for students assigned to locations
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT es.student_id) as student_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? AND c.exam_time = ?
                  AND es.building IS NOT NULL AND es.building != ''
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['student_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد دانشجویان با مکان: $count"
                : 'هیچ دانشجویی با مکان تخصیص یافته برای این جلسه یافت نشد.';
            break;

        case 'reproduction':
            // Check for courses with students
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT c.course_code) as course_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? AND c.exam_time = ?
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['course_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد دروس با دانشجو: $count"
                : 'هیچ درسی با دانشجو برای این جلسه یافت نشد.';
            break;

        case 'location':
        case 'location_labels':
            // Check for locations with students
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT CONCAT(es.building, '-', es.class_name)) as location_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? AND c.exam_time = ?
                  AND es.building IS NOT NULL AND es.building != ''
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['location_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد مکان‌ها: $count"
                : 'هیچ مکانی برای این جلسه یافت نشد.';
            break;

        case 'attendance':
        case 'attendance_sheet':
            // Check for students with seat assignments
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT es.student_id) as student_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? AND c.exam_time = ?
                  AND es.building IS NOT NULL AND es.building != ''
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['student_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد دانشجویان: $count"
                : 'هیچ دانشجویی برای فهرست حضور و غیاب یافت نشد.';
            break;

        case 'descriptive':
            // Check for descriptive (تشریحی) courses with students
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as seat_count
                FROM courses c
                JOIN exam_seats es ON c.course_code = es.course_code
                WHERE c.exam_date = ? AND c.exam_time = ?
                  AND c.course_type LIKE '%تشریحی%'
                  AND es.exam_type = 'کتبی'
            ");
            $stmt->execute([$examDate, $examTime]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['seat_count'] ?? 0);
            $result['count'] = $count;
            $result['available'] = $count > 0;
            $result['message'] = $count > 0
                ? "تعداد پاسخنامه‌های تشریحی: $count"
                : 'هیچ درس تشریحی با آزمون کتبی برای این جلسه یافت نشد.';
            break;

        default:
            // For unknown report types, assume available
            $result['available'] = true;
            $result['message'] = 'نوع گزارش ناشناخته';
            break;
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
