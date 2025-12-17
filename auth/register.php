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

// Get input (JSON or form data)
$input = getInput();

$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';
$csrfToken = $input['csrf_token'] ?? '';
$turnstileToken = $input['cf-turnstile-response'] ?? '';

// Validate CSRF for form submissions
if (!empty($_POST) && !validateCsrfToken($csrfToken)) {
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

    $existingUser = Database::fetchOne(
        'SELECT id FROM users WHERE email = ?',
        [$email]
    );

    if ($existingUser) {
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


    $userId = Database::insert(
        'INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, ?, NOW())',
        [$email, $passwordHash, 'user']
    );

    if (!$userId) {
        throw new Exception('Failed to create user');
    }


    $user = Database::fetchOne(
        'SELECT id, email, role FROM users WHERE id = ?',
        [$userId]
    );

    setUserSession($user);


    Database::execute(
        'UPDATE users SET last_login = NOW() WHERE id = ?',
        [$userId]
    );

    jsonResponse(true, 'Registration successful', 201, [
        'user' => [
            'id' => $userId,
            'email' => $email,
            'role' => 'user'
        ],
        'redirect' => $_SESSION['redirect_after_login'] ?? '/'
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
