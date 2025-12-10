<?php
/**
 * API for student photo update requests
 * Students can upload a new photo for admin approval
 * Photos are stored in /pic/{SaadCode}/StudentsUpdate/
 */

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once 'db_init.php';

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

license_guard_enforce_api();

// Rate limiting: 10 requests per 5 minutes per IP
rate_limit_enforce($pdo, 'student_photo_upload', 10, 300);

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

// Get student_id and national_id from POST
$studentId  = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
$nationalId = isset($_POST['national_id']) ? trim($_POST['national_id']) : '';

// Validate student_id format (9 digits)
if (!preg_match('/^[0-9]{9}$/', $studentId)) {
    echo json_encode(['success' => false, 'error' => 'شماره دانشجویی نامعتبر است']);
    exit;
}

// Validate national_id format (10 digits)
if (!preg_match('/^[0-9]{10}$/', $nationalId)) {
    echo json_encode(['success' => false, 'error' => 'کد ملی نامعتبر است']);
    exit;
}

// Verify student exists in database
try {
    $stmt = $pdo->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_id = ? AND national_id = ?");
    $stmt->execute([$studentId, $nationalId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'اطلاعات دانشجو یافت نشد یا کد ملی مطابقت ندارد']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در بررسی اطلاعات دانشجو']);
    exit;
}

// Get SaadCode from config
try {
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'SaadCode'");
    $stmt->execute();
    $saadCode = $stmt->fetchColumn();

    if (!$saadCode || strlen(trim($saadCode)) !== 4) {
        echo json_encode(['success' => false, 'error' => 'کد ساد در تنظیمات یافت نشد']);
        exit;
    }
    $saadCode = trim($saadCode);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در دریافت تنظیمات']);
    exit;
}

// Create directories if not exist
$picDir = __DIR__ . '/../pic';
if (!is_dir($picDir)) {
    mkdir($picDir, 0755, true);
}

$saadDir = $picDir . '/' . $saadCode;
if (!is_dir($saadDir)) {
    mkdir($saadDir, 0755, true);
}

$updateDir = $saadDir . '/StudentsUpdate';
if (!is_dir($updateDir)) {
    if (!mkdir($updateDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'خطا در ایجاد پوشه ذخیره‌سازی']);
        exit;
    }
}

// Check if file was uploaded
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'حجم فایل بیش از حد مجاز است',
        UPLOAD_ERR_FORM_SIZE => 'حجم فایل بیش از حد مجاز است',
        UPLOAD_ERR_PARTIAL => 'فایل به طور کامل آپلود نشد',
        UPLOAD_ERR_NO_FILE => 'هیچ فایلی انتخاب نشده است',
        UPLOAD_ERR_NO_TMP_DIR => 'خطای سرور',
        UPLOAD_ERR_CANT_WRITE => 'خطا در نوشتن فایل',
    ];
    $errorCode    = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMsg     = $uploadErrors[$errorCode] ?? 'خطا در آپلود فایل';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$tmpFile      = $_FILES['photo']['tmp_name'];
$originalName = $_FILES['photo']['name'];

// Validate file is actually a JPEG image
$imageInfo = @getimagesize($tmpFile);
if ($imageInfo === false || $imageInfo[2] !== IMAGETYPE_JPEG) {
    echo json_encode(['success' => false, 'error' => 'فایل باید یک تصویر JPG معتبر باشد']);
    exit;
}

// Limit file size (max 512KB)
if ($_FILES['photo']['size'] > 512 * 1024) {
    echo json_encode(['success' => false, 'error' => 'حجم فایل نباید بیش از ۵۱۲ کیلوبایت باشد']);
    exit;
}

// Generate unique filename with timestamp
$timestamp   = date('Y-m-d_H-i-s');
$extension   = 'jpg';
$newFilename = $studentId . '_' . $timestamp . '.' . $extension;
$targetPath  = $updateDir . '/' . $newFilename;

// Move uploaded file
if (!move_uploaded_file($tmpFile, $targetPath)) {
    echo json_encode(['success' => false, 'error' => 'خطا در ذخیره‌سازی فایل']);
    exit;
}

// Create or update photo_update_requests table
try {
    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `photo_update_requests` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `student_id` CHAR(9) NOT NULL,
        `first_name` VARCHAR(50) NOT NULL,
        `last_name` VARCHAR(50) NOT NULL,
        `filename` VARCHAR(100) NOT NULL,
        `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
        INDEX `idx_status` (`status`),
        INDEX `idx_student` (`student_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insert new request
    $stmt = $pdo->prepare("INSERT INTO photo_update_requests (student_id, first_name, last_name, filename) VALUES (?, ?, ?, ?)");
    $stmt->execute([$studentId, $student['first_name'], $student['last_name'], $newFilename]);

    // Audit log
    audit_log($pdo, 'PHOTO_UPDATE_REQUEST', 'درخواست آپلود عکس دانشجو', $studentId, [
        'filename' => $newFilename,
        'first_name' => $student['first_name'],
        'last_name' => $student['last_name']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'درخواست شما با موفقیت ثبت شد و پس از تایید مدیر اعمال خواهد شد'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Delete uploaded file if database insert failed
    @unlink($targetPath);
    echo json_encode(['success' => false, 'error' => 'خطا در ثبت درخواست']);
    exit;
}
