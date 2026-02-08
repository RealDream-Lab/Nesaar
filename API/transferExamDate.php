<?php
/**
 * Transfer (postpone) an exam session to a new date.
 * Moves all courses with a given exam_date + exam_time to a new Jalali date.
 * The exam times remain unchanged.
 *
 * Expects POST JSON:
 *   {
 *     "old_date": "1404/10/25",   // current exam date (Jalali)
 *     "exam_time": "08:00",       // exam time
 *     "new_date": "1404/10/28"    // target date (Jalali)
 *   }
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once 'db_init.php';

csrf_enforce();
license_guard_enforce_api();
$session = admin_session_require($pdo);

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        echo json_encode(['success' => false, 'error' => 'داده‌های ورودی نامعتبر است.']);
        exit;
    }

    $oldDate  = trim($input['old_date'] ?? '');
    $examTime = trim($input['exam_time'] ?? '');
    $newDate  = trim($input['new_date'] ?? '');

    // Validate inputs
    if (empty($oldDate) || empty($examTime) || empty($newDate)) {
        echo json_encode(['success' => false, 'error' => 'تاریخ مبدأ، ساعت آزمون و تاریخ مقصد الزامی هستند.']);
        exit;
    }

    // Validate Jalali date format (YYYY/MM/DD)
    if (
        !preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $oldDate) ||
        !preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $newDate)
    ) {
        echo json_encode(['success' => false, 'error' => 'فرمت تاریخ نامعتبر است. فرمت صحیح: YYYY/MM/DD']);
        exit;
    }

    // Validate time format (HH:MM)
    if (!preg_match('/^\d{2}:\d{2}$/', $examTime)) {
        echo json_encode(['success' => false, 'error' => 'فرمت ساعت نامعتبر است.']);
        exit;
    }

    // Don't allow same date
    if ($oldDate === $newDate) {
        echo json_encode(['success' => false, 'error' => 'تاریخ مقصد نمی‌تواند با تاریخ فعلی یکسان باشد.']);
        exit;
    }

    // Check that courses exist for the old date/time
    $checkStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM courses WHERE exam_date = ? AND exam_time = ?");
    $checkStmt->execute([$oldDate, $examTime]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count === 0) {
        echo json_encode(['success' => false, 'error' => 'هیچ درسی با تاریخ و ساعت مشخص‌شده یافت نشد.']);
        exit;
    }

    // Begin transaction
    $pdo->beginTransaction();

    // 1. Update courses table
    $updateCourses = $pdo->prepare("UPDATE courses SET exam_date = ? WHERE exam_date = ? AND exam_time = ?");
    $updateCourses->execute([$newDate, $oldDate, $examTime]);
    $coursesUpdated = $updateCourses->rowCount();

    // 2. Update ExamsDetil table (if exists)
    $examsDetilUpdated = 0;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'ExamsDetil'");
        if ($checkTable->rowCount() > 0) {
            $updateDetail = $pdo->prepare("UPDATE `ExamsDetil` SET exam_date = ? WHERE exam_date = ? AND exam_time = ?");
            $updateDetail->execute([$newDate, $oldDate, $examTime]);
            $examsDetilUpdated = $updateDetail->rowCount();
        }
    } catch (Throwable $e) {
        // Ignore if table doesn't exist
    }

    // 3. Update ExamAssignments table (if exists)
    $assignmentsUpdated = 0;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'ExamAssignments'");
        if ($checkTable->rowCount() > 0) {
            $updateAssignments = $pdo->prepare("UPDATE `ExamAssignments` SET exam_date = ? WHERE exam_date = ? AND exam_time = ?");
            $updateAssignments->execute([$newDate, $oldDate, $examTime]);
            $assignmentsUpdated = $updateAssignments->rowCount();
        }
    } catch (Throwable $e) {
        // Ignore if table doesn't exist
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "جلسه آزمون با موفقیت از {$oldDate} به {$newDate} منتقل شد.",
        'courses_updated' => $coursesUpdated,
        'details_updated' => $examsDetilUpdated,
        'assignments_updated' => $assignmentsUpdated
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('transferExamDate error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'خطا در انتقال تاریخ آزمون: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
