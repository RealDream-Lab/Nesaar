<?php
/**
 * Upload and process proctors Excel file
 * Validates data, checks for duplicates, and inserts valid records
 */

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

// Enforce license
$__lic = license_guard_validate(false);
if ($__lic['valid'] !== true) {
    license_guard_respond_forbidden($__lic['message'] ?? 'License validation failed');
}

// Set response header
header('Content-Type: application/json; charset=utf-8');

// Check if admin is authenticated
$session = admin_session_require($pdo);

// Rate limit
$rateLimitKey = 'upload_proctors_excel:' . ($session['username'] ?? 'unknown');
rate_limit_enforce($pdo, $rateLimitKey, 20, 300);

// Helper function to convert Persian/Arabic digits to English
function toEnglishDigits($str)
{
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $str     = str_replace($persian, $english, $str);
    $str     = str_replace($arabic, $english, $str);
    return $str;
}

// Check file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'حجم فایل بیش از حد مجاز است',
        UPLOAD_ERR_FORM_SIZE => 'حجم فایل بیش از حد مجاز فرم است',
        UPLOAD_ERR_PARTIAL => 'فایل به صورت ناقص آپلود شد',
        UPLOAD_ERR_NO_FILE => 'هیچ فایلی آپلود نشد',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت یافت نشد',
        UPLOAD_ERR_CANT_WRITE => 'امکان نوشتن فایل وجود ندارد',
        UPLOAD_ERR_EXTENSION => 'یک افزونه آپلود را متوقف کرد',
    ];
    $errorCode     = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMsg      = $errorMessages[$errorCode] ?? 'خطا در آپلود فایل';
    http_response_code(400);
    echo json_encode(['error' => $errorMsg], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadedFile = $_FILES['file'];
$filename     = $uploadedFile['name'];
$tmpPath      = $uploadedFile['tmp_name'];

// Check file extension
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if ($fileExtension !== 'xlsx') {
    http_response_code(400);
    echo json_encode(['error' => 'فقط فایل‌های با پسوند xlsx پشتیبانی می‌شوند'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Move file to temp location
$tempFilePath = __DIR__ . '/../temp/proctors_upload_' . time() . '.xlsx';
if (!move_uploaded_file($tmpPath, $tempFilePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در ذخیره فایل موقت'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Open and read the Excel file
    $reader = new XLSXReader();
    $reader->open($tempFilePath);

    $results = [
        'total' => 0,
        'inserted' => 0,
        'skipped_duplicate_national_id' => 0,
        'skipped_duplicate_phone' => 0,
        'skipped_invalid' => 0,
        'errors' => []
    ];

    // Get existing national_ids and phones for duplicate checking
    $existingNationalIds = [];
    $existingPhones      = [];

    $stmt = $pdo->query('SELECT national_id, phone FROM `Proctors`');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingNationalIds[$row['national_id']] = true;
        $existingPhones[$row['phone']]            = true;
    }

    // Track national_ids and phones within the uploaded file to detect in-file duplicates
    $fileNationalIds = [];
    $filePhones      = [];

    // Prepare insert statement
    $insertStmt = $pdo->prepare('INSERT INTO `Proctors` (gender, first_name, last_name, national_id, phone) VALUES (?, ?, ?, ?, ?)');

    $isFirstRow = true;
    $rowNumber  = 0;

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rowNumber++;
            // Use toArray() instead of getCells() for OpenSpout v4 compatibility
            $cells = $row->toArray();

            // Skip header row (first row)
            if ($isFirstRow) {
                $isFirstRow = false;
                continue;
            }

            $results['total']++;

            // Extract cell values
            $gender     = trim((string)($cells[0] ?? ''));
            $firstName  = trim((string)($cells[1] ?? ''));
            $lastName   = trim((string)($cells[2] ?? ''));
            $nationalId = toEnglishDigits(trim((string)($cells[3] ?? '')));
            $phone      = toEnglishDigits(trim((string)($cells[4] ?? '')));

            // Validate required fields
            if (empty($firstName) || empty($lastName) || empty($nationalId) || empty($phone)) {
                $results['skipped_invalid']++;
                $results['errors'][] = "ردیف $rowNumber: فیلدهای اجباری خالی هستند";
                continue;
            }

            // Validate gender
            if (!in_array($gender, ['مرد', 'زن', ''])) {
                // Try to normalize common values
                $genderLower = mb_strtolower($gender);
                if (in_array($genderLower, ['male', 'm', '1'])) {
                    $gender = 'مرد';
                } elseif (in_array($genderLower, ['female', 'f', '0', '2'])) {
                    $gender = 'زن';
                } else {
                    $gender = ''; // Allow empty gender
                }
            }

            // Fix national_id: pad with leading zeros if less than 10 digits
            // Excel often strips leading zeros from numbers
            if (preg_match('/^\d+$/', $nationalId) && strlen($nationalId) < 10) {
                $nationalId = str_pad($nationalId, 10, '0', STR_PAD_LEFT);
            }

            // Validate national_id (must be exactly 10 digits)
            if (!preg_match('/^\d{10}$/', $nationalId)) {
                $results['skipped_invalid']++;
                $results['errors'][] = "ردیف $rowNumber: شماره ملی باید دقیقاً ۱۰ رقم باشد";
                continue;
            }

            // Fix phone: if 10 digits and starts with 9, add leading 0
            // Excel often strips leading zeros from numbers like 09121234567 -> 9121234567
            if (preg_match('/^9\d{9}$/', $phone)) {
                $phone = '0' . $phone;
            }

            // Validate phone (must be exactly 11 digits)
            if (!preg_match('/^\d{11}$/', $phone)) {
                $results['skipped_invalid']++;
                $results['errors'][] = "ردیف $rowNumber: شماره همراه باید دقیقاً ۱۱ رقم باشد";
                continue;
            }

            // Check for duplicate national_id in database
            if (isset($existingNationalIds[$nationalId])) {
                $results['skipped_duplicate_national_id']++;
                continue;
            }

            // Check for duplicate phone in database
            if (isset($existingPhones[$phone])) {
                $results['skipped_duplicate_phone']++;
                continue;
            }

            // Check for duplicate national_id within file
            if (isset($fileNationalIds[$nationalId])) {
                $results['skipped_duplicate_national_id']++;
                continue;
            }

            // Check for duplicate phone within file
            if (isset($filePhones[$phone])) {
                $results['skipped_duplicate_phone']++;
                continue;
            }

            // Track in-file duplicates
            $fileNationalIds[$nationalId] = true;
            $filePhones[$phone]           = true;

            // Insert the proctor
            try {
                $insertStmt->execute([$gender, $firstName, $lastName, $nationalId, $phone]);
                $results['inserted']++;

                // Add to existing lists to prevent duplicate in next rows
                $existingNationalIds[$nationalId] = true;
                $existingPhones[$phone]           = true;
            } catch (PDOException $e) {
                // Handle unique constraint violations (shouldn't happen with our checks, but just in case)
                if ($e->getCode() == 23000) {
                    $results['skipped_duplicate_national_id']++;
                } else {
                    throw $e;
                }
            }
        }

        // Only process first sheet
        break;
    }

    $reader->close();

    // Clean up temp file
    if (file_exists($tempFilePath)) {
        unlink($tempFilePath);
    }

    // Build response message
    $message = sprintf(
        '%d مراقب با موفقیت اضافه شد.',
        $results['inserted']
    );

    $skipped = $results['skipped_duplicate_national_id'] + $results['skipped_duplicate_phone'] + $results['skipped_invalid'];
    if ($skipped > 0) {
        $message .= sprintf(' (%d ردیف رد شد)', $skipped);
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'results' => $results
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Clean up temp file on error
    if (isset($tempFilePath) && file_exists($tempFilePath)) {
        unlink($tempFilePath);
    }

    error_log('Proctors Excel upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'خطا در پردازش فایل: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
