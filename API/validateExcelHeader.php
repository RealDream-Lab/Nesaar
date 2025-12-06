<?php
// Start session for admin authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure errors don't get emitted as HTML (which would break JSON consumers)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../database/api_errors.log');
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Convert PHP warnings/notices to exceptions so they are handled uniformly
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function ($e) {
    http_response_code(500);
    error_log((string)$e);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Internal server error', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/../vendor/autoload.php';

use OpenSpout\Reader\XLSX\Reader as XLSXReader;
use OpenSpout\Reader\XLS\Reader as XLSReader;

// Enforce CSRF and license prior to any heavy work
csrf_enforce();
$__lic = license_guard_validate(false);
if ($__lic['valid'] !== true) {
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

// Require authenticated admin session and rate limit validations
$session      = admin_session_require($pdo);
$rateLimitKey = 'validate_excel_header:' . ($session['username'] ?? 'unknown');
// Allow higher rate for header validation during bulk imports (interactive tools may call this frequently)
rate_limit_enforce($pdo, $rateLimitKey, 100, 120);

$filename = $_POST['filename'] ?? '';
$examType = $_POST['examType'] ?? '';

if (empty($filename) || !in_array($examType, ['K', 'E'])) {
    http_response_code(400);
    echo json_encode(['error' => 'نام فایل یا نوع آزمون نامعتبر است']);
    exit;
}

$filePath = __DIR__ . '/../database/' . $filename;
if (!file_exists($filePath)) {
    http_response_code(400);
    echo json_encode(['error' => 'فایل یافت نشد']);
    exit;
}

$fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
try {
    if ($fileExt === 'xlsx') {
        $reader = new XLSXReader();
    } elseif ($fileExt === 'xls') {
        $reader = new XLSReader();
    } else {
        throw new Exception('Unsupported file type');
    }
    $reader->open($filePath);
    $headerRow = null;
    $rowCount  = 0;
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $r) {
            // OpenSpout v5 compatibility
            $rowData = $r->toArray();
            if ($headerRow === null) {
                $headerRow = $rowData;
            }
            $rowCount++;
        }
        break;
    }
    $reader->close();

    if ($headerRow === null) {
        http_response_code(400);
        echo json_encode(['error' => 'فایل خالی است']);
        exit;
    }

    // Expected columns (must match server logic)
    $columns = [
        'شماره دانشجويي',
        'شماره شناسنامه',
        'مرکز مبدا',
        'مرکز مقصد',
        'نام',
        'نام خانوادگي',
        'مدرک',
        'کد درس',
        'نام درس',
        'تاريخ آزمون',
        'ساعت آزمون',
        'شماره صندلي',
        'نوع آزمون',
        'نوع درس',
        'ساختمان',
        'کلاس',
        'ردیف'
    ];

    $normalize = function ($s) {
        if ($s === null)
            return '';
        $s = trim((string)$s);
        $s = str_replace(['ك', 'ي'], ['ک', 'ی'], $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower($s);
    };

    $expected = array_map($normalize, $columns);
    $actual   = array_map($normalize, $headerRow);

    // Check each expected exists in header
    foreach ($expected as $exp) {
        $found = false;
        foreach ($actual as $act) {
            if ($exp === $act) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            http_response_code(400);
            echo json_encode(['error' => 'فایل منطبق با ساختار فایل نرم افزار ساد نیست']);
            exit;
        }
    }

    // Success: return total data rows (exclude header)
    $totalDataRows = max(0, $rowCount - 1);
    echo json_encode(['success' => true, 'totalRows' => $totalDataRows]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در خواندن فایل: ' . $e->getMessage()]);
    exit;
}

?>