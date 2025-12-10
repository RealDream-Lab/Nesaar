<?php
/**
 * API to get pending photo update requests for admin
 */

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once 'db_init.php';

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

license_guard_enforce_api();

// Rate limiting: 30 requests per minute per IP
rate_limit_enforce($pdo, 'get_photo_requests', 30, 60);

try {
    // Check if table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'photo_update_requests'");
    if ($tableCheck->rowCount() === 0) {
        echo json_encode([
            'success' => true,
            'count' => 0,
            'requests' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Get pending requests
    $stmt     = $pdo->query("SELECT id, student_id, first_name, last_name, filename, created_at 
                         FROM photo_update_requests 
                         WHERE status = 'pending' 
                         ORDER BY created_at DESC");
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get SaadCode for image URLs
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'SaadCode'");
    $stmt->execute();
    $saadCode = trim($stmt->fetchColumn());

    // Add image URLs
    foreach ($requests as &$req) {
        $req['new_photo_url']     = '/pic/' . $saadCode . '/StudentsUpdate/' . $req['filename'];
        $req['current_photo_url'] = '/pic/' . $saadCode . '/' . $req['student_id'] . '.jpg';

        // Check if current photo exists
        $currentPhotoPath         = __DIR__ . '/../pic/' . $saadCode . '/' . $req['student_id'] . '.jpg';
        $req['has_current_photo'] = file_exists($currentPhotoPath);

        // Format date for display (time | date)
        $req['created_at_formatted'] = jdate('H:i', strtotime($req['created_at'])) . ' | ' . jdate('Y/m/d', strtotime($req['created_at']));
    }

    echo json_encode([
        'success' => true,
        'count' => count($requests),
        'requests' => $requests
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در دریافت درخواست‌ها']);
}
