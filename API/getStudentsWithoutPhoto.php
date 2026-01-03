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
        // Sort by student_id with priority for older students (89, 88, etc. first)
        usort($studentsWithoutPhoto, function ($a, $b) {
            $idA = $a['student_id'];
            $idB = $b['student_id'];

            // Extract first two digits (year code)
            $yearA = intval(substr($idA, 0, 2));
            $yearB = intval(substr($idB, 0, 2));

            // Years starting with 8 or 9 are older (1389, 1388, etc.)
            // Years starting with 0, 1, 2, 3, 4 are newer (1400, 1401, etc.)
            $isOldA = $yearA >= 80;
            $isOldB = $yearB >= 80;

            // Old students (80-99) come before new students (00-79)
            if ($isOldA && !$isOldB)
                return -1;
            if (!$isOldA && $isOldB)
                return 1;

            // If both are old or both are new, sort by year descending then by full ID
            if ($isOldA && $isOldB) {
                // Both old: lower number = older (89 < 98 means 1389 < 1398)
                if ($yearA !== $yearB)
                    return $yearA - $yearB;
            } else {
                // Both new: lower number = older (00 < 04 means 1400 < 1404)
                if ($yearA !== $yearB)
                    return $yearA - $yearB;
            }

            // Same year, sort by full student_id
            return strcmp($idA, $idB);
        });
        $response['students'] = $studentsWithoutPhoto;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در دریافت اطلاعات دانشجویان']);
}
