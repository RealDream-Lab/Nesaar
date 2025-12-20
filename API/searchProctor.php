<?php
/**
 * Search for a proctor by first name and last name
 * Returns proctor details and their exam schedule
 */
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/jdf.php';

try {
    license_guard_enforce_api();

    $query     = isset($_GET['q']) ? trim($_GET['q']) : '';
    $proctorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // If ID is provided, fetch that specific proctor
    if ($proctorId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, gender, first_name, last_name, national_id, phone, created_at 
            FROM Proctors 
            WHERE id = ?
        ");
        $stmt->execute([$proctorId]);
        $proctor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$proctor) {
            echo json_encode([
                'success' => false,
                'error' => 'مراقب یافت نشد'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Get proctor's exam assignments and return full details
        $assignStmt = $pdo->prepare("
            SELECT exam_date, exam_time, proctor_name
            FROM ExamAssignments 
            WHERE proctor_id = ?
            ORDER BY exam_date ASC, exam_time ASC
        ");
        $assignStmt->execute([$proctor['id']]);
        $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate status for each session
        $timezone     = new DateTimeZone('Asia/Tehran');
        $now          = new DateTimeImmutable('now', $timezone);
        $nowTimestamp = $now->getTimestamp();

        $sessions      = [];
        $pastCount     = 0;
        $upcomingCount = 0;

        foreach ($assignments as $row) {
            $examDate = trim((string)($row['exam_date'] ?? ''));
            $examTime = trim((string)($row['exam_time'] ?? ''));

            if ($examDate === '')
                continue;
            if ($examTime === '')
                $examTime = '00:00';

            $status           = 'unknown';
            $sessionTimestamp = null;

            $dateParts = explode('/', $examDate);
            $timeParts = explode(':', $examTime);

            if (count($dateParts) === 3) {
                $jYear  = (int)$dateParts[0];
                $jMonth = (int)$dateParts[1];
                $jDay   = (int)$dateParts[2];
                $hour   = isset($timeParts[0]) ? (int)$timeParts[0] : 0;
                $minute = isset($timeParts[1]) ? (int)$timeParts[1] : 0;

                $gregorian = jalali_to_gregorian($jYear, $jMonth, $jDay);
                if (is_array($gregorian) && count($gregorian) === 3) {
                    [$gYear, $gMonth, $gDay] = $gregorian;
                    $sessionDateTime         = DateTimeImmutable::createFromFormat(
                        'Y-m-d H:i',
                        sprintf('%04d-%02d-%02d %02d:%02d', $gYear, $gMonth, $gDay, $hour, $minute),
                        $timezone
                    );
                    if ($sessionDateTime instanceof DateTimeImmutable) {
                        $sessionTimestamp = $sessionDateTime->getTimestamp();
                        if ($sessionTimestamp < $nowTimestamp) {
                            $status = 'past';
                            $pastCount++;
                        } else {
                            $status = 'upcoming';
                            $upcomingCount++;
                        }
                    }
                }
            }

            $sessions[] = [
                'exam_date' => $examDate,
                'exam_time' => $examTime,
                'status' => $status,
                'timestamp' => $sessionTimestamp
            ];
        }

        echo json_encode([
            'success' => true,
            'proctor' => [
                'id' => (int)$proctor['id'],
                'gender' => $proctor['gender'],
                'first_name' => $proctor['first_name'],
                'last_name' => $proctor['last_name'],
                'full_name' => trim($proctor['first_name'] . ' ' . $proctor['last_name']),
                'national_id' => $proctor['national_id'],
                'phone' => $proctor['phone']
            ],
            'sessions' => $sessions,
            'summary' => [
                'total' => count($sessions),
                'past' => $pastCount,
                'upcoming' => $upcomingCount
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Search by query
    if (empty($query) || mb_strlen($query) < 2) {
        echo json_encode([
            'success' => false,
            'error' => 'نام یا نام خانوادگی مراقب را وارد کنید'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Search in Proctors table by first_name or last_name using LIKE
    $searchTerm = '%' . $query . '%';
    $stmt       = $pdo->prepare("
        SELECT id, gender, first_name, last_name, national_id, phone, created_at 
        FROM Proctors 
        WHERE first_name LIKE ? OR last_name LIKE ?
        ORDER BY last_name, first_name
        LIMIT 10
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    $proctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($proctors)) {
        echo json_encode([
            'success' => false,
            'error' => 'مراقبی با این نام یافت نشد'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Return list for selection (always return list format for spotlight UI)
    $proctorList = array_map(function ($p) {
        return [
            'id' => (int)$p['id'],
            'full_name' => trim($p['first_name'] . ' ' . $p['last_name']),
            'national_id' => $p['national_id'],
            'phone' => $p['phone']
        ];
    }, $proctors);

    echo json_encode([
        'success' => true,
        'multiple' => true,
        'proctors' => $proctorList
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    error_log("searchProctor error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'خطا در جستجو'
    ], JSON_UNESCAPED_UNICODE);
}
