<?php
// Return assignments for a given proctor_id with simple past/ upcoming status
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/db_init.php';
require_once __DIR__ . '/jdf.php';

try {
    license_guard_enforce_api();

    $session = admin_session_require($pdo);

    $proctorId = isset($_GET['proctor_id']) ? intval($_GET['proctor_id']) : 0;
    if ($proctorId <= 0) {
        echo json_encode(['error' => 'proctor_id required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // get current server jalali date/time
    $nowDate = jdate('Y/m/d', '', '', 'Asia/Tehran', 'en');
    $nowTime = jdate('H:i:s', '', '', 'Asia/Tehran', 'en');

    $stmt = $pdo->prepare('SELECT exam_date, exam_time FROM `ExamAssignments` WHERE proctor_id = ? ORDER BY exam_date ASC, exam_time ASC');
    $stmt->execute([$proctorId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $d = $r['exam_date'] ?? '';
        $t = $r['exam_time'] ?? '';
        $past = false;
        if ($d < $nowDate) $past = true;
        elseif ($d === $nowDate) {
            // compare times HH:MM
            $slotTime = strlen($t) >= 5 ? $t . ':00' : ($t . ':00');
            if ($slotTime <= $nowTime) $past = true;
        }
        $out[] = ['exam_date' => $d, 'exam_time' => $t, 'past' => $past];
    }

    echo json_encode(['success' => true, 'proctor_id' => $proctorId, 'assignments' => $out], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

?>