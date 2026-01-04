<?php
/**
 * Generate Excel file for Exam Booklet Report using OpenSpout
 * Includes: Course Name, Course Code, Student Count, Exam Type, Course Type, Time, Date
 */

// Prevent PHP from emitting HTML error pages
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../database/api_errors.log');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/privileged_session.php';
require_once __DIR__ . '/db_init.php';

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;

// Validate license
$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'License Error'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Session check
$session = privileged_session_require($pdo);

// Get filter parameter
$filter = $_GET['filter'] ?? 'all'; // 'all', 'electronic', 'written'

// Helper for Persian Digits
function toPersianDigits($str)
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, (string)$str);
}

try {
    // Check if exam_type column exists in courses or exam_seats
    $hasExamTypeInCourses = false;
    $hasExamTypeInSeats   = false;
    try {
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'exam_type'");
        $colStmt->execute();
        $hasExamTypeInCourses = (bool)$colStmt->fetchColumn();

        $colStmt2 = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_seats' AND COLUMN_NAME = 'exam_type'");
        $colStmt2->execute();
        $hasExamTypeInSeats = (bool)$colStmt2->fetchColumn();
    } catch (Exception $e) {
        // Columns don't exist
    }

    // Build exam_type select expression
    $examTypeExpr = "'کتبی'";
    if ($hasExamTypeInCourses) {
        $examTypeExpr = "c.exam_type";
    } elseif ($hasExamTypeInSeats) {
        $examTypeExpr = "MAX(es.exam_type)";
    }

    // Build WHERE/HAVING clause based on filter
    $where       = "1=1";
    $having      = "";
    $filterParam = [];
    $havingParam = [];

    if ($filter === 'electronic') {
        if ($hasExamTypeInCourses) {
            $where       = "c.exam_type = ?";
            $filterParam = ['الکترونیکی'];
        } elseif ($hasExamTypeInSeats) {
            // Use HAVING for aggregated column
            $having      = "HAVING MAX(es.exam_type) = ?";
            $havingParam = ['الکترونیکی'];
        }
    } elseif ($filter === 'written') {
        if ($hasExamTypeInCourses) {
            $where       = "c.exam_type = ?";
            $filterParam = ['کتبی'];
        } elseif ($hasExamTypeInSeats) {
            // Use HAVING for aggregated column
            $having      = "HAVING MAX(es.exam_type) = ?";
            $havingParam = ['کتبی'];
        }
    }

    // Fetch courses with student counts
    $sql = "
        SELECT 
            c.course_code,
            c.course_name,
            c.exam_date,
            c.exam_time,
            $examTypeExpr AS exam_type,
            c.course_type,
            COUNT(DISTINCT es.student_id) as student_count
        FROM courses c
        LEFT JOIN exam_seats es ON c.course_code = es.course_code
        WHERE $where
        GROUP BY c.course_code, c.course_name, c.exam_date, c.exam_time" . ($hasExamTypeInCourses ? ", c.exam_type" : "") . ", c.course_type
        $having
        ORDER BY c.exam_date ASC, c.exam_time ASC, c.course_code ASC
    ";

    $allParams = array_merge($filterParam, $havingParam);
    $stmt      = $pdo->prepare($sql);
    $stmt->execute($allParams);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'هیچ دوره‌ای یافت نشد'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Create Excel file (simple approach without styles - compatible with all OpenSpout versions)
    $writer = new Writer();

    $filename = 'ExamBooklet_' . date('Y-m-d_H-i-s') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer->openToFile('php://output');

    // Header row
    $headerRow = Row::fromValues([
        'ردیف',
        'تاریخ',
        'ساعت',
        'کد درس',
        'نام درس',
        'نوع آزمون',
        'نوع درس',
        'تعداد دانشجو'
    ]);
    $writer->addRow($headerRow);

    // Data rows
    foreach ($courses as $index => $course) {
        $row = Row::fromValues([
            toPersianDigits($index + 1),
            toPersianDigits($course['exam_date']),
            toPersianDigits($course['exam_time']),
            toPersianDigits($course['course_code']),
            $course['course_name'],
            $course['exam_type'] ?? 'کتبی',
            $course['course_type'] ?? 'عمومی',
            toPersianDigits($course['student_count'])
        ]);
        $writer->addRow($row);
    }

    $writer->close();
    exit;

} catch (Exception $e) {
    error_log('generateExamBookletExcel error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'خطا در تولید فایل: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
