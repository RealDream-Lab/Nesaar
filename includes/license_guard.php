<?php
/**
 * Global license guard to enforce license validation for every request.
 *
 * This file exposes helper functions for API endpoints and page controllers
 * to ensure no content is served when the license is invalid or the
 * verification cannot be performed within the defined grace period.
 */

declare(strict_types=1);

require_once __DIR__ . '/../API/db_init.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/audit_log.php';

const LICENSE_WEBHOOK_URL = 'https://wfa.pnubijar.ac.ir/webhook/LC';
const LICENSE_GRACE_PERIOD_SECONDS = 24 * 60 * 60; // 24 hours from last success
const LICENSE_REVALIDATE_TRIAL_SECONDS = 15 * 60; // revalidate trials at least every 15 minutes
const LICENSE_REVALIDATE_DAY_SECONDS = 24 * 60 * 60; // for permanent licenses revalidate daily

/**
 * Enforce license validity for API endpoints. Terminates the request with a
 * 403 JSON response when the license is not valid.
 */
function license_guard_enforce_api(): array
{
    $result = license_guard_validate();

    if ($result['valid'] === true) {
        return $result;
    }

    license_guard_respond_forbidden($result['message'] ?? 'License validation failed');
}

/**
 * Validates the license and returns the status details.
 *
 * @return array{
 *     valid: bool,
 *     message: string,
 *     licenceType?: string|null,
 *     usedCache?: bool,
 *     graceUntil?: string|null
 * }
 */
function license_guard_validate(bool $forceRefresh = false): array
{
    global $pdo;

    if (!($pdo instanceof PDO)) {
        return [
            'valid' => false,
            'message' => 'Database connection not available'
        ];
    }
    
    // Check if system is initialized
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'IsInit'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $isInit = $row ? $row['ConfigValue'] : 'NO';
    
    // If not initialized, allow access (setup mode)
    if ($isInit !== 'YES') {
        return [
            'valid' => true,
            'message' => 'System in setup mode',
            'setupMode' => true
        ];
    }
    
    $config = license_guard_fetch_config($pdo);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $nowString = $now->format('Y-m-d H:i:s');

    $lastStatus = $config['LicenseLastStatus'] ?? null;
    $lastSuccessRaw = $config['LicenseLastSuccess'] ?? $config['LicenseLastSuccessCheck'] ?? null;
    $lastSuccess = license_guard_parse_datetime($lastSuccessRaw, $now->getTimezone());
    $lastChecked = license_guard_parse_datetime($config['LicenseLastChecked'] ?? null, $now->getTimezone());
    $currentType = $config['LicenseCurrentType'] ?? null;

    // Remove legacy expiry entry if it still exists.
    license_guard_upsert_config($pdo, 'LicenseExpiry', null);

    // If we have a recent successful check, respect it according to cache rules.
    // این قبل از rate limiting چک می‌شود تا کاربران عادی محدود نشوند
    if (!$forceRefresh && $lastStatus === 'valid') {
        $isPermanent = ($currentType === 'permanent');
        $revalidateWindow = $isPermanent
            ? LICENSE_REVALIDATE_DAY_SECONDS
            : LICENSE_REVALIDATE_TRIAL_SECONDS;

        if ($lastChecked instanceof DateTimeImmutable) {
            $age = $now->getTimestamp() - $lastChecked->getTimestamp();
            if ($age <= $revalidateWindow) {
                // No need to update LicenseLastChecked - just return cached result
                return [
                    'valid' => true,
                    'message' => $isPermanent
                        ? 'Permanent license is valid (cached)'
                        : 'Trial license is valid (cached)',
                    'licenceType' => $currentType ?? 'trial',
                    'usedCache' => true
                ];
            }
        }
    }
    
    // Rate limiting: فقط برای درخواست‌های جدید (غیر cache شده)
    // 100 درخواست در هر 60 ثانیه از هر IP
    if (rate_limit_check($pdo, 'license_validation', 100, 60)) {
        return [
            'valid' => false,
            'message' => 'تعداد درخواست‌های بررسی لایسنس بیش از حد مجاز است'
        ];
    }

    // Grace period when remote checks fail but we had success within the last 24 hours
    if (!$forceRefresh && $lastSuccess instanceof DateTimeImmutable) {
        $secondsSinceSuccess = $now->getTimestamp() - $lastSuccess->getTimestamp();
        if ($secondsSinceSuccess <= LICENSE_GRACE_PERIOD_SECONDS) {
            // Only allow grace if prior status was valid or error (not explicit invalid)
            if ($lastStatus === 'valid' || $lastStatus === 'error') {
                $graceUntil = $lastSuccess->modify('+' . LICENSE_GRACE_PERIOD_SECONDS . ' seconds');
                license_guard_upsert_config($pdo, 'LicenseLastChecked', $nowString);
                return [
                    'valid' => true,
                    'message' => 'Grace period is active based on last successful validation',
                    'licenceType' => $currentType ?? 'trial',
                    'usedCache' => true,
                    'graceUntil' => $graceUntil->format('Y-m-d H:i:s')
                ];
            }
        }
    }

    // Perform fresh validation
    license_guard_upsert_config($pdo, 'LicenseLastChecked', $nowString);

    $token = $config['LicenseToken'] ?? null;
    if (!$token) {
        license_guard_upsert_config($pdo, 'LicenseLastStatus', 'invalid');
        return [
            'valid' => false,
            'message' => 'License token is missing'
        ];
    }

    $webhookData = license_guard_call_webhook($token);

    if (!$webhookData['success']) {
        // Remote error: record status and fall back to grace (if any)
        license_guard_upsert_config($pdo, 'LicenseLastStatus', 'error');

        if ($lastSuccess instanceof DateTimeImmutable) {
            $secondsSinceSuccess = $now->getTimestamp() - $lastSuccess->getTimestamp();
            if ($secondsSinceSuccess <= LICENSE_GRACE_PERIOD_SECONDS) {
                $graceUntil = $lastSuccess->modify('+' . LICENSE_GRACE_PERIOD_SECONDS . ' seconds');
                return [
                    'valid' => true,
                    'message' => 'Grace period is active (webhook unavailable)',
                    'licenceType' => $currentType ?? 'trial',
                    'usedCache' => true,
                    'graceUntil' => $graceUntil->format('Y-m-d H:i:s')
                ];
            }
        }

        return [
            'valid' => false,
            'message' => $webhookData['message']
        ];
    }

    $licenceType = $webhookData['licenceType'];
    $expiryString = $webhookData['expiry'];

    if ($licenceType === 'permanent') {
        license_guard_upsert_config($pdo, 'LicenseLastStatus', 'valid');
        license_guard_upsert_config($pdo, 'LicenseLastSuccess', $nowString);
        license_guard_upsert_config($pdo, 'LicenseCurrentType', 'permanent');
        
        // Audit log
        audit_log_license($pdo, 'webhook_check', 'valid', [
            'license_type' => 'permanent',
            'used_cache' => false
        ]);

        return [
            'valid' => true,
            'message' => 'Permanent license validated successfully',
            'licenceType' => 'permanent',
            'usedCache' => false
        ];
    }

    if ($licenceType === 'trial' && $expiryString) {
        $expiryDate = license_guard_parse_datetime($expiryString, $now->getTimezone());
        if (!$expiryDate || $expiryDate <= $now) {
            license_guard_upsert_config($pdo, 'LicenseLastStatus', 'invalid');
            license_guard_upsert_config($pdo, 'LicenseCurrentType', 'trial');
            
            // Audit log
            audit_log_license($pdo, 'webhook_check', 'expired', [
                'license_type' => 'trial',
                'expiry' => $expiryString
            ]);
            
            return [
                'valid' => false,
                'message' => 'دوره آزمایشی پایان یافته، در صورتی که کاربر این سامانه هستید لطفاً به ادمین اطلاع دهید تا نسبت به فعال‌سازی لایسنس اقدام نماید.'
            ];
        }

        license_guard_upsert_config($pdo, 'LicenseLastStatus', 'valid');
        license_guard_upsert_config($pdo, 'LicenseLastSuccess', $nowString);
        license_guard_upsert_config($pdo, 'LicenseCurrentType', 'trial');
        
        // Audit log
        audit_log_license($pdo, 'webhook_check', 'valid', [
            'license_type' => 'trial',
            'expiry' => $expiryString,
            'used_cache' => false
        ]);

        return [
            'valid' => true,
            'message' => 'Trial license validated successfully',
            'licenceType' => 'trial',
            'usedCache' => false
        ];
    }

    license_guard_upsert_config($pdo, 'LicenseLastStatus', 'invalid');
    license_guard_upsert_config($pdo, 'LicenseCurrentType', null);
    
    // Audit log
    audit_log_license($pdo, 'webhook_check', 'invalid', [
        'reason' => 'Invalid license type received from server'
    ]);

    return [
        'valid' => false,
        'message' => 'Invalid license type received from server'
    ];
}

/**
 * Fetches the license-related configuration items in a single query.
 */
function license_guard_fetch_config(PDO $pdo): array
{
    $keys = [
        'LicenseLastStatus',
        'LicenseLastSuccess',
        'LicenseLastSuccessCheck',
        'LicenseLastChecked',
        'LicenseCurrentType',
        'LicenseToken'
    ];

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT ConfigName, ConfigValue FROM Config WHERE ConfigName IN ($placeholders)");
    $stmt->execute($keys);

    $config = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['ConfigName']] = $row['ConfigValue'];
    }

    return $config;
}

/**
 * Inserts or updates a configuration value.
 */
function license_guard_upsert_config(PDO $pdo, string $key, ?string $value): void
{
    if ($value === null) {
        $stmt = $pdo->prepare('DELETE FROM Config WHERE ConfigName = ?');
        $stmt->execute([$key]);
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO Config (ConfigName, ConfigValue)
        VALUES (:name, :value)
        ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)
    ');
    $stmt->execute([
        'name' => $key,
        'value' => $value
    ]);
}

/**
 * Parse database datetime string safely.
 */
function license_guard_parse_datetime(?string $value, DateTimeZone $tz): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }

    $dtAlt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
    return $dtAlt ?: null;
}

/**
 * Calls the remote license webhook.
 */
function license_guard_call_webhook(string $token): array
{
    // نکته امنیتی: ترجیحاً token باید در header ارسال شود، اما webhook فعلی از query string استفاده می‌کند
    // TODO: هماهنگی با تیم backend برای تغییر به header-based authentication
    $url = LICENSE_WEBHOOK_URL . '?LicenseToken=' . urlencode($token);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'header' => "Accept: application/json\r\n",
            'ignore_errors' => false
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false
        ]
    ]);

    $response = false;
    $error = null;
    
    try {
        $response = file_get_contents($url, false, $context);
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("License webhook call failed: " . $error);
    }
    
    if ($response === false) {
        return [
            'success' => false,
            'message' => 'Unable to reach license server' . ($error ? ": $error" : '')
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'message' => 'Invalid response from license server'
        ];
    }

    $licenceType = $decoded['LicenceType'] ?? '';
    $expiry = $decoded['Exp'] ?? null;

    if ($licenceType === 'Licenced') {
        return [
            'success' => true,
            'licenceType' => 'permanent',
            'expiry' => null
        ];
    }

    if ($licenceType === 'FullLicenced') {
        return [
            'success' => true,
            'licenceType' => 'trial',
            'expiry' => $expiry
        ];
    }

    return [
        'success' => false,
        'message' => 'License server returned an unexpected type'
    ];
}

/**
 * Request automatic trial license from server
 */
/**
 * Sends a JSON 403 response and terminates execution.
 */
function license_guard_respond_forbidden(string $message): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'license_forbidden',
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
