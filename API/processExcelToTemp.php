<?php
// Start session before any output or headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent PHP from emitting HTML error pages that break JSON consumers.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../database/api_errors.log');
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function ($e) {
    http_response_code(500);
    error_log((string)$e);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Internal server error', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../vendor/autoload.php'; // For OpenSpout
require_once __DIR__ . '/db_init.php';

use OpenSpout\Reader\XLSX\Reader as XLSXReader;

// Enforce CSRF first (cheap)
csrf_enforce();
// Enforce license with optional soft bypass controlled by Config
$__lic = license_guard_validate(false);
if ($__lic['valid'] !== true) {
    // Optional soft bypass: Allow import when license is temporarily invalid
    // Controlled by Config key 'AllowImportOnInvalidLicense' == 'YES'
    $allowBypass = false;
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'AllowImportOnInvalidLicense'");
            $st->execute();
            $val         = strtoupper(trim((string)($st->fetchColumn() ?? '')));
            $allowBypass = ($val === 'YES');
        }
    } catch (Throwable $e) { /* ignore and deny bypass */
    }
    if (!$allowBypass) {
        license_guard_respond_forbidden($__lic['message'] ?? 'License validation failed');
    }
}


// Set response header
header('Content-Type: application/json; charset=utf-8');

// Check if admin is authenticated via signed session cookie
$session = admin_session_require($pdo);

$rateLimitKey = 'process_excel:' . ($session['username'] ?? 'unknown');
// Processing can be invoked frequently by batch flows; raise limit to avoid false positives
rate_limit_enforce($pdo, $rateLimitKey, 100, 300);

// Get exam type and filename
$examType = $_POST['examType'] ?? '';
$filename = $_POST['filename'] ?? '';

if (!in_array($examType, ['K', 'E'])) {
    http_response_code(400);
    echo json_encode(['error' => 'نوع آزمون نامعتبر است']);
    exit;
}

if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'نام فایل مشخص نشده است']);
    exit;
}

// Check if file exists
$filePath = __DIR__ . '/../database/' . $filename;
if (!file_exists($filePath)) {
    http_response_code(400);
    echo json_encode(['error' => 'فایل یافت نشد']);
    exit;
}

// Get file extension
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

// Process the Excel file
try {
    // Create reader based on file type
    if ($fileExtension === 'xlsx') {
        $reader = new XLSXReader();
    } else {
        throw new Exception('فقط فایل‌های با پسوند xlsx پشتیبانی می‌شوند');
    }
    $reader->open($filePath);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            // In OpenSpout v4, Row is an object that contains cells, but getCells() might be deprecated or removed in favor of direct iteration or toArray()
            // Let's try toArray() which is the standard way to get values in v4
            $rowData = $row->toArray();
            $rows[]  = $rowData;
        }
        break; // Only first sheet
    }
    $reader->close();

    if (empty($rows)) {
        http_response_code(400);
        echo json_encode(['error' => 'فایل اکسل خالی است یا قابل خواندن نیست']);
        exit;
    }

    // Fixed columns for temp table
    $columns = [
        'شماره دانشجويي' => 'VARCHAR(9)',
        'شماره شناسنامه' => 'VARCHAR(10)',
        'مرکز مبدا' => 'VARCHAR(4)',
        'مرکز مقصد' => 'VARCHAR(4)',
        'نام' => 'VARCHAR(50)',
        'نام خانوادگي' => 'VARCHAR(50)',
        'مدرک' => 'VARCHAR(15)',
        'کد درس' => 'VARCHAR(7)',
        'نام درس' => 'VARCHAR(100)',
        'تاريخ آزمون' => 'VARCHAR(10)',
        'ساعت آزمون' => 'VARCHAR(5)',
        'شماره صندلي' => 'VARCHAR(6)',
        'نوع آزمون' => 'VARCHAR(20)',
        'نوع درس' => 'VARCHAR(20)',
        'ساختمان' => 'VARCHAR(50)',
        'کلاس' => 'VARCHAR(50)',
        'ردیف' => 'VARCHAR(50)'
    ];

    // Progress file path (per-upload)
    $progressFile = __DIR__ . '/../database/progress_' . basename($filename) . '.json';
    // Initialize progress file
    file_put_contents($progressFile, json_encode([
        'stage' => 'reading',
        'totalRows' => 0,
        'processedRows' => 0,
        'message' => 'در حال خواندن فایل'
    ], JSON_UNESCAPED_UNICODE));

    // Connect to database

    // Validate header (first row) and build mapping from expected columns to actual indexes
    $headerRow = $rows[0];
    // Normalization helper
    $normalize = function ($s) {
        if ($s === null)
            return '';
        $s = trim((string)$s);
        // Convert Arabic chars to Persian
        $s = str_replace(['ك', 'ي'], ['ک', 'ی'], $s);
        // Normalize whitespace
        $s = preg_replace('/\s+/u', ' ', $s);
        // Lowercase for comparison
        $s = mb_strtolower($s);
        return $s;
    };

    $expected = array_map($normalize, array_keys($columns));
    $actual   = array_map($normalize, $headerRow);

    // Build mapping: expected index -> actual column index
    $mapping = [];
    foreach ($expected as $expIdx => $expName) {
        $found = false;
        foreach ($actual as $actIdx => $actName) {
            if ($expName === $actName) {
                $mapping[$expIdx] = $actIdx;
                $found            = true;
                break;
            }
        }
        if (!$found) {
            // Missing required column
            file_put_contents($progressFile, json_encode(['stage' => 'error', 'message' => 'فایل منطبق با ساختار فایل نرم افزار ساد نیست', 'totalRows' => 0, 'processedRows' => 0], JSON_UNESCAPED_UNICODE));
            http_response_code(400);
            echo json_encode(['error' => 'فایل منطبق با ساختار فایل نرم افزار ساد نیست']);
            exit;
        }
    }

    // All expected columns found; now create target table and prepare insert
    // Determine target table name based on examType (k or e)
    // Use descriptive table names 'k-exams' and 'e-exams'
    $targetTable = strtolower($examType) === 'k' ? 'k-exams' : 'e-exams';

    // Drop target table if exists
    $pdo->query("DROP TABLE IF EXISTS `" . $targetTable . "`");

    // Create target table with fixed schema
    $createSql  = "CREATE TABLE `" . $targetTable . "` (";
    $columnDefs = [];
    foreach ($columns as $name => $type) {
        $columnDefs[] = "`" . str_replace('`', '``', $name) . "` " . $type . " CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL";
    }
    $createSql .= implode(', ', $columnDefs) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if ($pdo->query($createSql) === false) {
        $err = $pdo->errorInfo();
        throw new Exception('خطا در ایجاد جدول موقت: ' . ($err[2] ?? 'Unknown error'));
    }

    // Prepare insert into target table
    $insertedRows = 0;
    $columnNames  = array_keys($columns);
    $placeholders = implode(',', array_fill(0, count($columnNames), '?'));
    $stmt         = $pdo->prepare("INSERT INTO `" . $targetTable . "` (" . implode(',', array_map(fn($n) => "`" . str_replace('`', '``', $n) . "`", $columnNames)) . ") VALUES (" . $placeholders . ")");

    // Set total rows for progress (exclude header)
    $totalDataRows = max(0, count($rows) - 1);
    file_put_contents($progressFile, json_encode(['stage' => 'inserting', 'totalRows' => $totalDataRows, 'processedRows' => 0, 'message' => 'در حال درج در دیتابیس'], JSON_UNESCAPED_UNICODE));

    $lastProgressWrite = microtime(true);
    foreach ($rows as $rowIndex => $row) {
        if ($rowIndex == 0)
            continue; // Skip header

        $values = [];
        for ($i = 0; $i < count($columnNames); $i++) {
            $colIdx = $mapping[$i] ?? null;
            $value  = ($colIdx !== null) ? ($row[$colIdx] ?? null) : null;
            if ($value === null || $value === '') {
                $values[] = null;
            } elseif ($value instanceof \DateTimeInterface) {
                // For ساعت آزمون (index 10), format as H:i, else Y-m-d
                if ($i == 10) {
                    $values[] = $value->format('H:i');
                } else {
                    $values[] = $value->format('Y-m-d');
                }
            } else {
                $strValue = (string)$value;
                // Convert Arabic characters to Persian
                $strValue = str_replace(['ك', 'ي'], ['ک', 'ی'], $strValue);
                // For ساعت آزمون (index 10), format as HH:MM from digits if applicable
                $clean = preg_replace('/[^0-9]/', '', $strValue);
                if ($i == 10 && strlen($clean) >= 4) {
                    $strValue = substr($clean, 0, 2) . ':' . substr($clean, 2, 2);
                }
                $values[] = $strValue;
            }
        }

        if ($stmt->execute($values)) {
            $insertedRows++;
        }

        // Update progress file every 20 rows or every 0.5s
        if ($insertedRows % 20 === 0 || (microtime(true) - $lastProgressWrite) > 0.5) {
            file_put_contents($progressFile, json_encode(['stage' => 'inserting', 'totalRows' => $totalDataRows, 'processedRows' => $insertedRows, 'message' => 'در حال درج در دیتابیس'], JSON_UNESCAPED_UNICODE));
            $lastProgressWrite = microtime(true);
        }
    }

    // Finalize progress file
    file_put_contents($progressFile, json_encode(['stage' => 'done', 'totalRows' => $totalDataRows, 'processedRows' => $insertedRows, 'message' => 'پردازش کامل شد', 'success' => true], JSON_UNESCAPED_UNICODE));

    // Log the processing
    $logMessage = date('Y-m-d H:i:s') . " - Excel processed to table {$targetTable}: {$insertedRows} rows, " . count($columns) . " columns by " . ($session['username'] ?? 'Unknown') . "\n";
    $logFile    = __DIR__ . '/../database/excel_log.txt';
    file_put_contents($logFile, $logMessage, FILE_APPEND);

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'فایل اکسل با موفقیت پردازش شد',
        'rows' => $insertedRows,
        'columns' => count($columns),
        'examType' => $examType === 'K' ? 'کتبی' : 'الکترونیکی'
    ]);

    // Remove the processed Excel file immediately after successful import to temp table
    // Data is now in database, no need to keep the file
    if (isset($filePath) && file_exists($filePath)) {
        if (!unlink($filePath)) {
            error_log("Failed to delete processed Excel file: {$filePath}");
        }
    }

    // Remove progress file after successful processing to avoid leaving temporary files
    if (isset($progressFile) && file_exists($progressFile)) {
        @unlink($progressFile);
    }

} catch (Exception $e) {
    // Write error to progress file if available
    if (isset($progressFile)) {
        @file_put_contents($progressFile, json_encode(['stage' => 'error', 'message' => 'خطا در پردازش فایل: ' . $e->getMessage(), 'totalRows' => $totalDataRows ?? 0, 'processedRows' => $insertedRows ?? 0], JSON_UNESCAPED_UNICODE));
    }
    http_response_code(500);
    echo json_encode(['error' => 'خطا در پردازش فایل: ' . $e->getMessage()]);
    exit;
}

?>