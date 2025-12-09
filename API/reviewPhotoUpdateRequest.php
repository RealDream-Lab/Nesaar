<?php
/**
 * API to approve or reject photo update requests
 */

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once 'db_init.php';

header('Content-Type: application/json; charset=utf-8');

license_guard_enforce_api();

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
        // Get the extension from uploaded file
        $pathInfo  = pathinfo($request['filename']);
        $extension = strtolower($pathInfo['extension'] ?? 'jpg');

        // Target path (always save as .jpg in main folder)
        $targetPath = $mainDir . '/' . $request['student_id'] . '.jpg';

        // If uploaded file is PNG, convert to JPG
        if ($extension === 'png') {
            $img = imagecreatefrompng($newPhotoPath);
            if ($img) {
                // Create white background for transparency
                $width  = imagesx($img);
                $height = imagesy($img);
                $jpgImg = imagecreatetruecolor($width, $height);
                $white  = imagecolorallocate($jpgImg, 255, 255, 255);
                imagefill($jpgImg, 0, 0, $white);
                imagecopy($jpgImg, $img, 0, 0, 0, 0, $width, $height);
                imagejpeg($jpgImg, $targetPath, 90);
                imagedestroy($img);
                imagedestroy($jpgImg);
            } else {
                echo json_encode(['success' => false, 'error' => 'خطا در پردازش تصویر']);
                exit;
            }
        } else {
            // Just copy the file
            if (!copy($newPhotoPath, $targetPath)) {
                echo json_encode(['success' => false, 'error' => 'خطا در جایگزینی عکس']);
                exit;
            }
        }

        // Update request status
        $stmt = $pdo->prepare("UPDATE photo_update_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$requestId]);

        // Delete the uploaded file from StudentsUpdate folder
        @unlink($newPhotoPath);

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

        echo json_encode([
            'success' => true,
            'message' => 'درخواست رد شد'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در پردازش درخواست: ' . $e->getMessage()]);
}
