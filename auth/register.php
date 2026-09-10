<?php
/**
 * PixelHop - User Registration API
 * POST /auth/register.php
 *
 * Validates email, hashes password with Argon2id, prevents duplicates
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

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

// Get input (JSON or form data)
$input = getInput();

$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';
// Accept CSRF token from JSON body, form POST, or X-CSRF-Token header.
$csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$turnstileToken = $input['cf-turnstile-response'] ?? '';

// Validate CSRF for ALL POST requests, including JSON submissions.
$sessionCsrfToken = $_SESSION['csrf_token'] ?? '';
if ($sessionCsrfToken === '' || $csrfToken === '' || !hash_equals($sessionCsrfToken, $csrfToken)) {
    jsonResponse(false, 'Invalid security token. Please refresh and try again.', 403);
}

// Verify Turnstile
$turnstileResult = Turnstile::verify($turnstileToken);
if (!$turnstileResult['success']) {
    jsonResponse(false, $turnstileResult['message'], 400);
}

// Validate email
if (empty($email)) {
    jsonResponse(false, 'Email is required', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Invalid email format', 400);
}

if (strlen($email) > 255) {
    jsonResponse(false, 'Email is too long', 400);
}

// Validate password
if (empty($password)) {
    jsonResponse(false, 'Password is required', 400);
}

if (strlen($password) < 8) {
    jsonResponse(false, 'Password must be at least 8 characters', 400);
}

if (strlen($password) > 128) {
    jsonResponse(false, 'Password is too long', 400);
}

// Check password complexity
if (!preg_match('/[A-Z]/', $password)) {
    jsonResponse(false, 'Password must contain at least one uppercase letter', 400);
}

if (!preg_match('/[a-z]/', $password)) {
    jsonResponse(false, 'Password must contain at least one lowercase letter', 400);
}

if (!preg_match('/[0-9]/', $password)) {
    jsonResponse(false, 'Password must contain at least one number', 400);
}

// Confirm password match
if ($password !== $confirmPassword) {
    jsonResponse(false, 'Passwords do not match', 400);
}

try {
    // Include Mailer for email verification
    require_once __DIR__ . '/../includes/Mailer.php';

    $existingUser = Database::fetchOne(
        'SELECT id, email_verified FROM users WHERE email = ?',
        [$email]
    );

    if ($existingUser) {
        // If user exists but not verified, allow re-sending verification
        if (!$existingUser['email_verified']) {
            // Generate new verification token
            $verificationToken = bin2hex(random_bytes(32));
            $verificationExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            Database::execute(
                'UPDATE users SET email_verification_token = ?, email_verification_expires = ? WHERE id = ?',
                [$verificationToken, $verificationExpires, $existingUser['id']]
            );
            
            // Send verification email
            $mailer = new Mailer();
            $mailer->sendVerificationEmail($email, $verificationToken);
            
            jsonResponse(true, 'Verification email resent. Please check your inbox.', 200, [
                'require_verification' => true,
                'redirect' => '/auth/verify-pending.php'
            ]);
        }
        jsonResponse(false, 'An account with this email already exists', 409);
    }


    $passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1
    ]);

    if ($passwordHash === false) {
        throw new Exception('Password hashing failed');
    }

    // Generate verification token
    $verificationToken = bin2hex(random_bytes(32));
    $verificationExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $userId = Database::insert(
        'INSERT INTO users (email, password_hash, role, email_verified, email_verification_token, email_verification_expires, created_at) 
         VALUES (?, ?, ?, 0, ?, ?, NOW())',
        [$email, $passwordHash, 'user', $verificationToken, $verificationExpires]
    );

    if (!$userId) {
        throw new Exception('Failed to create user');
    }

    // Send verification email
    $mailer = new Mailer();
    $emailSent = $mailer->sendVerificationEmail($email, $verificationToken);
    
    if (!$emailSent) {
        error_log("Failed to send verification email to: $email");
    }

    // Don't auto-login - require verification first
    jsonResponse(true, 'Account created! Please check your email to verify your account.', 201, [
        'require_verification' => true,
        'redirect' => '/auth/verify-pending.php'
    ]);

} catch (PDOException $e) {
    error_log('Registration DB error: ' . $e->getMessage());
    jsonResponse(false, 'Database error. Please try again later.', 500);
} catch (Exception $e) {
    error_log('Registration error: ' . $e->getMessage());
    jsonResponse(false, 'Registration failed. Please try again.', 500);
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
