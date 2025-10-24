<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/license_guard.php';
require_once 'db_init.php';
require_once 'jdf.php';

license_guard_enforce_api();

try {
    // Get total students
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM students");
    $totalStudents = $stmt->fetch()['count'] ?? 0;

    // Get total courses
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM courses");
    $totalCourses = $stmt->fetch()['count'] ?? 0;

    // Get current timestamp
    $currentTimestamp = time();
    
    // Get all unique exam date/time combinations that have students
    $stmt = $pdo->query("
        SELECT 
            c.exam_date,
            c.exam_time,
            COUNT(*) as student_count
        FROM courses c
        INNER JOIN exam_seats es ON c.course_code = es.course_code
        GROUP BY c.exam_date, c.exam_time
        HAVING student_count > 0
        ORDER BY c.exam_date ASC, c.exam_time ASC
    ");
    
    $allExams = $stmt->fetchAll();
    $futureExams = [];
    
    foreach ($allExams as $exam) {
        // Parse Jalali date (format: YYYY/MM/DD)
        $dateParts = explode('/', $exam['exam_date']);
        $timeParts = explode(':', $exam['exam_time']);
        
        if (count($dateParts) === 3 && count($timeParts) === 2) {
            // Convert Jalali to Gregorian timestamp
            list($jY, $jM, $jD) = $dateParts;
            list($gY, $gM, $gD) = jalali_to_gregorian($jY, $jM, $jD);
            
            $examTimestamp = mktime(
                (int)$timeParts[0],  // hour
                (int)$timeParts[1],  // minute
                0,                    // second
                $gM, $gD, $gY
            );
            
            // Only include future exams
            if ($examTimestamp > $currentTimestamp) {
                $futureExams[] = [
                    'exam_date' => $exam['exam_date'],
                    'exam_time' => $exam['exam_time'],
                    'student_count' => $exam['student_count'],
                    'timestamp' => $examTimestamp
                ];
            }
        }
    }
    
    // Sort by timestamp and get the nearest one
    if (!empty($futureExams)) {
        usort($futureExams, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        
        $nextExam = $futureExams[0];
        $nextExamStudents = $nextExam['student_count'] ?? 0;
        
        // Format: time | date (from left: time space pipe space date)
        $nextExamDateTime = $nextExam['exam_time'] . ' | ' . $nextExam['exam_date'];
    } else {
        $nextExamStudents = 0;
        $nextExamDateTime = 'آزمونی یافت نشد';
    }

    echo json_encode([
        'totalStudents' => $totalStudents,
        'totalCourses' => $totalCourses,
        'nextExamStudents' => $nextExamStudents,
        'nextExamDateTime' => $nextExamDateTime
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Statistics error: ' . $e->getMessage());
    echo json_encode(['error' => 'خطا در دریافت آمار'], JSON_UNESCAPED_UNICODE);
}
?>