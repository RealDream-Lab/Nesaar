<?php
/**
 * Generate a sample Excel file for proctors upload
 * This file creates a downloadable XLSX file with sample data
 */

// Prevent PHP from emitting HTML error pages that break JSON consumers.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../database/api_errors.log');

require_once __DIR__ . '/../vendor/autoload.php';

use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;

try {
    // Create writer (without custom options to avoid readonly property issues)
    $writer = new Writer();

    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="sample_proctors.xlsx"');
    header('Cache-Control: max-age=0');

    $writer->openToFile('php://output');

    // Add header row
    $headerRow = Row::fromValues([
        'جنسیت',
        'نام',
        'نام خانوادگی',
        'شماره ملی',
        'شماره همراه'
    ]);
    $writer->addRow($headerRow);

    // Add sample data rows
    $sampleData = [
        ['زن', 'مریم', 'قربانی', '0012345678', '09121234567'],
        ['زن', 'رویا', 'کاویانی', '0023456789', '09132345678'],
        ['مرد', 'سردار', 'آزمون', '0034567890', '09143456789'],
    ];

    foreach ($sampleData as $rowData) {
        $row = Row::fromValues($rowData);
        $writer->addRow($row);
    }

    $writer->close();

} catch (Throwable $e) {
    error_log('Sample Excel generation error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'خطا در تولید فایل نمونه'], JSON_UNESCAPED_UNICODE);
}
