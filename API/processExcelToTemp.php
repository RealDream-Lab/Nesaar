<?php
// Start session before any output or headers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../vendor/autoload.php'; // For OpenSpout

use OpenSpout\Reader\XLSX\Reader as XLSXReader;
use OpenSpout\Reader\XLS\Reader as XLSReader;

// Enforce license and CSRF protection
// license_guard_enforce_api(); // Temporarily disabled for testing
// csrf_enforce(); // Temporarily disabled for testing

// Set response header
header('Content-Type: application/json; charset=utf-8');

// Check if admin is authenticated
// $adminSession = $_COOKIE['adminSession'] ?? null;
// if (!$adminSession) {
//     http_response_code(401);
//     echo json_encode(['error' => 'دسترسی غیرمجاز']);
//     exit;
// }

// try {
//     $session = json_decode(urldecode($adminSession), true);
//     if (!$session || ($session['type'] ?? '') !== 'admin') {
//         http_response_code(401);
//         echo json_encode(['error' => 'دسترسی غیرمجاز']);
//         exit;
//     }
// } catch (Exception $e) {
//     http_response_code(401);
//     echo json_encode(['error' => 'دسترسی غیرمجاز']);
//     exit;
// }

// Get exam type and filename
$examType = $_POST['examType'] ?? '';
$filename = $_POST['filename'] ?? '';

if (!in_array($examType, ['K', 'E'])) {
    http_response_code(400);
    echo json_encode(['error' => 'نوع آزمون نامعتبر است']);
    exit;
}

if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'نام فایل مشخص نشده است']);
    exit;
}

// Check if file exists
$filePath = __DIR__ . '/../database/' . $filename;
if (!file_exists($filePath)) {
    http_response_code(400);
    echo json_encode(['error' => 'فایل یافت نشد']);
    exit;
}

// Get file extension
$fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

// Process the Excel file
try {
    // Create reader based on file type
    if ($fileExtension === 'xlsx') {
        $reader = new XLSXReader();
    } elseif ($fileExtension === 'xls') {
        $reader = new XLSReader();
    } else {
        throw new Exception('Unsupported file type');
    }
    $reader->open($filePath);
    
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $cells = $row->getCells();
            $rowData = [];
            foreach ($cells as $cell) {
                $rowData[] = $cell->getValue();
            }
            $rows[] = $rowData;
        }
        break; // Only first sheet
    }
    $reader->close();
    
    if (empty($rows)) {
        http_response_code(400);
        echo json_encode(['error' => 'فایل اکسل خالی است یا قابل خواندن نیست']);
        exit;
    }
    
    // Fixed columns for temp table
    $columns = [
        'شماره دانشجويي' => 'VARCHAR(9)',
        'شماره شناسنامه' => 'VARCHAR(10)',
        'مرکز مبدا' => 'VARCHAR(4)',
        'مرکز مقصد' => 'VARCHAR(4)',
        'نام' => 'VARCHAR(50)',
        'نام خانوادگي' => 'VARCHAR(50)',
        'مدرک' => 'VARCHAR(15)',
        'کد درس' => 'VARCHAR(7)',
        'نام درس' => 'VARCHAR(100)',
        'تاريخ آزمون' => 'VARCHAR(10)',
        'ساعت آزمون' => 'VARCHAR(5)',
        'شماره صندلي' => 'VARCHAR(6)',
        'نوع آزمون' => 'VARCHAR(20)',
        'نوع درس' => 'VARCHAR(20)',
        'ساختمان' => 'VARCHAR(50)',
        'کلاس' => 'VARCHAR(50)',
        'ردیف' => 'VARCHAR(50)'
    ];
    
    // Connect to database
    require_once 'db_init.php';
    
    // Drop temp table if exists
    $pdo->query("DROP TABLE IF EXISTS temp");
    
    // Create temp table with fixed schema
    $createSql = "CREATE TABLE temp (";
    $columnDefs = [];
    foreach ($columns as $name => $type) {
        $columnDefs[] = "`" . str_replace('`', '``', $name) . "` " . $type . " CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL";
    }
    $createSql .= implode(', ', $columnDefs) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if (!$pdo->query($createSql)) {
        throw new Exception('خطا در ایجاد جدول موقت: ' . $pdo->error);
    }
    
    // Insert data
    $insertedRows = 0;
    $columnNames = array_keys($columns);
    $stmt = $pdo->prepare("INSERT INTO temp (" . implode(',', array_map(fn($n) => "`" . str_replace('`', '``', $n) . "`", $columnNames)) . ") VALUES (" . str_repeat('?,', count($columnNames)-1) . "?)");
    foreach ($rows as $rowIndex => $row) {
        if ($rowIndex == 0) continue; // Skip header if present
        
        $values = [];
        for ($i = 0; $i < count($columnNames); $i++) {
            $value = $row[$i] ?? null;
            if ($value === null || $value === '') {
                $values[] = null;
            } elseif ($value instanceof \DateTimeInterface) {
                // For ساعت آزمون (index 10), format as H:i, else Y-m-d
                if ($i == 10) {
                    $values[] = $value->format('H:i');
                } else {
                    $values[] = $value->format('Y-m-d');
                }
            } else {
                $strValue = (string)$value;
                // Convert Arabic characters to Persian
                $strValue = str_replace(['ك', 'ي'], ['ک', 'ی'], $strValue);
                // For ساعت آزمون (index 10), format as HH:MM from 8-digit string
                if ($i == 10 && strlen($strValue) >= 4) {
                    $strValue = substr($strValue, 0, 2) . ':' . substr($strValue, 2, 2);
                }
                $values[] = $strValue;
            }
        }
        
        if ($stmt->execute($values)) {
            $insertedRows++;
        }
    }
    
    // Log the processing
    $logMessage = date('Y-m-d H:i:s') . " - Excel processed to temp table: {$insertedRows} rows, " . count($columns) . " columns by " . ($session['username'] ?? 'Unknown') . "\n";
    $logFile = __DIR__ . '/../database/excel_log.txt';
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'فایل اکسل با موفقیت پردازش شد',
        'rows' => $insertedRows,
        'columns' => count($columns),
        'examType' => $examType === 'K' ? 'کتبی' : 'الکترونیکی'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در پردازش فایل: ' . $e->getMessage()]);
    exit;
}

?>