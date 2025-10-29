<?php
header('Content-Type: application/json; charset=utf-8');

// Simple progress reader for processExcelToTemp.php
// Expects GET parameter: filename

$filename = $_GET['filename'] ?? '';
if (empty($filename)) {
    http_response_code(400);
    echo json_encode(['error' => 'filename required']);
    exit;
}

$progressFile = __DIR__ . '/../database/progress_' . basename($filename) . '.json';
if (!file_exists($progressFile)) {
    // Not found yet - return not found but OK structure
    echo json_encode(['stage' => 'pending', 'totalRows' => 0, 'processedRows' => 0, 'message' => 'pending']);
    exit;
}

$data = @file_get_contents($progressFile);
if ($data === false) {
    http_response_code(500);
    echo json_encode(['error' => 'could not read progress']);
    exit;
}

$payload = json_decode($data, true);
if (!is_array($payload)) {
    http_response_code(500);
    echo json_encode(['error' => 'invalid progress file']);
    exit;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);

?>
