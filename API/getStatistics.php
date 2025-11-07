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
    $busiestSession = null;
    $quietestSession = null;
    $busiestSessions = [];
    $quietestSessions = [];
    
    foreach ($allExams as $exam) {
        // Parse Jalali date (format: YYYY/MM/DD)
        $dateParts = explode('/', $exam['exam_date']);
        $timeParts = explode(':', $exam['exam_time']);

        $count = (int) ($exam['student_count'] ?? 0);
        $sessionInfo = [
            'exam_date' => $exam['exam_date'],
            'exam_time' => $exam['exam_time'],
            'student_count' => $count
        ];
        if ($busiestSession === null || $count > $busiestSession['student_count']) {
            $busiestSession = $sessionInfo;
            $busiestSessions = [$sessionInfo];
        } elseif ($count === $busiestSession['student_count']) {
            $busiestSessions[] = $sessionInfo;
        }
        if ($quietestSession === null || $count < $quietestSession['student_count']) {
            $quietestSession = $sessionInfo;
            $quietestSessions = [$sessionInfo];
        } elseif ($count === $quietestSession['student_count']) {
            $quietestSessions[] = $sessionInfo;
        }
        
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

    // Prepare exam_type counts per date/time
    $typeStmt = $pdo->query("SELECT c.exam_date, c.exam_time, COALESCE(es.exam_type, '') as exam_type, COUNT(*) as cnt
        FROM courses c
        INNER JOIN exam_seats es ON c.course_code = es.course_code
        GROUP BY c.exam_date, c.exam_time, es.exam_type");

    $typeCounts = [];
    foreach ($typeStmt->fetchAll() as $row) {
        $d = $row['exam_date'];
        $t = $row['exam_time'];
        $ty = $row['exam_type'] ?: 'unknown';
        if (!isset($typeCounts[$d])) $typeCounts[$d] = [];
        if (!isset($typeCounts[$d][$t])) $typeCounts[$d][$t] = [];
        $typeCounts[$d][$t][$ty] = (int)$row['cnt'];
    }

    // Build futureExams array with per-type breakdown
    $futureExamsOutput = [];
    foreach ($futureExams as $exam) {
        $d = $exam['exam_date'];
        $t = $exam['exam_time'];
        $etypeCounts = [
            'الکترونیکی' => 0,
            'کتبی' => 0
        ];
        if (isset($typeCounts[$d][$t])) {
            foreach ($typeCounts[$d][$t] as $k => $v) {
                if ($k === 'الکترونیکی' || mb_stripos($k, 'الکت') !== false) $etypeCounts['الکترونیکی'] += (int)$v;
                elseif ($k === 'کتبی' || mb_stripos($k, 'کتب') !== false) $etypeCounts['کتبی'] += (int)$v;
                else {
                    // fallback: if unknown, count towards 'کتبی' (conservative)
                    $etypeCounts['کتبی'] += (int)$v;
                }
            }
        }

        $futureExamsOutput[] = [
            'exam_date' => $d,
            'exam_time' => $t,
            'student_count' => $exam['student_count'],
            'timestamp' => $exam['timestamp'],
            'exam_type_counts' => $etypeCounts
        ];
    }

    // Build allExams array (past + future) with per-type breakdown and timestamp
    $allExamsOutput = [];
    foreach ($allExams as $exam) {
        $d = $exam['exam_date'];
        $t = $exam['exam_time'];
        $dateParts = explode('/', $d);
        $timeParts = explode(':', $t);
        $timestamp = null;
        if (count($dateParts) === 3 && count($timeParts) === 2) {
            list($jY, $jM, $jD) = $dateParts;
            list($gY, $gM, $gD) = jalali_to_gregorian($jY, $jM, $jD);
            $timestamp = mktime((int)$timeParts[0], (int)$timeParts[1], 0, $gM, $gD, $gY);
        }

        $etypeCounts = [ 'الکترونیکی' => 0, 'کتبی' => 0 ];
        if (isset($typeCounts[$d][$t])) {
            foreach ($typeCounts[$d][$t] as $k => $v) {
                if ($k === 'الکترونیکی' || mb_stripos($k, 'الکت') !== false) $etypeCounts['الکترونیکی'] += (int)$v;
                elseif ($k === 'کتبی' || mb_stripos($k, 'کتب') !== false) $etypeCounts['کتبی'] += (int)$v;
                else $etypeCounts['کتبی'] += (int)$v;
            }
        }

        $allExamsOutput[] = [
            'exam_date' => $d,
            'exam_time' => $t,
            'student_count' => (int)($exam['student_count'] ?? 0),
            'timestamp' => $timestamp,
            'exam_type_counts' => $etypeCounts
        ];
    }

    // Count remaining future sessions
    $remainingSessions = count($futureExamsOutput);
    // Aggregate exam-type totals across all future exams
    $futureExamTypeTotals = ['الکترونیکی' => 0, 'کتبی' => 0];
    foreach ($futureExamsOutput as $fe) {
        if (!empty($fe['exam_type_counts'])) {
            foreach ($fe['exam_type_counts'] as $k => $v) {
                if ($k === 'الکترونیکی' || mb_stripos($k, 'الکت') !== false) $futureExamTypeTotals['الکترونیکی'] += (int)$v;
                else $futureExamTypeTotals['کتبی'] += (int)$v;
            }
        }
    }

    // Aggregate course-type totals across all future exams
    $futureCourseTypeTotals = [];
    if (!empty($futureExamsOutput)) {
        // Build WHERE clause pairs for (exam_date, exam_time)
        $pairs = [];
        $params = [];
        foreach ($futureExamsOutput as $fe) {
            $pairs[] = "(c.exam_date = ? AND c.exam_time = ?)";
            $params[] = $fe['exam_date'];
            $params[] = $fe['exam_time'];
        }
        $where = implode(' OR ', $pairs);
        $sql = "SELECT c.course_type, COUNT(*) as cnt FROM courses c INNER JOIN exam_seats es ON c.course_code = es.course_code WHERE ($where) GROUP BY c.course_type";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $ctype = $row['course_type'] ?: 'نامشخص';
            $futureCourseTypeTotals[$ctype] = (int)$row['cnt'];
        }
    }

    // Course variety per session (number of distinct courses scheduled in a session)
    $courseVarietyStmt = $pdo->query("SELECT 
            c.exam_date,
            c.exam_time,
            COUNT(DISTINCT c.course_code) AS course_count,
            COUNT(es.student_id) AS student_count
        FROM courses c
        INNER JOIN exam_seats es ON c.course_code = es.course_code
        GROUP BY c.exam_date, c.exam_time");
    $courseVarietyRows = $courseVarietyStmt->fetchAll();

    $maxCourseFrequency = null;
    $maxCourseSessions = [];
    foreach ($courseVarietyRows as $row) {
        $row['course_count'] = (int) $row['course_count'];
        $row['student_count'] = (int) $row['student_count'];
        if ($maxCourseFrequency === null || $row['course_count'] > $maxCourseFrequency['course_count']) {
            $maxCourseFrequency = $row;
            $maxCourseSessions = [$row];
        } elseif ($maxCourseFrequency !== null && $row['course_count'] === $maxCourseFrequency['course_count']) {
            $maxCourseSessions[] = $row;
        }
    }

    // Exam-type extremes per session (کتبی/الکترونیکی)
    $maxWritten = null;
    $maxWrittenSessions = [];
    $maxElectronic = null;
    $maxElectronicSessions = [];

    foreach ($typeCounts as $d => $times) {
        foreach ($times as $t => $types) {
            $written = 0;
            $electronic = 0;
            foreach ($types as $label => $value) {
                if ($label === 'الکترونیکی' || mb_stripos($label, 'الکت') !== false) {
                    $electronic += (int) $value;
                } else {
                    $written += (int) $value;
                }
            }

            if ($written > 0) {
                $entry = ['exam_date' => $d, 'exam_time' => $t, 'student_count' => $written];
                if ($maxWritten === null || $written > $maxWritten['student_count']) {
                    $maxWritten = $entry;
                    $maxWrittenSessions = [$entry];
                } elseif ($written === $maxWritten['student_count']) {
                    $maxWrittenSessions[] = $entry;
                }
            }

            if ($electronic > 0) {
                $entry = ['exam_date' => $d, 'exam_time' => $t, 'student_count' => $electronic];
                if ($maxElectronic === null || $electronic > $maxElectronic['student_count']) {
                    $maxElectronic = $entry;
                    $maxElectronicSessions = [$entry];
                } elseif ($electronic === $maxElectronic['student_count']) {
                    $maxElectronicSessions[] = $entry;
                }
            }
        }
    }

    if ($busiestSession !== null) {
        $busiestSession['tie_count'] = count($busiestSessions);
        $busiestSession['matches'] = array_values($busiestSessions);
    }
    if ($quietestSession !== null) {
        $quietestSession['tie_count'] = count($quietestSessions);
        $quietestSession['matches'] = array_values($quietestSessions);
    }
    if ($maxCourseFrequency !== null) {
        $maxCourseFrequency['tie_count'] = count($maxCourseSessions);
        $maxCourseFrequency['matches'] = array_values($maxCourseSessions);
    }
    if ($maxWritten !== null) {
        $maxWritten['tie_count'] = count($maxWrittenSessions);
        $maxWritten['matches'] = array_values($maxWrittenSessions);
    }
    if ($maxElectronic !== null) {
        $maxElectronic['tie_count'] = count($maxElectronicSessions);
        $maxElectronic['matches'] = array_values($maxElectronicSessions);
    }

    echo json_encode([
        'totalStudents' => $totalStudents,
        'totalCourses' => $totalCourses,
        'nextExamStudents' => $nextExamStudents,
        'nextExamDateTime' => $nextExamDateTime,
        'remainingSessions' => $remainingSessions,
        'futureExams' => $futureExamsOutput,
        'allExams' => $allExamsOutput,
        'futureExamTypeTotals' => $futureExamTypeTotals,
        'futureCourseTypeTotals' => $futureCourseTypeTotals,
        'quickInsights' => [
            'busiestSession' => $busiestSession,
            'quietestSession' => $quietestSession,
            'maxCourseFrequency' => $maxCourseFrequency,
            'maxWritten' => $maxWritten,
            'maxElectronic' => $maxElectronic
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Statistics error: ' . $e->getMessage());
    echo json_encode(['error' => 'خطا در دریافت آمار'], JSON_UNESCAPED_UNICODE);
}
?>