<?php
/**
 * getMultiExamStudentsReport.php
 *
 * API endpoint to fetch multi-exam students report for a specific exam session.
 * Returns structured data about students who are taking multiple exams in the same session.
 *
 * Query Parameters:
 *   - exam_date: Date of exam (YYYY/MM/DD)
 *   - exam_time: Time of exam (HH:MM)
 *
 * Response JSON:
 *   {
 *     "success": true|false,
 *     "error": "error message if any",
 *     "multi_exam_mode": true|false,
 *     "exam_date": "YYYY/MM/DD",
 *     "exam_time": "HH:MM",
 *     "total_students": number,
 *     "locations": [
 *       {
 *         "building": "ساختمان",
 *         "class_name": "کلاس",
 *         "students": [
 *           {
 *             "student_id": "string",
 *             "student_name": "نام",
 *             "primary_seat": number,
 *             "exams": [
 *               {
 *                 "course_code": "درس",
 *                 "course_name": "نام درس",
 *                 "seat_number": number,
 *                 "location": "مکان",
 *                 "is_primary": bool
 *               }
 *             ]
 *           }
 *         ]
 *       }
 *     ]
 *   }
 */

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/privileged_session.php';
require_once __DIR__ . '/db_init.php';

header('Content-Type: application/json; charset=utf-8');

// Validate license
$licenseStatus = license_guard_validate();
if ($licenseStatus['valid'] !== true) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => $licenseStatus['message'] ?? 'License validation failed'
    ]);
    exit;
}

// Session check
try {
    $session = privileged_session_require($pdo);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized'
    ]);
    exit;
}

// Get parameters
$examDate = $_GET['exam_date'] ?? '';
$examTime = $_GET['exam_time'] ?? '';

if (empty($examDate) || empty($examTime)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required parameters: exam_date and exam_time'
    ]);
    exit;
}

try {
    // Fetch config
    $config = [];
    $stmt   = $pdo->query("SELECT ConfigName, ConfigValue FROM Config");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }

    // Check if MultiExamMode is enabled
    $multiExamModeEnabled = isset($config['MultiExamMode']) && strtoupper($config['MultiExamMode']) === 'YES';

    if (!$multiExamModeEnabled) {
        echo json_encode([
            'success' => true,
            'multi_exam_mode' => false,
            'exam_date' => $examDate,
            'exam_time' => $examTime,
            'total_students' => 0,
            'locations' => []
        ]);
        exit;
    }

    // Fetch courses for this session
    $stmt = $pdo->prepare("
        SELECT 
            c.course_code, 
            c.course_name, 
            c.exam_date, 
            c.exam_time
        FROM courses c
        WHERE c.exam_date = ? AND c.exam_time = ?
        ORDER BY c.course_code
    ");
    $stmt->execute([$examDate, $examTime]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($courses)) {
        echo json_encode([
            'success' => true,
            'multi_exam_mode' => true,
            'exam_date' => $examDate,
            'exam_time' => $examTime,
            'total_students' => 0,
            'locations' => []
        ]);
        exit;
    }

    // Fetch all students for these courses
    $courseCodes  = array_column($courses, 'course_code');
    $placeholders = str_repeat('?,', count($courseCodes) - 1) . '?';

    $stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            es.seat_number,
            es.building,
            es.class_name,
            c.course_code,
            c.course_name
        FROM exam_seats es
        JOIN students s ON es.student_id = s.student_id
        JOIN courses c ON es.course_code = c.course_code
        WHERE es.course_code IN ($placeholders)
        ORDER BY s.student_id, c.course_code
    ");
    $stmt->execute($courseCodes);
    $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build course map
    $courseMap = [];
    foreach ($courses as $c) {
        $courseMap[$c['course_code']] = $c['course_name'];
    }

    // Find multi-exam students
    $studentExamCount = [];
    foreach ($allStudents as $s) {
        $sid = $s['student_id'];
        if (!isset($studentExamCount[$sid])) {
            $studentExamCount[$sid] = [];
        }
        $studentExamCount[$sid][] = $s;
    }

    $multiExamStudents = [];
    foreach ($studentExamCount as $sid => $exams) {
        if (count($exams) > 1) {
            $multiExamStudents[$sid] = $exams;
        }
    }

    // Group by location
    $locations = [];
    foreach ($multiExamStudents as $sid => $exams) {
        // Find primary seat (minimum)
        $seatNumbers = [];
        $primaryExam = null;
        foreach ($exams as $exam) {
            $raw = $exam['seat_number'];
            if (preg_match('/(\d+)/', $raw, $m)) {
                $seatNum                           = (int)$m[1];
                $seatNumbers[$exam['course_code']] = $seatNum;
                if ($primaryExam === null || $seatNum < $seatNumbers[$primaryExam['course_code']]) {
                    $primaryExam = $exam;
                }
            }
        }

        if ($primaryExam) {
            $b   = trim($primaryExam['building'] ?? '') ?: 'بدون ساختمان';
            $c   = trim($primaryExam['class_name'] ?? '') ?: 'بدون کلاس';
            $key = $b . '||' . $c;

            if (!isset($locations[$key])) {
                $locations[$key] = [
                    'building' => $b,
                    'class_name' => $c,
                    'students' => []
                ];
            }

            $primarySeat     = min($seatNumbers);
            $primaryLocation = $b . ' | ' . $c;

            // Build exam details
            $examDetails = [];
            foreach ($exams as $exam) {
                $examB        = trim($exam['building'] ?? '') ?: 'بدون ساختمان';
                $examC        = trim($exam['class_name'] ?? '') ?: 'بدون کلاس';
                $examLocation = $examB . ' | ' . $examC;
                $examSeat     = $seatNumbers[$exam['course_code']] ?? 0;
                $isPrimary    = ($examSeat == $primarySeat);

                $examDetails[] = [
                    'course_code' => $exam['course_code'],
                    'course_name' => $courseMap[$exam['course_code']] ?? $exam['course_code'],
                    'seat_number' => $examSeat,
                    'location' => $examLocation,
                    'is_primary' => $isPrimary
                ];
            }

            // Sort exam details by seat number
            usort($examDetails, function ($a, $b) {
                return $a['seat_number'] - $b['seat_number'];
            });

            $studentName = ($exams[0]['first_name'] ?? '') . ' ' . ($exams[0]['last_name'] ?? '');

            $locations[$key]['students'][] = [
                'student_id' => $sid,
                'student_name' => trim($studentName),
                'primary_seat' => $primarySeat,
                'exams' => $examDetails
            ];
        }
    }

    // Sort locations
    uksort($locations, function ($a, $b) {
        return strcmp($a, $b);
    });

    // Sort students within each location by primary seat
    foreach ($locations as &$loc) {
        usort($loc['students'], function ($a, $b) {
            return $a['primary_seat'] - $b['primary_seat'];
        });
    }

    // Convert to array format for JSON
    $locationsArray = array_values($locations);

    echo json_encode([
        'success' => true,
        'multi_exam_mode' => true,
        'exam_date' => $examDate,
        'exam_time' => $examTime,
        'total_students' => count($multiExamStudents),
        'locations' => $locationsArray
    ]);

} catch (Exception $e) {
    error_log('Error in getMultiExamStudentsReport: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ]);
}
