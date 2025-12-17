<?php
/**
 * PixelHop - Authentication Middleware
 * Check if user is logged in, redirect to login if not
 *
 * Usage: require_once __DIR__ . '/auth/middleware.php';
 */

// Start secure session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Session regeneration for security (every 30 min)
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

/**
 * Check if user is authenticated
 */
function isAuthenticated(): bool
{
    return isset($_SESSION['user_id']) &&
           isset($_SESSION['user_email']) &&
           !empty($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function isAdmin(): bool
{
    return isAuthenticated() &&
           isset($_SESSION['user_role']) &&
           $_SESSION['user_role'] === 'admin';
}

/**
 * Get current user ID
 */
function getCurrentUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user email
 */
function getCurrentUserEmail(): ?string
{
    return $_SESSION['user_email'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user data from database
 */
function getCurrentUser(): ?array
{
    if (!isAuthenticated()) {
        return null;
    }

    static $user = null;

    if ($user === null) {
        try {
            require_once __DIR__ . '/../includes/Database.php';
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log('getCurrentUser error: ' . $e->getMessage());
            return null;
        }
    }

    return $user;
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireAuth(string $redirectTo = '/login.php'): void
{
    if (!isAuthenticated()) {

        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * Require admin role
 */
function requireAdmin(string $redirectTo = '/'): void
{
    requireAuth();
    if (!isAdmin()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * Redirect if already authenticated (for login/register pages)
 */
function redirectIfAuthenticated(string $redirectTo = '/'): void
{
    if (isAuthenticated()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * Set user session after successful login
 */
function setUserSession(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_regeneration'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Destroy user session (logout)
 */
function destroyUserSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Generate CSRF token
 */
function generateCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken(?string $token): bool
{
    if (!$token || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF input field HTML
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}
