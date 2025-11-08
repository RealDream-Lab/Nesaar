<?php
// Start session before any output or headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/db_init.php';

// Enforce license and CSRF protection (enable when ready)
try {
    license_guard_enforce_api();
    // csrf_enforce();
} catch (Throwable $e) {
    // license_guard_enforce_api() already responded with 403 on failure
    exit;
}

// Progress file utilities (re-use the same reader on client via getProcessProgress.php?filename=update)
$progressFile = __DIR__ . '/../database/progress_update.json';

function write_progress(string $stage, string $message, int $percent): void {
    global $progressFile;
    // Model it like other progress files: totalRows = 100, processedRows = percent
    @file_put_contents($progressFile, json_encode([
        'stage' => $stage,
        'message' => $message,
        'totalRows' => 100,
        'processedRows' => max(0, min(100, $percent))
    ], JSON_UNESCAPED_UNICODE));
}

// Helper: build UNION ALL from available temp tables
function build_union_from_temp(PDO $pdo): array {
    // Returns [sql, sources] where sources is array of table names included
    $sources = [];
    $parts = [];
    $dbName = '';
    try {
        $stmt = $pdo->query('SELECT DATABASE() AS db');
        $dbName = $stmt ? ($stmt->fetch()['db'] ?? '') : '';
    } catch (Throwable $e) {}

    $checkExists = function(string $table) use ($pdo, $dbName): bool {
        try {
            $q = $pdo->prepare('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $q->execute([$dbName, $table]);
            return ((int)($q->fetch()['cnt'] ?? 0)) > 0;
        } catch (Throwable $e) { return false; }
    };

    foreach (['e-exams', 'k-exams'] as $t) {
        if ($checkExists($t)) {
            $sources[] = $t;
            $safe = str_replace('`', '``', $t);
            $parts[] = "SELECT `شماره دانشجويي`,`شماره شناسنامه`,`مرکز مبدا`,`مرکز مقصد`,`نام`,`نام خانوادگي`,`مدرک`,`کد درس`,`نام درس`,`تاريخ آزمون`,`ساعت آزمون`,`شماره صندلي`,`نوع آزمون`,`نوع درس`,`ساختمان`,`کلاس`,`ردیف` FROM `{$safe}`";
        }
    }

    return [implode(" UNION ALL ", $parts), $sources];
}

// SQL helpers for digit normalization (Persian/Arabic to ASCII)
function norm_digits_sql(string $col): string {
    // Compose nested REPLACE chain
    $rep = [
        '۰' => '0','۱' => '1','۲' => '2','۳' => '3','۴' => '4','۵' => '5','۶' => '6','۷' => '7','۸' => '8','۹' => '9',
        '٠' => '0','١' => '1','٢' => '2','٣' => '3','٤' => '4','٥' => '5','٦' => '6','٧' => '7','٨' => '8','٩' => '9'
    ];
    $expr = $col;
    foreach ($rep as $from => $to) {
        // escape single quotes and backslashes in $from/$to if any (not expected here)
        $fromEsc = str_replace("'", "''", $from);
        $toEsc = str_replace("'", "''", $to);
        $expr = "REPLACE(" . $expr . ", '{$fromEsc}', '{$toEsc}')";
    }
    return "TRIM(" . $expr . ")";
}

try {
    // Stage 1: pre-check
    write_progress('precheck', 'در حال بررسی جداول موقت...', 3);

    // Build union
    [$unionSql, $sources] = build_union_from_temp($pdo);
    if (!$unionSql || empty($sources)) {
        write_progress('error', 'هیچ کدام از جداول موقت e-exams یا k-exams موجود نیستند', 0);
        http_response_code(400);
        echo json_encode(['error' => 'جداول موقت یافت نشد']);
        exit;
    }

    // Begin transaction for atomicity (use DELETE instead of TRUNCATE to avoid implicit commit)
    // Drop tables if they exist before creating anything (DDL may cause implicit commit)
    try { $pdo->exec('DROP TABLE IF EXISTS `ExamsDetil`'); } catch (Throwable $e) { /* ignore */ }
    try { $pdo->exec('DROP TABLE IF EXISTS `locations`'); } catch (Throwable $e) { /* ignore */ }

    // Ensure `locations` table exists before starting transaction (CREATE TABLE is DDL and may cause implicit commit)
    $createLocations = "CREATE TABLE IF NOT EXISTS `locations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `building` VARCHAR(255) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `class_name` VARCHAR(255) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `required_proctors` INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY `ux_locations_building_class` (`building`,`class_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($createLocations);

    $pdo->beginTransaction();

    // Stage 2: delete existing
    write_progress('cleanup', 'در حال پاکسازی داده‌های قبلی...', 10);
    $pdo->exec('DELETE FROM exam_seats');
    $pdo->exec('DELETE FROM courses');
    $pdo->exec('DELETE FROM students');

    // Common derived table
    $derived = "( {$unionSql} ) AS t";

    // Stage 3: insert courses
    write_progress('courses', 'در حال درج دروس...', 35);
    $course_code = norm_digits_sql('t.`کد درس`');
    $sqlCourses = "INSERT INTO courses (course_code, course_name, exam_date, exam_time, course_type)\n"
        . "SELECT DISTINCT\n"
        . "  {$course_code} AS course_code,\n"
        . "  TRIM(t.`نام درس`) AS course_name,\n"
        . "  TRIM(t.`تاريخ آزمون`) AS exam_date,\n"
        . "  TRIM(t.`ساعت آزمون`) AS exam_time,\n"
        . "  TRIM(t.`نوع درس`) AS course_type\n"
        . "FROM {$derived}\n"
        . "WHERE t.`کد درس` IS NOT NULL AND TRIM(t.`کد درس`) <> ''";
    $pdo->exec($sqlCourses);
    $insertedCourses = $pdo->query('SELECT COUNT(*) AS c FROM courses')->fetch()['c'] ?? 0;

    // Stage 4: insert students
    write_progress('students', 'در حال درج دانشجویان...', 60);
    $student_id = norm_digits_sql('t.`شماره دانشجويي`');
    $national_id = norm_digits_sql('t.`شماره شناسنامه`');
    $src_center  = norm_digits_sql('t.`مرکز مبدا`');
    $dst_center  = norm_digits_sql('t.`مرکز مقصد`');
    $sqlStudents = "INSERT INTO students (student_id, national_id, source_center, destination_center, first_name, last_name, degree)\n"
        . "SELECT DISTINCT\n"
        . "  {$student_id} AS student_id,\n"
        . "  {$national_id} AS national_id,\n"
        . "  {$src_center} AS source_center,\n"
        . "  {$dst_center} AS destination_center,\n"
        . "  TRIM(t.`نام`) AS first_name,\n"
        . "  TRIM(t.`نام خانوادگي`) AS last_name,\n"
        . "  TRIM(t.`مدرک`) AS degree\n"
        . "FROM {$derived}\n"
        . "WHERE t.`شماره دانشجويي` IS NOT NULL AND TRIM(t.`شماره دانشجويي`) <> ''";
    $pdo->exec($sqlStudents);
    $insertedStudents = $pdo->query('SELECT COUNT(*) AS c FROM students')->fetch()['c'] ?? 0;

    // Stage 5: insert exam seats
    write_progress('seats', 'در حال درج صندلی‌ها...', 85);
    $sid   = norm_digits_sql('u.`شماره دانشجويي`');
    $ccode = norm_digits_sql('u.`کد درس`');
    $snorm = norm_digits_sql('u.`شماره صندلي`');
    $rnorm = norm_digits_sql('u.`ردیف`');

    $unionAlias = "( {$unionSql} ) AS u";
    $sqlSeats = "INSERT INTO exam_seats (student_id, course_code, seat_number, building, class_name, seat_row, exam_type)\n"
        . "SELECT\n"
        . "  {$sid} AS student_id,\n"
        . "  {$ccode} AS course_code,\n"
        . "  CAST({$snorm} AS UNSIGNED) AS seat_number,\n"
        . "  COALESCE(TRIM(u.`ساختمان`), '') AS building,\n"
        . "  COALESCE(TRIM(u.`کلاس`), '') AS class_name,\n"
        . "  CASE WHEN {$rnorm} REGEXP '^[0-9]+$' THEN CAST({$rnorm} AS UNSIGNED) ELSE 0 END AS seat_row,\n"
        . "  COALESCE(TRIM(u.`نوع آزمون`), '') AS exam_type\n"
        . "FROM {$unionAlias}\n"
        . "JOIN students s ON s.student_id = {$sid}\n"
        . "JOIN courses  c ON c.course_code = {$ccode}\n"
        . "WHERE {$sid} <> '' AND {$ccode} <> '' AND {$snorm} REGEXP '^[0-9]+$'";
    $pdo->exec($sqlSeats);
    $insertedSeats = $pdo->query('SELECT COUNT(*) AS c FROM exam_seats')->fetch()['c'] ?? 0;

    // Stage: extract unique locations (building / class) and populate `locations` table
    write_progress('locations', 'در حال گردآوری و درج مکان‌ها...', 92);
    // Insert distinct building/class pairs. We deliberately do NOT store a combined "location_label" field
    // as requested — keep building and class_name separate. required_proctors is left at default 0.
    $sqlLocations = "INSERT INTO locations (building, class_name, required_proctors)
        SELECT DISTINCT
            COALESCE(TRIM(u.`ساختمان`), '') AS building,
            COALESCE(TRIM(u.`کلاس`), '') AS class_name,
            0 AS required_proctors
        FROM {$unionAlias}
        WHERE (u.`ساختمان` IS NOT NULL AND TRIM(u.`ساختمان`) <> '') OR (u.`کلاس` IS NOT NULL AND TRIM(u.`کلاس`) <> '')";
    $pdo->exec($sqlLocations);
    $insertedLocations = $pdo->query('SELECT COUNT(*) AS c FROM locations')->fetch()['c'] ?? 0;

    // Commit
    $pdo->commit();

    // Stage 6: done
    write_progress('done', 'به‌روزرسانی کامل شد', 100);
    // Clean progress file (leave it a moment so UI can reach 100%)
    // We'll not delete immediately; the client may remove it or we can leave it for a bit.
    // @unlink($progressFile);

    // Post-success cleanup: remove uploaded Excel files and drop temporary tables so no leftover data remains.
    try {
        // Remove uploaded files created by uploadDatabase.php (E.* and K.* with common extensions)
        $uploadDir = __DIR__ . '/../database/';
        $types = ['E', 'K'];
        $exts = ['xlsx', 'xls'];
        foreach ($types as $t) {
            foreach ($exts as $ext) {
                $f = $uploadDir . $t . '.' . $ext;
                if (file_exists($f)) {
                    @unlink($f);
                }
            }
        }

        // Drop temp tables if they exist
        try {
            $pdo->exec('DROP TABLE IF EXISTS `e-exams`');
        } catch (Throwable $e) {
            // ignore drop errors
        }
        try {
            $pdo->exec('DROP TABLE IF EXISTS `k-exams`');
        } catch (Throwable $e) {
            // ignore drop errors
        }
    } catch (Throwable $e) {
        // Log but don't fail the response; cleanup is best-effort
        error_log('Post-update cleanup failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'sources' => $sources,
        'inserted' => [
            'courses' => (int)$insertedCourses,
            'students' => (int)$insertedStudents,
            'exam_seats' => (int)$insertedSeats,
            'locations' => (int)($insertedLocations ?? 0)
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Rollback if in transaction
    if ($pdo instanceof PDO) {
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) {}
    }
    write_progress('error', 'خطا در به‌روزرسانی پایگاه داده: ' . $e->getMessage(), 0);
    http_response_code(500);
    echo json_encode(['error' => 'خطا در به‌روزرسانی پایگاه داده'], JSON_UNESCAPED_UNICODE);
}

?>
