<?php
/**
 * Internal Authentication Helper
 * 
 * برای محافظت از APIهایی که فقط باید توسط سرور داخلی فراخوانی شوند
 */

declare(strict_types=1);
const INTERNAL_AUTH_TOKEN_FILE = __DIR__ . '/../database/internal_api_token.secret';

/**
 * Retrieve path where the plaintext internal token is stored for local use
 */
function internal_auth_token_file(): string
{
    return INTERNAL_AUTH_TOKEN_FILE;
}

/**
 * Load hashed token from Config table (if present)
 */
function internal_auth_get_hash(PDO $pdo): ?string
{
    $stmt = $pdo->prepare("SELECT ConfigValue FROM Config WHERE ConfigName = 'InternalAPIToken'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $hash = trim((string)($row['ConfigValue'] ?? ''));
    return $hash !== '' ? $hash : null;
}

/**
 * Generate a secure internal API token
 * این تابع یک بار اجرا می‌شود و token را در Config ذخیره می‌کند
 */
function internal_auth_generate_token(PDO $pdo): string
{
    $token = bin2hex(random_bytes(32)); // 64 character hex string
    $hash = hash('sha256', $token);
    
    $stmt = $pdo->prepare('
        INSERT INTO Config (ConfigName, ConfigValue)
        VALUES (:name, :value)
        ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)
    ');
    $stmt->execute([
        'name' => 'InternalAPIToken',
        'value' => $hash
    ]);

    $path = internal_auth_token_file();
    try {
        file_put_contents($path, $token . "\n", LOCK_EX);
        @chmod($path, 0600);
    } catch (Throwable $e) {
        error_log('Failed to persist Internal API token file: ' . $e->getMessage());
    }
    
    return $token;
}

/**
 * Get or create internal API token
 */
function internal_auth_get_token(PDO $pdo): string
{
    $path = internal_auth_token_file();
    if (is_readable($path)) {
        $token = trim((string)@file_get_contents($path));
        if ($token !== '') {
            return $token;
        }
    }

    return internal_auth_generate_token($pdo);
}

/**
 * Validate internal API request
 * بررسی می‌کند که درخواست از سرور داخلی است یا نه
 */
function internal_auth_validate(): bool
{
    global $pdo;
    
    if (!($pdo instanceof PDO)) {
        return false;
    }
    
    // روش ۱: بررسی Internal Token در Header
    $providedToken = $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';
    if (!empty($providedToken)) {
        $storedHash = internal_auth_get_hash($pdo);
        if ($storedHash !== null) {
            $computed = hash('sha256', $providedToken);
            if (hash_equals($storedHash, $computed)) {
                return true;
            }
            if (strlen($storedHash) === 64 && ctype_xdigit($storedHash) && hash_equals($storedHash, $providedToken)) {
                return true;
            }
        }
    }
    
    // روش ۲: بررسی اینکه درخواست از localhost باشد
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIPs = ['127.0.0.1', '::1', 'localhost'];
    
    if (in_array($remoteAddr, $allowedIPs, true)) {
        return true;
    }
    
    // روش ۳: بررسی server-to-server call (same process)
    if (isset($_SERVER['HTTP_X_INTERNAL_CALL']) && $_SERVER['HTTP_X_INTERNAL_CALL'] === 'true') {
        return true;
    }
    
    return false;
}

/**
 * Enforce internal authentication
 * اگر درخواست از خارج باشد، با 403 رد می‌شود
 */
function internal_auth_enforce(): void
{
    if (!internal_auth_validate()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'forbidden',
            'message' => 'This endpoint is for internal use only'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Make an internal API call with proper authentication
 */
function internal_auth_call(string $endpoint, array $data = []): array
{
    global $pdo;
    
    if (!($pdo instanceof PDO)) {
        return ['success' => false, 'error' => 'Database not available'];
    }
    
    $token = internal_auth_get_token($pdo);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Internal-Token: ' . $token,
            'X-Internal-Call: true'
        ],
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        return ['success' => false, 'error' => 'Internal API call failed'];
    }
    
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'error' => 'Invalid response'];
}

?>
