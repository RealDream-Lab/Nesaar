<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/license_guard.php';
require_once __DIR__ . '/../includes/csrf_protection.php';
require_once __DIR__ . '/../includes/admin_session.php';
require_once 'db_init.php';

// Only allow admins to fetch SMS credit
license_guard_enforce_api();
$session = admin_session_require($pdo);

try {
    // API key is embedded in code as requested
    $apiKey = 'OqWNSN8PzlWCjHMW9rQq37PUHq4Eb7zTn0g7T5Qdpi6ahgH8';

    $url = 'https://api.sms.ir/v1/credit';
    $ch  = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-KEY: " . $apiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp     = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $httpCode >= 400) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'failed_to_fetch_sms_credit', 'details' => $err ?: $resp], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $parsed = json_decode($resp, true);
    if (!is_array($parsed)) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'invalid_response_from_provider']);
        exit;
    }

    // Common SMS.ir response wraps credit in Data/Data (case variants) or Credit/credit field. Try common keys.
    $credit = null;
    if (isset($parsed['Data'])) {
        $credit = $parsed['Data'];
    } elseif (isset($parsed['data'])) {
        $credit = $parsed['data'];
    } elseif (isset($parsed['credit'])) {
        $credit = $parsed['credit'];
    } elseif (isset($parsed['Credit'])) {
        $credit = $parsed['Credit'];
    }

    echo json_encode(['success' => true, 'raw' => $parsed, 'credit' => $credit], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('getSmsCredit error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'internal_error']);
}

?>