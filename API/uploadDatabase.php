<?php
// Start session before any output or headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent PHP from emitting HTML error pages that break JSON consumers.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../database/api_errors.log');
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function($e) {
    http_response_code(500);
    error_log((string)$e);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Internal server error', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';

// Helper function to get max upload size from PHP configuration
function getMaxUploadSize() {
    $upload_max = ini_get('upload_max_filesize');
    $post_max = ini_get('post_max_size');
    
    // Convert to bytes
    $upload_max_bytes = convertToBytes($upload_max);
    $post_max_bytes = convertToBytes($post_max);
    
    // Return the smaller of the two
    return min($upload_max_bytes, $post_max_bytes);
}

function convertToBytes($value) {
    $value = trim($value);
    $unit = strtolower($value[strlen($value) - 1]);
    $number = (int)$value;
    
    switch($unit) {
        case 'g':
            $number *= 1024;
        case 'm':
            $number *= 1024;
        case 'k':
            $number *= 1024;
    }
    
    return $number;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Enforce license and CSRF protection
license_guard_enforce_api();
csrf_enforce();

// Set response header
header('Content-Type: application/json; charset=utf-8');

// Check if admin is authenticated
$adminSession = $_COOKIE['adminSession'] ?? null;
if (!$adminSession) {
    http_response_code(401);
    echo json_encode(['error' => 'دسترسی غیرمجاز']);
    exit;
}

try {
    $session = json_decode(urldecode($adminSession), true);
    if (!$session || ($session['type'] ?? '') !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'دسترسی غیرمجاز']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'دسترسی غیرمجاز']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'فایلی آپلود نشده است']);
    exit;
}

// Get the exam type (K for written, E for electronic)
$examType = $_POST['examType'] ?? '';
if (!in_array($examType, ['K', 'E'])) {
    http_response_code(400);
    echo json_encode(['error' => 'نوع آزمون نامعتبر است']);
    exit;
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileTmpName = $file['tmp_name'];
$fileSize = $file['size'];

// Get file extension
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// Validate file extension (only Excel files)
if (!in_array($fileExtension, ['xls', 'xlsx'])) {
    http_response_code(400);
    echo json_encode(['error' => 'فقط فایل‌های با پسوند XLS و XLSX مجاز هستند']);
    exit;
}

// Validate file size (use PHP's configured max upload size)
$maxFileSize = getMaxUploadSize();
if ($fileSize > $maxFileSize) {
    http_response_code(400);
    echo json_encode([
        'error' => 'حجم فایل نباید بیشتر از ' . formatBytes($maxFileSize) . ' باشد'
    ]);
    exit;
}

// Validate MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fileTmpName);
finfo_close($finfo);

$allowedMimeTypes = [
    'application/vnd.ms-excel', // .xls
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
    'application/octet-stream' // Some systems use this for Excel files
];

if (!in_array($mimeType, $allowedMimeTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'نوع فایل نامعتبر است. لطفاً فقط فایل‌های اکسل ارسال کنید']);
    exit;
}

// Set target directory and filename
$targetDir = __DIR__ . '/../database/';
$newFileName = $examType . '.' . $fileExtension;
$targetFilePath = $targetDir . $newFileName;

// Create database directory if it doesn't exist
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['error' => 'خطا در ایجاد پوشه دیتابیس']);
        exit;
    }
}

// Move uploaded file to target directory
if (!move_uploaded_file($fileTmpName, $targetFilePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در ذخیره فایل']);
    exit;
}

// Log the upload
$logMessage = date('Y-m-d H:i:s') . " - Database file uploaded: {$newFileName} by " . ($session['username'] ?? 'Unknown') . "\n";
$logFile = __DIR__ . '/../database/upload_log.txt';
file_put_contents($logFile, $logMessage, FILE_APPEND);

// Return success response
echo json_encode([
    'success' => true,
    'message' => 'فایل با موفقیت آپلود شد',
    'filename' => $newFileName,
    'examType' => $examType === 'K' ? 'کتبی' : 'الکترونیکی'
]);
