<?php

/**
 * Security Helper Functions
 * Provides additional security hardening measures
 */

/**
 * Sanitize input to prevent XSS attacks
 */
function sanitize_input(string $input): string
{
    return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Validate and sanitize integer input
 */
function validate_int($value, ?int $min = null, ?int $max = null): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    
    $intValue = (int) $value;
    
    if ($min !== null && $intValue < $min) {
        return null;
    }
    
    if ($max !== null && $intValue > $max) {
        return null;
    }
    
    return $intValue;
}

/**
 * Validate string length
 */
function validate_length(string $input, int $min, int $max): bool
{
    $length = mb_strlen($input, 'UTF-8');
    return $length >= $min && $length <= $max;
}

/**
 * Check for SQL injection patterns in input
 */
function detect_sql_injection(string $input): bool
{
    $patterns = [
        '/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|OR|AND)\b/i',
        '/--/',
        '/\/\*/',
        '/\*\/',
        '/;/',
        '/\bEXEC\b/i',
        '/\bXP_CMD\b/i',
        '/\bSP_OACREATE\b/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Rate limiting helper
 */
function check_rate_limit(string $identifier, int $maxAttempts = 5, int $windowSeconds = 60): bool
{
    $key = 'rate_limit_' . md5($identifier);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['attempts' => 0, 'first_attempt' => time()];
    }
    
    $data = $_SESSION[$key];
    $elapsed = time() - $data['first_attempt'];
    
    if ($elapsed > $windowSeconds) {
        $_SESSION[$key] = ['attempts' => 1, 'first_attempt' => time()];
        return true;
    }
    
    if ($data['attempts'] >= $maxAttempts) {
        return false;
    }
    
    $_SESSION[$key]['attempts']++;
    return true;
}

/**
 * Generate secure random token
 */
function generate_secure_token(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

/**
 * Validate CSRF token from request
 */
function validate_csrf_token(string $token): bool
{
    start_app_session();
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Set security headers
 */
function set_security_headers(): void
{
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Enable XSS protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy (basic)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self';");
}

/**
 * Log security events
 */
function log_security_event(string $event, array $context = []): void
{
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'context' => $context,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    ];
    
    $logFile = __DIR__ . '/../logs/security.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    @file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Check if request is from same origin
 */
function is_same_origin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    
    if (empty($origin)) {
        return false;
    }
    
    $parsedOrigin = parse_url($origin);
    return ($parsedOrigin['host'] ?? '') === $host;
}
