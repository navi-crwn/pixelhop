<?php
/**
 * PixelHop - User Login API
 * POST /auth/login.php
 *
 * Authenticates user with secure session handling
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', 405);
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Turnstile.php';
require_once __DIR__ . '/middleware.php';

// Get input
$input = getInput();

$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$remember = (bool) ($input['remember'] ?? false);
$csrfToken = $input['csrf_token'] ?? '';
$turnstileToken = $input['cf-turnstile-response'] ?? '';

// Get client IP for rate limiting
$clientIp = getClientIp();

// Validate CSRF for form submissions
if (!empty($_POST) && !validateCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid security token. Please refresh and try again.', 403);
}

// Verify Turnstile
$turnstileResult = Turnstile::verify($turnstileToken);
if (!$turnstileResult['success']) {
    jsonResponse(false, $turnstileResult['message'], 400);
}

// Check rate limiting (max 10 attempts per 15 minutes)
if (isRateLimited($clientIp)) {
    jsonResponse(false, 'Too many login attempts. Please try again in 15 minutes.', 429);
}

// Validate input
if (empty($email)) {
    jsonResponse(false, 'Email is required', 400);
}

if (empty($password)) {
    jsonResponse(false, 'Password is required', 400);
}

try {

    $user = Database::fetchOne(
        'SELECT id, email, password_hash, role, is_blocked, block_reason, locked_until, login_attempts
         FROM users WHERE email = ?',
        [$email]
    );


    logLoginAttempt($clientIp, $email, false);


    if (!$user) {

        password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$fake$fakehash');
        jsonResponse(false, 'Invalid email or password', 401);
    }


    if ($user['is_blocked']) {
        $reason = $user['block_reason'] ?: 'Your account has been suspended';
        jsonResponse(false, $reason, 403);
    }


    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $unlockTime = date('H:i', strtotime($user['locked_until']));
        jsonResponse(false, "Account temporarily locked. Try again after {$unlockTime}.", 403);
    }


    if (!password_verify($password, $user['password_hash'])) {

        $attempts = $user['login_attempts'] + 1;
        $lockUntil = null;


        if ($attempts >= 5) {
            $lockUntil = date('Y-m-d H:i:s', time() + 900);
            $attempts = 0;
        }

        Database::execute(
            'UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?',
            [$attempts, $lockUntil, $user['id']]
        );

        if ($lockUntil) {
            jsonResponse(false, 'Too many failed attempts. Account locked for 15 minutes.', 403);
        }

        jsonResponse(false, 'Invalid email or password', 401);
    }


    if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
        $newHash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1
        ]);
        Database::execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [$newHash, $user['id']]
        );
    }


    Database::execute(
        'UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?',
        [$user['id']]
    );


    logLoginAttempt($clientIp, $email, true);


    setUserSession($user);


    if ($remember) {
        $params = session_get_cookie_params();
        $params['lifetime'] = 86400 * 30;
        setcookie(session_name(), session_id(), [
            'expires' => time() + $params['lifetime'],
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite']
        ]);
    }


    $defaultRedirect = ($user['role'] === 'admin') ? '/admin/dashboard.php' : '/dashboard.php';
    $redirectUrl = $_SESSION['redirect_after_login'] ?? $defaultRedirect;
    unset($_SESSION['redirect_after_login']);

    jsonResponse(true, 'Login successful', 200, [
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ],
        'redirect' => $redirectUrl
    ]);

} catch (PDOException $e) {
    error_log('Login DB error: ' . $e->getMessage());
    jsonResponse(false, 'Database error. Please try again later.', 500);
} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    jsonResponse(false, 'Login failed. Please try again.', 500);
}

/**
 * Get client IP address
 */
function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '0.0.0.0';
}

/**
 * Check if IP is rate limited
 */
function isRateLimited(string $ip): bool
{
    try {
        $cutoff = date('Y-m-d H:i:s', time() - 900);

        $result = Database::fetchOne(
            'SELECT COUNT(*) as attempts FROM login_attempts
             WHERE ip_address = ? AND success = 0 AND attempted_at > ?',
            [$ip, $cutoff]
        );

        return ($result['attempts'] ?? 0) >= 10;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Log login attempt
 */
function logLoginAttempt(string $ip, string $email, bool $success): void
{
    try {
        Database::insert(
            'INSERT INTO login_attempts (ip_address, email, success, attempted_at) VALUES (?, ?, ?, NOW())',
            [$ip, $email, $success ? 1 : 0]
        );
    } catch (Exception $e) {
        error_log('Failed to log login attempt: ' . $e->getMessage());
    }
}

/**
 * Get input from JSON or POST
 */
function getInput(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?: [];
    }

    return $_POST;
}

/**
 * JSON response helper
 */
function jsonResponse(bool $success, ?string $message = null, int $code = 200, array $data = []): void
{
    http_response_code($code);

    $response = ['success' => $success];

    if ($message !== null) {
        $response['message'] = $message;
    }

    if (!empty($data)) {
        $response = array_merge($response, $data);
    }

    echo json_encode($response);
    exit;
}
