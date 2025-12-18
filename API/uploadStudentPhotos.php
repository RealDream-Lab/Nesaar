<?php
/**
 * API for uploading student photos
 * Accepts multiple JPG files with 9-digit numeric filenames
 * Stores in /pic/{SaadCode}/ directory
 */

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once 'db_init.php';

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

license_guard_enforce_api();

// Rate limiting: 20000 requests per 5 minutes per IP (for bulk photo uploads)
rate_limit_enforce($pdo, 'admin_photo_upload', 20000, 300);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
if (!csrf_validate_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Get SaadCode from config
try {
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'SaadCode'");
    $stmt->execute();
    $saadCode = $stmt->fetchColumn();

    if (!$saadCode || strlen(trim($saadCode)) !== 4) {
        echo json_encode(['success' => false, 'error' => 'کد ساد در تنظیمات یافت نشد یا نامعتبر است']);
        exit;
    }
    $saadCode = trim($saadCode);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در دریافت تنظیمات']);
    exit;
}

// Create target directory if not exists
$picDir = __DIR__ . '/../pic';
if (!is_dir($picDir)) {
    if (!mkdir($picDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'خطا در ایجاد پوشه pic']);
        exit;
    }
}
$targetDir = $picDir . '/' . $saadCode;
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'خطا در ایجاد پوشه ذخیره‌سازی']);
        exit;
    }
}

// Check if files were uploaded
if (!isset($_FILES['photos']) || empty($_FILES['photos']['name'][0])) {
    echo json_encode(['success' => false, 'error' => 'هیچ فایلی آپلود نشده است']);
    exit;
}

$uploaded = 0;
$failed   = 0;
$errors   = [];

$files      = $_FILES['photos'];
$totalFiles = count($files['name']);

for ($i = 0; $i < $totalFiles; $i++) {
    $originalName = $files['name'][$i];
    $tmpName      = $files['tmp_name'][$i];
    $error        = $files['error'][$i];
    $size         = $files['size'][$i];

    // Check for upload errors
    if ($error !== UPLOAD_ERR_OK) {
        $failed++;
        continue;
    }

    // Get filename without extension
    $pathInfo  = pathinfo($originalName);
    $filename  = $pathInfo['filename'];
    $extension = strtolower($pathInfo['extension'] ?? '');

    // Validate extension is jpg/jpeg
    if (!in_array($extension, ['jpg', 'jpeg'])) {
        $failed++;
        $errors[] = "فایل {$originalName}: فرمت فایل باید JPG باشد";
        continue;
    }

    // Validate filename is exactly 9 digits (English digits only)
    if (!preg_match('/^[0-9]{9}$/', $filename)) {
        $failed++;
        $errors[] = "فایل {$originalName}: نام فایل باید دقیقاً ۹ رقم انگلیسی باشد";
        continue;
    }

    // Validate file is actually an image
    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false || !in_array($imageInfo[2], [IMAGETYPE_JPEG])) {
        $failed++;
        $errors[] = "فایل {$originalName}: فایل یک تصویر JPEG معتبر نیست";
        continue;
    }

    // Move file to target directory (always save as .jpg)
    $targetPath = $targetDir . '/' . $filename . '.jpg';

    if (move_uploaded_file($tmpName, $targetPath)) {
        $uploaded++;
    } else {
        $failed++;
        $errors[] = "فایل {$originalName}: خطا در ذخیره‌سازی";
    }
}

// Audit log
audit_log($pdo, 'ADMIN_PHOTO_UPLOAD', 'آپلود دسته‌ای عکس دانشجویان', 'admin', [
    'uploaded' => $uploaded,
    'failed' => $failed,
    'total' => $totalFiles,
    'saad_code' => $saadCode
]);

echo json_encode([
    'success' => true,
    'uploaded' => $uploaded,
    'failed' => $failed,
    'total' => $totalFiles,
    'errors' => array_slice($errors, 0, 10) // Only return first 10 errors
], JSON_UNESCAPED_UNICODE);
