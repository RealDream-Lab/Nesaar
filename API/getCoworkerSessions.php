<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/coworker_session.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/jdf.php';

const ENCRYPTION_KEY = 'PNU_EXAM_SEAT_2025_SECRET_KEY';

function decrypt_payload(string $encrypted)
{
    $decoded = base64_decode($encrypted, true);
    if ($decoded === false) {
        return null;
    }
    $json = json_decode($decoded, true);
    return is_array($json) ? $json : null;
}

function normalize_digits($value): string
{
    $map = [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9'
    ];
    return preg_replace('/[^0-9]/', '', strtr((string)$value, $map));
}

function ensure_proctors_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `Proctors` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `gender` VARCHAR(3) DEFAULT '',
        `first_name` VARCHAR(40) DEFAULT '',
        `last_name` VARCHAR(40) DEFAULT '',
        `national_id` CHAR(10) NOT NULL DEFAULT '',
        `phone` VARCHAR(11) DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_national_id` (`national_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function jalali_datetime(string $date, string $time, DateTimeZone $tz): ?DateTimeImmutable
{
    $parts = explode('/', $date);
    if (count($parts) !== 3) {
        return null;
    }
    $jy   = (int)$parts[0];
    $jm   = (int)$parts[1];
    $jd   = (int)$parts[2];
    $greg = jalali_to_gregorian($jy, $jm, $jd);
    if (!is_array($greg) || count($greg) !== 3) {
        return null;
    }
    $timeParts = explode(':', $time ?: '00:00');
    $hour      = (int)($timeParts[0] ?? 0);
    $minute    = (int)($timeParts[1] ?? 0);

    $formatted = sprintf('%04d-%02d-%02d %02d:%02d', $greg[0], $greg[1], $greg[2], $hour, $minute);
    $dt        = DateTimeImmutable::createFromFormat('Y-m-d H:i', $formatted, $tz);
    return $dt ?: null;
}

function jalali_weekday(string $date): string
{
    $parts = explode('/', $date);
    if (count($parts) !== 3) {
        return '';
    }
    $jy   = (int)$parts[0];
    $jm   = (int)$parts[1];
    $jd   = (int)$parts[2];
    $greg = jalali_to_gregorian($jy, $jm, $jd);
    if (!is_array($greg) || count($greg) !== 3) {
        return '';
    }
    $tz = new DateTimeZone('Asia/Tehran');
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $greg[0], $greg[1], $greg[2]), $tz);
    if (!$dt) {
        return '';
    }
    $map   = [
        0 => 'یکشنبه',
        1 => 'دوشنبه',
        2 => 'سه‌شنبه',
        3 => 'چهارشنبه',
        4 => 'پنج‌شنبه',
        5 => 'جمعه',
        6 => 'شنبه'
    ];
    $index = (int)$dt->format('w');
    return $map[$index] ?? '';
}

try {
    license_guard_enforce_api();

    rate_limit_enforce($pdo, 'coworker_login', 25, 300);
    ensure_proctors_table($pdo);

    $encrypted = $_POST['encrypted_data'] ?? $_GET['encrypted_data'] ?? '';
    if (!$encrypted) {
        echo json_encode(['success' => false, 'error' => 'missing_payload']);
        exit;
    }

    $payload = decrypt_payload($encrypted);
    if (!$payload || !isset($payload['national_id'], $payload['phone'])) {
        echo json_encode(['success' => false, 'error' => 'invalid_payload']);
        exit;
    }

    $nationalId = normalize_digits($payload['national_id']);
    $phone      = normalize_digits($payload['phone']);

    if (!preg_match('/^\d{10}$/', $nationalId)) {
        echo json_encode(['success' => false, 'error' => 'invalid_national_id']);
        exit;
    }
    if (!preg_match('/^\d{10,11}$/', $phone)) {
        echo json_encode(['success' => false, 'error' => 'invalid_phone']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, first_name, last_name, gender, national_id, phone FROM `Proctors` WHERE national_id = ? LIMIT 1');
    $stmt->execute([$nationalId]);
    $proctor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proctor) {
        echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'هیچ مراقبی با این مشخصات یافت نشد.']);
        exit;
    }

    $storedPhone = normalize_digits($proctor['phone'] ?? '');
    if ($storedPhone !== $phone) {
        echo json_encode(['success' => false, 'error' => 'phone_mismatch', 'message' => 'شماره تماس با پرونده ثبت‌شده مطابقت ندارد.']);
        exit;
    }

    coworker_session_set($pdo, [
        'proctor_id' => (int)$proctor['id'],
        'national_id' => $nationalId,
        'phone' => $phone,
        'first_name' => $proctor['first_name'] ?? '',
        'last_name' => $proctor['last_name'] ?? ''
    ]);

    $assignments = [];
    try {
        $assignStmt = $pdo->prepare('SELECT exam_date, exam_time FROM `ExamAssignments` WHERE proctor_id = ? ORDER BY exam_date ASC, exam_time ASC');
        $assignStmt->execute([(int)$proctor['id']]);
        $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $ignored) {
        $assignments = [];
    }

    $tz    = new DateTimeZone('Asia/Tehran');
    $now   = new DateTimeImmutable('now', $tz);
    $nowTs = $now->getTimestamp();

    $sessionDetailsStmt = null;
    $courseCountStmt    = null;
    try {
        $sessionDetailsStmt = $pdo->prepare('SELECT required_proctors, students_count FROM `ExamsDetil` WHERE exam_date = ? AND exam_time = ? LIMIT 1');
    } catch (Throwable $ignored) {
    }
    try {
        $courseCountStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM `courses` WHERE exam_date = ? AND exam_time = ?');
    } catch (Throwable $ignored) {
    }

    $sessionMetaCache = [];
    $fetchMeta        = function (string $date, string $time) use (&$sessionMetaCache, $sessionDetailsStmt, $courseCountStmt) {
        $key = $date . '|' . $time;
        if (isset($sessionMetaCache[$key])) {
            return $sessionMetaCache[$key];
        }
        $meta = [
            'required_proctors' => null,
            'students_count' => null,
            'course_count' => null,
        ];
        if ($sessionDetailsStmt) {
            try {
                $sessionDetailsStmt->execute([$date, $time]);
                $row = $sessionDetailsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($row) {
                    $meta['required_proctors'] = isset($row['required_proctors']) ? (int)$row['required_proctors'] : null;
                    $meta['students_count']    = isset($row['students_count']) ? (int)$row['students_count'] : null;
                }
            } catch (Throwable $ignored) {
            }
        }
        if ($courseCountStmt) {
            try {
                $courseCountStmt->execute([$date, $time]);
                $meta['course_count'] = (int)$courseCountStmt->fetchColumn();
            } catch (Throwable $ignored) {
            }
        }
        $sessionMetaCache[$key] = $meta;
        return $meta;
    };

    $sessions = [];
    foreach ($assignments as $item) {
        $examDate = trim((string)($item['exam_date'] ?? ''));
        $examTime = trim((string)($item['exam_time'] ?? ''));
        if ($examDate === '') {
            continue;
        }
        $dateTime     = jalali_datetime($examDate, $examTime ?: '00:00', $tz);
        $timestamp    = $dateTime ? $dateTime->getTimestamp() : null;
        $status       = ($timestamp !== null && $timestamp < $nowTs) ? 'past' : 'upcoming';
        $minutesUntil = null;
        if ($status === 'upcoming' && $timestamp !== null) {
            $minutesUntil = (int)floor(($timestamp - $nowTs) / 60);
        }
        $meta    = $fetchMeta($examDate, $examTime ?: '00:00');
        $weekday = jalali_weekday($examDate);

        $sessions[] = [
            'exam_date' => $examDate,
            'exam_time' => $examTime,
            'weekday' => $weekday,
            'status' => $status,
            'timestamp' => $dateTime ? $dateTime->format(DateTimeInterface::ATOM) : null,
            'minutes_until' => $minutesUntil,
            'required_proctors' => $meta['required_proctors'],
            'students_count' => $meta['students_count'],
            'course_count' => $meta['course_count'],
        ];
    }

    usort($sessions, function ($a, $b) {
        $aTs = $a['timestamp'] ? strtotime($a['timestamp']) : PHP_INT_MAX;
        $bTs = $b['timestamp'] ? strtotime($b['timestamp']) : PHP_INT_MAX;
        if ($aTs === false)
            $aTs = PHP_INT_MAX;
        if ($bTs === false)
            $bTs = PHP_INT_MAX;
        if ($aTs === $bTs) {
            return strcmp($a['exam_time'], $b['exam_time']);
        }
        return $aTs <=> $bTs;
    });

    $upcoming = array_values(array_filter($sessions, fn($s) => ($s['status'] ?? '') !== 'past'));
    $past     = array_values(array_filter($sessions, fn($s) => ($s['status'] ?? '') === 'past'));

    $stats = [
        'total_sessions' => count($sessions),
        'upcoming_sessions' => count($upcoming),
        'completed_sessions' => count($past),
        'unique_days' => count(array_unique(array_map(fn($s) => $s['exam_date'], $sessions))),
        'snapshot_generated_at' => $now->format(DateTimeInterface::ATOM),
        'next_session' => $upcoming[0] ?? null,
        'most_recent' => $past ? end($past) : null,
    ];
    if ($past) {
        $stats['most_recent'] = $past[count($past) - 1];
    }

    echo json_encode([
        'success' => true,
        'coworker' => [
            'id' => (int)$proctor['id'],
            'first_name' => $proctor['first_name'] ?? '',
            'last_name' => $proctor['last_name'] ?? '',
            'gender' => $proctor['gender'] ?? '',
            'phone' => $phone,
            'national_id' => $nationalId,
        ],
        'sessions' => $sessions,
        'stats' => $stats,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
