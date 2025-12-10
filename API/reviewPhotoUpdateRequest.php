<?php
/**
 * API to approve or reject photo update requests
 */

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/push_helper.php';
require_once 'db_init.php';

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

license_guard_enforce_api();

// Rate limiting: 30 requests per minute per IP
rate_limit_enforce($pdo, 'review_photo_request', 30, 60);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Validate CSRF
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$csrfToken) {
    $input     = json_decode(file_get_contents('php://input'), true);
    $csrfToken = $input['csrf_token'] ?? null;
}
if (!csrf_validate_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Get input
$input     = json_decode(file_get_contents('php://input'), true);
$requestId = isset($input['request_id']) ? intval($input['request_id']) : 0;
$action    = isset($input['action']) ? $input['action'] : '';

if ($requestId <= 0) {
    echo json_encode(['success' => false, 'error' => 'شناسه درخواست نامعتبر است']);
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'error' => 'عملیات نامعتبر است']);
    exit;
}

try {
    // Get request details
    $stmt = $pdo->prepare("SELECT * FROM photo_update_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        echo json_encode(['success' => false, 'error' => 'درخواست یافت نشد یا قبلاً بررسی شده است']);
        exit;
    }

    // Get SaadCode
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'SaadCode'");
    $stmt->execute();
    $saadCode = trim($stmt->fetchColumn());

    $updateDir    = __DIR__ . '/../pic/' . $saadCode . '/StudentsUpdate';
    $mainDir      = __DIR__ . '/../pic/' . $saadCode;
    $newPhotoPath = $updateDir . '/' . $request['filename'];

    if ($action === 'approve') {
        // Target path (always save as .jpg in main folder)
        $targetPath = $mainDir . '/' . $request['student_id'] . '.jpg';

        // Copy the file (only JPG files are accepted now)
        if (!copy($newPhotoPath, $targetPath)) {
            echo json_encode(['success' => false, 'error' => 'خطا در جایگزینی عکس']);
            exit;
        }

        // Update request status
        $stmt = $pdo->prepare("UPDATE photo_update_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$requestId]);

        // Delete the uploaded file from StudentsUpdate folder
        @unlink($newPhotoPath);

        // Audit log
        audit_log($pdo, 'PHOTO_REQUEST_APPROVED', 'تایید درخواست عکس دانشجو', 'admin', [
            'request_id' => $requestId,
            'student_id' => $request['student_id'],
            'first_name' => $request['first_name'],
            'last_name' => $request['last_name']
        ]);

        // Send push notification to student
        send_push_to_user(
            $pdo,
            $request['student_id'],
            'عکس تأیید شد ✅',
            'عکس ارسالی شما توسط مدیر سیستم تأیید و در پروفایل شما ثبت شد.',
            ['type' => 'photo_approved', 'student_id' => $request['student_id']]
        );

        echo json_encode([
            'success' => true,
            'message' => 'عکس دانشجو با موفقیت به‌روزرسانی شد'
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // Reject - just update status and delete file
        $stmt = $pdo->prepare("UPDATE photo_update_requests SET status = 'rejected', reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$requestId]);

        // Delete the uploaded file
        @unlink($newPhotoPath);

        // Audit log
        audit_log($pdo, 'PHOTO_REQUEST_REJECTED', 'رد درخواست عکس دانشجو', 'admin', [
            'request_id' => $requestId,
            'student_id' => $request['student_id'],
            'first_name' => $request['first_name'],
            'last_name' => $request['last_name']
        ]);

        // Send push notification to student
        send_push_to_user(
            $pdo,
            $request['student_id'],
            'عکس رد شد ❌',
            'عکس ارسالی شما توسط مدیر سیستم رد شد. لطفاً عکس مناسب‌تری ارسال کنید.',
            ['type' => 'photo_rejected', 'student_id' => $request['student_id']]
        );

        echo json_encode([
            'success' => true,
            'message' => 'درخواست رد شد'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    error_log('Photo review error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'خطا در پردازش درخواست']);
}
