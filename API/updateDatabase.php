<?php
// Start session before any output or headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/db_init.php';

// Enforce CSRF first
$proctorsCleared            = false;
$proctorRestrictionsCleared = false;

try {
    csrf_enforce();
} catch (Throwable $e) {
    exit;
}
// Enforce license with optional soft bypass (consistent with import endpoints)
$__lic = license_guard_validate(false);
if ($__lic['valid'] !== true) {
    $allowBypass = false;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName='AllowImportOnInvalidLicense'");
            $st->execute();
            $val         = strtoupper(trim((string)($st->fetchColumn() ?? '')));
            $allowBypass = ($val === 'YES');
        }
    } catch (Throwable $e) { /* ignore */
    }
    if (!$allowBypass) {
        license_guard_respond_forbidden($__lic['message'] ?? 'License validation failed');
    }
}

// Admin authentication (consistent with assignment endpoints)
$sessionData = admin_session_require($pdo);

$rateLimitKey = 'update_database:' . ($sessionData['username'] ?? 'unknown');
// Keep update database somewhat rate-limited because it's heavy, but allow moderate bursts
rate_limit_enforce($pdo, $rateLimitKey, 50, 600);

// Progress file utilities (re-use the same reader on client via getProcessProgress.php?filename=update)
$progressFile = __DIR__ . '/../database/progress_update.json';

function write_progress(string $stage, string $message, int $percent): void
{
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
function build_union_from_temp(PDO $pdo): array
{
    // Returns [sql, sources] where sources is array of table names included
    $sources = [];
    $parts   = [];
    $dbName  = '';
    try {
        $stmt   = $pdo->query('SELECT DATABASE() AS db');
        $dbName = $stmt ? ($stmt->fetch()['db'] ?? '') : '';
    } catch (Throwable $e) {
    }

    $checkExists = function (string $table) use ($pdo, $dbName): bool {
        try {
            $q = $pdo->prepare('SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $q->execute([$dbName, $table]);
            return ((int)($q->fetch()['cnt'] ?? 0)) > 0;
        } catch (Throwable $e) {
            return false;
        }
    };

    foreach (['e-exams', 'k-exams'] as $t) {
        if ($checkExists($t)) {
            $sources[] = $t;
            $safe      = str_replace('`', '``', $t);
            $parts[]   = "SELECT `شماره دانشجويي`,`شماره شناسنامه`,`مرکز مبدا`,`مرکز مقصد`,`نام`,`نام خانوادگي`,`مدرک`,`کد درس`,`نام درس`,`تاريخ آزمون`,`ساعت آزمون`,`شماره صندلي`,`نوع آزمون`,`نوع درس`,`ساختمان`,`کلاس`,`ردیف` FROM `{$safe}`";
        }
    }

    return [implode(" UNION ALL ", $parts), $sources];
}

// SQL helpers for digit normalization (Persian/Arabic to ASCII)
function norm_digits_sql(string $col): string
{
    // Compose nested REPLACE chain
    $rep  = [
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
    $expr = $col;
    foreach ($rep as $from => $to) {
        // escape single quotes and backslashes in $from/$to if any (not expected here)
        $fromEsc = str_replace("'", "''", $from);
        $toEsc   = str_replace("'", "''", $to);
        $expr    = "REPLACE(" . $expr . ", '{$fromEsc}', '{$toEsc}')";
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
    try {
        $pdo->exec('DROP TABLE IF EXISTS `ExamsDetil`');
    } catch (Throwable $e) { /* ignore */
    }
    try {
        $pdo->exec('DROP TABLE IF EXISTS `locations`');
    } catch (Throwable $e) { /* ignore */
    }

    // Ensure Centers table is recreated from SQL file
    try {
        $pdo->exec('DROP TABLE IF EXISTS `Centers`');
        $centersSql = file_get_contents(__DIR__ . '/../database/Centers.sql');
        $pdo->exec($centersSql);
    } catch (Throwable $e) {
        error_log('Centers table recreation failed: ' . $e->getMessage());
        write_progress('error', 'خطا در بازسازی جدول Centers', 0);
        http_response_code(500);
        echo json_encode(['error' => 'خطا در بازسازی جدول Centers'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Ensure `locations` table exists before starting transaction (CREATE TABLE is DDL and may cause implicit commit)
    $createLocations = "CREATE TABLE IF NOT EXISTS `locations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `building` VARCHAR(255) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `class_name` VARCHAR(255) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `required_proctors` INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY `ux_locations_building_class` (`building`,`class_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($createLocations);

    // Create locations_backup table if not exists (for preserving capacity data)
    $createLocationsBackup = "CREATE TABLE IF NOT EXISTS `locations_backup` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `building` VARCHAR(255) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `class_name` VARCHAR(255) DEFAULT '' COLLATE utf8mb4_unicode_ci,
        `capacity` INT UNSIGNED NOT NULL DEFAULT 0,
        `required_proctors` INT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `ux_locations_backup_building_class` (`building`,`class_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($createLocationsBackup);

    // Calculate and backup location capacities BEFORE clearing data
    try {
        write_progress('backup_locations', 'در حال محاسبه و پشتیبان‌گیری ظرفیت مکان‌ها...', 8);

        // Get max seat_number per building/class from current exam_seats
        $capacityStmt = $pdo->query("
            SELECT building, class_name, MAX(seat_number) AS max_capacity
            FROM exam_seats
            WHERE building IS NOT NULL AND building != ''
            GROUP BY building, class_name
        ");
        $capacities   = $capacityStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($capacities as $loc) {
            $building    = $loc['building'];
            $className   = $loc['class_name'];
            $newCapacity = (int)$loc['max_capacity'];

            if (empty($building) && empty($className))
                continue;

            // Check if exists in backup
            $checkStmt = $pdo->prepare("SELECT capacity FROM locations_backup WHERE building = ? AND class_name = ?");
            $checkStmt->execute([$building, $className]);
            $existingCapacity = $checkStmt->fetchColumn();

            if ($existingCapacity !== false) {
                // Only update if new capacity is greater
                if ($newCapacity > (int)$existingCapacity) {
                    $updateStmt = $pdo->prepare("UPDATE locations_backup SET capacity = ? WHERE building = ? AND class_name = ?");
                    $updateStmt->execute([$newCapacity, $building, $className]);
                }
            } else {
                // Insert new record
                $insertStmt = $pdo->prepare("INSERT INTO locations_backup (building, class_name, capacity) VALUES (?, ?, ?)");
                $insertStmt->execute([$building, $className, $newCapacity]);
            }
        }
    } catch (Throwable $e) {
        error_log('Locations backup failed: ' . $e->getMessage());
        // Continue with the update even if backup fails
    }

    // Backup/Update ProctorsBackup table BEFORE transaction (DDL commits implicitly)
    try {
        // Create backup table if not exists with same structure
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ProctorsBackup` LIKE `Proctors`");

        // Update existing records or insert new ones based on national_id
        $proctorsStmt = $pdo->query("SELECT * FROM `Proctors`");
        $proctors     = $proctorsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($proctors as $proctor) {
            $nationalId = $proctor['national_id'] ?? '';
            if (empty($nationalId))
                continue;

            // Check if this national_id exists in backup
            $checkStmt = $pdo->prepare("SELECT id FROM `ProctorsBackup` WHERE national_id = ?");
            $checkStmt->execute([$nationalId]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                // Update existing record
                $updateStmt = $pdo->prepare("UPDATE `ProctorsBackup` SET 
                    gender = ?, first_name = ?, last_name = ?, phone = ? 
                    WHERE national_id = ?");
                $updateStmt->execute([
                    $proctor['gender'] ?? '',
                    $proctor['first_name'] ?? '',
                    $proctor['last_name'] ?? '',
                    $proctor['phone'] ?? '',
                    $nationalId
                ]);
            } else {
                // Insert new record
                $insertStmt = $pdo->prepare("INSERT INTO `ProctorsBackup` 
                    (gender, first_name, last_name, national_id, phone) 
                    VALUES (?, ?, ?, ?, ?)");
                $insertStmt->execute([
                    $proctor['gender'] ?? '',
                    $proctor['first_name'] ?? '',
                    $proctor['last_name'] ?? '',
                    $nationalId,
                    $proctor['phone'] ?? ''
                ]);
            }
        }
    } catch (Throwable $e) {
        // Ignore backup errors, continue with the update
        error_log('Proctors backup failed: ' . $e->getMessage());
    }

    $pdo->beginTransaction();

    // Stage 2: delete existing
    write_progress('cleanup', 'در حال پاکسازی داده‌های قبلی...', 10);
    $pdo->exec('DELETE FROM exam_seats');
    $pdo->exec('DELETE FROM courses');
    $pdo->exec('DELETE FROM students');

    try {
        $pdo->exec('DELETE FROM Proctors');
        $proctorsCleared = true;
    } catch (Throwable $e) {
        $proctorsCleared = false;
    }
    try {
        $pdo->exec('DELETE FROM ProctorRestrictions');
        $proctorRestrictionsCleared = true;
    } catch (Throwable $e) {
        $proctorRestrictionsCleared = false;
    }
    try {
        $pdo->exec('DELETE FROM ExamAssignments');
    } catch (Throwable $e) {
        // ignore if table doesn't exist
    }

    // Common derived table
    $derived = "( {$unionSql} ) AS t";

    // Stage 3: insert courses
    write_progress('courses', 'در حال درج دروس...', 35);
    $course_code = norm_digits_sql('t.`کد درس`');
    $sqlCourses  = "INSERT INTO courses (course_code, course_name, exam_date, exam_time, course_type)\n"
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
    $student_id  = norm_digits_sql('t.`شماره دانشجويي`');
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
    $sqlSeats   = "INSERT INTO exam_seats (student_id, course_code, seat_number, building, class_name, seat_row, exam_type)\n"
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
        $uploadDir = realpath(__DIR__ . '/../database') . '/';
        $types     = ['E', 'K'];
        $exts      = ['xlsx', 'xls'];
        foreach ($types as $t) {
            foreach ($exts as $ext) {
                $f = $uploadDir . $t . '.' . $ext;
                if (file_exists($f)) {
                    if (!unlink($f)) {
                        error_log("Failed to delete Excel file: {$f}");
                    }
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
        ],
        'proctorsCleared' => $proctorsCleared,
        'proctorRestrictionsCleared' => $proctorRestrictionsCleared
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Rollback if in transaction
    if ($pdo instanceof PDO) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e2) {
        }
    }
    write_progress('error', 'خطا در به‌روزرسانی پایگاه داده: ' . $e->getMessage(), 0);
    http_response_code(500);
    echo json_encode(['error' => 'خطا در به‌روزرسانی پایگاه داده'], JSON_UNESCAPED_UNICODE);
}

?>