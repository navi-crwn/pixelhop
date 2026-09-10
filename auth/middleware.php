<?php
/**
 * PixelHop - Authentication Middleware
 * Check if user is logged in, redirect to login if not
 *
 * Usage: require_once __DIR__ . '/auth/middleware.php';
 */

// Mulai sesi aman + regenerasi ID berkala via bootstrap.
// bootstrap.php menangani session_start, cookie parameter, dan regenerasi sesi.
require_once __DIR__ . '/../includes/bootstrap.php';

/**
 * Check if user is authenticated
 *
 * Catatan D1-01: fungsi ini sengaja memanggil enforceAccountStatus() secara
 * lazy agar setiap pengecekan autentikasi ikut memvalidasi status akun &
 * session_version di DB. Pemanggilan dibatasi 1x per request lewat static
 * flag, dan kegagalan DB ditangkap agar middleware tidak berubah menjadi
 * self-DoS (lihat komentar di enforceAccountStatus).
 */
function isAuthenticated(): bool
{
    static $checked = false;

    if (isset($_SESSION['user_id']) &&
        isset($_SESSION['user_email']) &&
        !empty($_SESSION['user_id'])) {
        if (!$checked) {
            $checked = true;
            enforceAccountStatus();
        }
        return true;
    }

    return false;
}

/**
 * Validasi status akun & session_version terhadap DB pada tiap request.
 *
 * Tujuan (D1-01): sesi user harus mati bila admin me-lock/suspend/block/
 * menghapus akun, atau user mengganti password. Sebelumnya middleware hanya
 * membaca $_SESSION sehingga sesi tetap hidup.
 *
 * Keamanan operasional: bila DB error, JANGAN melempar fatal — cukup log dan
 * biarkan request lanjut. Ini mencegah self-DoS: user yang sudah login tidak
 * boleh terkunci dari seluruh aplikasi hanya karena database sedang down.
 * Sesi lama tanpa key session_version tetap dianggap valid (transisi mulus).
 */
function enforceAccountStatus(): void
{
    static $enforced = false;

    if ($enforced) {
        return;
    }
    $enforced = true;

    if (!isAuthenticated()) {
        return;
    }

    try {
        require_once __DIR__ . '/../includes/Database.php';
        $user = Database::fetchOne(
            "SELECT account_status, is_blocked, role, session_version FROM users WHERE id = ?",
            [(int) $_SESSION['user_id']]
        );
    } catch (Throwable $e) {
        // Keputusan: DB down bukan alasan logout massal. Lanjutkan request
        // dengan data sesi yang ada agar aplikasi tetap bisa dipakai.
        error_log('enforceAccountStatus DB error: ' . $e->getMessage());
        return;
    }

    if (!$user) {
        destroyUserSession();
        header('Location: /login.php?error=account_removed');
        exit;
    }

    if (
        ($user['account_status'] ?? null) === 'locked' ||
        ($user['account_status'] ?? null) === 'suspended' ||
        !empty($user['is_blocked'])
    ) {
        destroyUserSession();
        header('Location: /login.php?error=account_locked');
        exit;
    }

    $dbSessionVersion = (int) ($user['session_version'] ?? 1);

    if (isset($_SESSION['session_version'])) {
        if ((int) $_SESSION['session_version'] !== $dbSessionVersion) {
            destroyUserSession();
            header('Location: /login.php?error=session_revoked');
            exit;
        }
    } else {
        // Sesi lama (dibuat sebelum fitur session_version ada): jangan logout
        // massal. Tetapkan versi dari DB dan lanjut.
        $_SESSION['session_version'] = $dbSessionVersion;
    }

    // Sinkronisasi data sesi dengan DB. Role di-refresh agar demote admin
    // berlaku paling lambat pada request berikutnya.
    $_SESSION['session_version'] = $dbSessionVersion;
    $_SESSION['user_role'] = $user['role'] ?? ($_SESSION['user_role'] ?? 'user');
}

/**
 * Increment session_version user agar semua sesi lama menjadi invalid.
 *
 * Dipanggil setelah aksi yang seharusnya memutus sesi lain:
 * block/unblock, ganti role, ganti type, lock/suspend, hapus user,
 * atau user mengganti password.
 */
function incrementSessionVersion(int $userId): void
{
    require_once __DIR__ . '/../includes/Database.php';
    Database::execute("UPDATE users SET session_version = session_version + 1 WHERE id = ?", [$userId]);
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
    $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
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
