<?php
// Usage: php apply_exam_type_update.php
// This script adds exam_type column to exam_seats if missing and alternates values between 'الکترونیکی' and 'کتبی' for existing rows.

require_once __DIR__ . '/../API/db_init.php'; // provides $pdo

try {
    // Check if column exists
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_seats' AND COLUMN_NAME = 'exam_type'");
    $stmt->execute();
    $res = $stmt->fetch();
    if ($res && $res['cnt'] == 0) {
        echo "Adding column exam_type to exam_seats...\n";
        $pdo->exec("ALTER TABLE exam_seats ADD COLUMN exam_type VARCHAR(15) NOT NULL DEFAULT ''");
        echo "Column added.\n";
    } else {
        echo "Column exam_type already exists.\n";
    }

    // Alternate values for existing rows
    echo "Updating existing rows to alternate exam_type values...\n";
    $pdo->beginTransaction();
    // Initialize user variable and update
    $pdo->exec("SET @i := 0");
    $updated = $pdo->exec("UPDATE exam_seats SET exam_type = IF((@i := @i + 1) % 2 = 1, 'الکترونیکی', 'کتبی')");
    $pdo->commit();

    echo "Updated rows: " . ($updated === false ? '0' : $updated) . "\n";

    // Report counts
    $cntStmt = $pdo->query("SELECT exam_type, COUNT(*) AS c FROM exam_seats GROUP BY exam_type");
    $rows = $cntStmt->fetchAll();
    foreach ($rows as $r) {
        echo "{$r['exam_type']} => {$r['c']}\n";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
