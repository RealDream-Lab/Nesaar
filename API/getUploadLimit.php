<?php
require_once __DIR__ . '/../includes/license_guard.php';

// Enforce license protection
license_guard_enforce_api();

// Set response header
header('Content-Type: application/json; charset=utf-8');

// Helper function to get max upload size from PHP configuration
function getMaxUploadSize() {
    $upload_max = ini_get('upload_max_filesize');
    $post_max = ini_get('post_max_size');
    
    // Convert to bytes
    $upload_max_bytes = convertToBytes($upload_max);
    $post_max_bytes = convertToBytes($post_max);
    
    // Return the smaller of the two
    return min($upload_max_bytes, $post_max_bytes);
}

function convertToBytes($value) {
    $value = trim($value);
    $unit = strtolower($value[strlen($value) - 1]);
    $number = (int)$value;
    
    switch($unit) {
        case 'g':
            $number *= 1024;
        case 'm':
            $number *= 1024;
        case 'k':
            $number *= 1024;
    }
    
    return $number;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 0) . ' گیگابایت';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 0) . ' مگابایت';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' کیلوبایت';
    } else {
        return $bytes . ' بایت';
    }
}

$maxSize = getMaxUploadSize();

echo json_encode([
    'maxSize' => $maxSize,
    'maxSizeFormatted' => formatBytes($maxSize),
    'uploadMaxFilesize' => ini_get('upload_max_filesize'),
    'postMaxSize' => ini_get('post_max_size')
]);
