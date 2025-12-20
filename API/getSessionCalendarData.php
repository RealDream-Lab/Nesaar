<?php
/**
 * Returns calendar data for exam sessions with detailed statistics
 * Used by the dashboard calendar view
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/jdf.php';

try {
    license_guard_enforce_api();

    // Get all unique sessions with aggregated data
    // Count seats (exam_seats rows) not distinct students, so type breakdowns add up correctly
    $sessionsQuery = $pdo->query("
        SELECT 
            c.exam_date,
            c.exam_time,
            COUNT(*) AS seat_count,
            COUNT(DISTINCT es.student_id) AS student_count,
            COUNT(DISTINCT c.course_code) AS course_count
        FROM courses c
        INNER JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date IS NOT NULL AND c.exam_time IS NOT NULL
        GROUP BY c.exam_date, c.exam_time
        ORDER BY c.exam_date ASC, c.exam_time ASC
    ");
    $sessions      = $sessionsQuery->fetchAll(PDO::FETCH_ASSOC);

    // Get exam type counts (کتبی/الکترونیکی)
    // Use COUNT(*) instead of COUNT(DISTINCT student_id) so totals match
    $examTypeQuery = $pdo->query("
        SELECT 
            c.exam_date,
            c.exam_time,
            COALESCE(es.exam_type, 'کتبی') AS exam_type,
            COUNT(*) AS seat_count,
            COUNT(DISTINCT c.course_code) AS course_count
        FROM courses c
        INNER JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date IS NOT NULL AND c.exam_time IS NOT NULL
        GROUP BY c.exam_date, c.exam_time, es.exam_type
    ");
    $examTypes     = $examTypeQuery->fetchAll(PDO::FETCH_ASSOC);

    // Build exam type lookup
    $examTypeLookup = [];
    foreach ($examTypes as $row) {
        $key = $row['exam_date'] . '|' . $row['exam_time'];
        if (!isset($examTypeLookup[$key])) {
            $examTypeLookup[$key] = [
                'کتبی' => ['students' => 0, 'courses' => 0],
                'الکترونیکی' => ['students' => 0, 'courses' => 0]
            ];
        }
        $type = $row['exam_type'];
        if (mb_stripos($type, 'الکت') !== false) {
            $examTypeLookup[$key]['الکترونیکی']['students'] += (int)$row['seat_count'];
            $examTypeLookup[$key]['الکترونیکی']['courses']  += (int)$row['course_count'];
        } else {
            $examTypeLookup[$key]['کتبی']['students'] += (int)$row['seat_count'];
            $examTypeLookup[$key]['کتبی']['courses']  += (int)$row['course_count'];
        }
    }

    // Get course type counts (تستی/تشریحی/تستی-تشریحی)
    // Use COUNT(*) instead of COUNT(DISTINCT student_id) so totals match
    $courseTypeQuery = $pdo->query("
        SELECT 
            c.exam_date,
            c.exam_time,
            COALESCE(c.course_type, 'نامشخص') AS course_type,
            COUNT(*) AS seat_count,
            COUNT(DISTINCT c.course_code) AS course_count
        FROM courses c
        INNER JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date IS NOT NULL AND c.exam_time IS NOT NULL
        GROUP BY c.exam_date, c.exam_time, c.course_type
    ");
    $courseTypes     = $courseTypeQuery->fetchAll(PDO::FETCH_ASSOC);

    // Build course type lookup
    $courseTypeLookup = [];
    foreach ($courseTypes as $row) {
        $key = $row['exam_date'] . '|' . $row['exam_time'];
        if (!isset($courseTypeLookup[$key])) {
            $courseTypeLookup[$key] = [];
        }
        $type = $row['course_type'] ?: 'نامشخص';
        if (!isset($courseTypeLookup[$key][$type])) {
            $courseTypeLookup[$key][$type] = ['students' => 0, 'courses' => 0];
        }
        $courseTypeLookup[$key][$type]['students'] += (int)$row['seat_count'];
        $courseTypeLookup[$key][$type]['courses']  += (int)$row['course_count'];
    }

    // Get location counts per session
    $locationsQuery = $pdo->query("
        SELECT 
            c.exam_date,
            c.exam_time,
            COUNT(DISTINCT CONCAT(es.building, '|', es.class_name)) AS location_count
        FROM courses c
        INNER JOIN exam_seats es ON c.course_code = es.course_code
        WHERE c.exam_date IS NOT NULL AND c.exam_time IS NOT NULL
        GROUP BY c.exam_date, c.exam_time
    ");
    $locations      = $locationsQuery->fetchAll(PDO::FETCH_ASSOC);

    // Build location lookup
    $locationLookup = [];
    foreach ($locations as $row) {
        $key                  = $row['exam_date'] . '|' . $row['exam_time'];
        $locationLookup[$key] = (int)$row['location_count'];
    }

    // Get proctor counts from ExamsDetil if available
    $proctorLookup = [];
    try {
        $stmt   = $pdo->query('SELECT DATABASE() AS db');
        $dbName = $stmt ? ($stmt->fetch()['db'] ?? '') : '';
        if ($dbName) {
            $check = $pdo->prepare('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $check->execute([$dbName, 'ExamsDetil']);
            $exists = (int)($check->fetch()['cnt'] ?? 0) > 0;

            if ($exists) {
                $proctorQuery = $pdo->query("SELECT exam_date, exam_time, COALESCE(required_proctors, 0) AS required_proctors FROM ExamsDetil");
                foreach ($proctorQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key                 = $row['exam_date'] . '|' . $row['exam_time'];
                    $proctorLookup[$key] = (int)$row['required_proctors'];
                }
            }
        }
    } catch (Exception $e) {
        // Ignore - proctors data not available
    }

    // Get students with multiple exams per session
    $multiExamStudentsLookup = [];
    try {
        $multiExamQuery = $pdo->query("
            SELECT 
                c.exam_date,
                c.exam_time,
                es.student_id,
                COUNT(DISTINCT c.course_code) AS exam_count
            FROM courses c
            INNER JOIN exam_seats es ON c.course_code = es.course_code
            WHERE c.exam_date IS NOT NULL AND c.exam_time IS NOT NULL
            GROUP BY c.exam_date, c.exam_time, es.student_id
            HAVING exam_count > 1
        ");
        foreach ($multiExamQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['exam_date'] . '|' . $row['exam_time'];
            if (!isset($multiExamStudentsLookup[$key])) {
                $multiExamStudentsLookup[$key] = 0;
            }
            $multiExamStudentsLookup[$key]++;
        }
    } catch (Exception $e) {
        // Ignore - multi exam data not available
    }

    // Build calendar data
    $calendarData = [];
    $allDates     = [];
    $allTimes     = [];

    foreach ($sessions as $session) {
        $date = $session['exam_date'];
        $time = $session['exam_time'];
        $key  = $date . '|' . $time;

        if (!in_array($date, $allDates)) {
            $allDates[] = $date;
        }
        if (!in_array($time, $allTimes)) {
            $allTimes[] = $time;
        }

        // Convert Jalali to timestamp for sorting
        $dateParts = explode('/', $date);
        $timeParts = explode(':', $time);
        $timestamp = null;
        if (count($dateParts) === 3 && count($timeParts) >= 2) {
            list($jY, $jM, $jD) = $dateParts;
            list($gY, $gM, $gD) = jalali_to_gregorian((int)$jY, (int)$jM, (int)$jD);
            $timestamp          = mktime((int)$timeParts[0], (int)$timeParts[1], 0, $gM, $gD, $gY);
        }

        // Get day of week (0=Saturday in Jalali calendar)
        $dayOfWeek = null;
        if (count($dateParts) === 3) {
            list($jY, $jM, $jD) = $dateParts;
            list($gY, $gM, $gD) = jalali_to_gregorian((int)$jY, (int)$jM, (int)$jD);
            $gregorianTimestamp = mktime(12, 0, 0, $gM, $gD, $gY);
            $phpDayOfWeek       = (int)date('w', $gregorianTimestamp); // 0=Sunday, 6=Saturday
            // Convert to Jalali week (0=Saturday, 1=Sunday, ... 6=Friday)
            $dayOfWeek = ($phpDayOfWeek + 1) % 7;
        }

        $calendarData[] = [
            'exam_date' => $date,
            'exam_time' => $time,
            'timestamp' => $timestamp,
            'day_of_week' => $dayOfWeek,
            'seat_count' => (int)$session['seat_count'],
            'student_count' => (int)$session['student_count'],
            'course_count' => (int)$session['course_count'],
            'location_count' => $locationLookup[$key] ?? 0,
            'proctor_count' => $proctorLookup[$key] ?? 0,
            'multi_exam_students' => $multiExamStudentsLookup[$key] ?? 0,
            'exam_types' => $examTypeLookup[$key] ?? [
                'کتبی' => ['students' => 0, 'courses' => 0],
                'الکترونیکی' => ['students' => 0, 'courses' => 0]
            ],
            'course_types' => $courseTypeLookup[$key] ?? []
        ];
    }

    // Sort times naturally
    usort($allTimes, function ($a, $b) {
        $pa = array_map('intval', explode(':', $a ?: '00:00'));
        $pb = array_map('intval', explode(':', $b ?: '00:00'));
        return ($pa[0] - $pb[0]) ?: (($pa[1] ?? 0) - ($pb[1] ?? 0));
    });

    // Get date range
    $minDate      = null;
    $maxDate      = null;
    $minTimestamp = null;
    $maxTimestamp = null;
    foreach ($calendarData as $item) {
        if ($item['timestamp'] !== null) {
            if ($minTimestamp === null || $item['timestamp'] < $minTimestamp) {
                $minTimestamp = $item['timestamp'];
                $minDate      = $item['exam_date'];
            }
            if ($maxTimestamp === null || $item['timestamp'] > $maxTimestamp) {
                $maxTimestamp = $item['timestamp'];
                $maxDate      = $item['exam_date'];
            }
        }
    }

    // Prepare color palette for session times
    $timeColors   = [];
    $colorPalette = [
        '#3b82f6', // blue
        '#10b981', // emerald
        '#f59e0b', // amber
        '#ef4444', // red
        '#8b5cf6', // violet
        '#06b6d4', // cyan
        '#ec4899', // pink
        '#84cc16', // lime
        '#f97316', // orange
        '#6366f1', // indigo
    ];
    foreach ($allTimes as $idx => $time) {
        $timeColors[$time] = $colorPalette[$idx % count($colorPalette)];
    }

    echo json_encode([
        'success' => true,
        'sessions' => $calendarData,
        'times' => $allTimes,
        'timeColors' => $timeColors,
        'dateRange' => [
            'start' => $minDate,
            'end' => $maxDate,
            'startTimestamp' => $minTimestamp,
            'endTimestamp' => $maxTimestamp
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("getSessionCalendarData error: " . $e->getMessage());
    echo json_encode(['error' => 'خطا در دریافت اطلاعات تقویم'], JSON_UNESCAPED_UNICODE);
}
