<?php
/**
 * API to get students without photos
 * Returns count and optionally full list of students
 */

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once 'db_init.php';

header('Content-Type: application/json; charset=utf-8');

license_guard_enforce_api();

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

// Photo directory (do not rely on realpath when folder may not exist)
$photoDir = __DIR__ . '/../pic/' . $saadCode;

// Check if we need full list or just count
$fullList = isset($_GET['full']) && $_GET['full'] === 'true';

try {
    // Get all unique students from database
    $stmt     = $pdo->query("SELECT DISTINCT student_id, first_name, last_name FROM students ORDER BY last_name, first_name");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStudents        = count($students);
    $studentsWithoutPhoto = [];
    $countWithoutPhoto    = 0;

    foreach ($students as $student) {
        $photoPath = $photoDir . '/' . $student['student_id'] . '.jpg';

        if (!file_exists($photoPath)) {
            $countWithoutPhoto++;
            if ($fullList) {
                $studentsWithoutPhoto[] = [
                    'student_id' => $student['student_id'],
                    'first_name' => $student['first_name'],
                    'last_name' => $student['last_name']
                ];
            }
        }
    }

    $response = [
        'success' => true,
        'totalStudents' => $totalStudents,
        'withoutPhoto' => $countWithoutPhoto,
        'withPhoto' => $totalStudents - $countWithoutPhoto
    ];

    if ($fullList) {
        // Sort by last_name, then first_name
        usort($studentsWithoutPhoto, function ($a, $b) {
            $cmp = strcmp($a['last_name'], $b['last_name']);
            if ($cmp === 0) {
                return strcmp($a['first_name'], $b['first_name']);
            }
            return $cmp;
        });
        $response['students'] = $studentsWithoutPhoto;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در دریافت اطلاعات دانشجویان']);
}
