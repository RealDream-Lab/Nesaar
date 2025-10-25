<?php
/**
 * CSRF Protection Helper
 * 
 * محافظت در برابر Cross-Site Request Forgery attacks
 */

declare(strict_types=1);

/**
 * Generate CSRF token and store in session
 */
function csrf_generate_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Get current CSRF token
 */
function csrf_get_token(): ?string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return $_SESSION['csrf_token'] ?? null;
}

/**
 * Validate CSRF token from request
 */
function csrf_validate_token(?string $providedToken): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    
    if (!$sessionToken || !$providedToken) {
        return false;
    }
    
    return hash_equals($sessionToken, $providedToken);
}

/**
 * Enforce CSRF protection
 * بررسی می‌کند که token معتبر باشد، در غیر این صورت درخواست را رد می‌کند
 */
function csrf_enforce(): void
{
    // بررسی متد درخواست (فقط برای POST, PUT, DELETE, PATCH)
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        return; // GET و OPTIONS نیاز به CSRF ندارند
    }
    
    // دریافت token از header یا body
    $token = null;
    
    // روش 1: از header
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    
    // روش 2: از POST data
    if (!$token) {
        $token = $_POST['csrf_token'] ?? null;
    }
    
    // روش 3: از JSON body
    if (!$token) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $token = $data['csrf_token'] ?? null;
    }
    
    if (!csrf_validate_token($token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'csrf_validation_failed',
            'message' => 'توکن امنیتی نامعتبر است'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Generate HTML meta tag for CSRF token
 * برای استفاده در head صفحات HTML
 */
function csrf_meta_tag(): string
{
    $token = csrf_generate_token();
    return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Generate hidden input field for forms
 */
function csrf_field(): string
{
    $token = csrf_generate_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Refresh CSRF token (برای امنیت بیشتر بعد از عملیات حساس)
 */
function csrf_refresh_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

?>
