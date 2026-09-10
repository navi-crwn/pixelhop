<?php
/**
 * PixelHop - Resend Verification Email
 * POST /auth/resend-verification.php
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/ClientIp.php';
require_once __DIR__ . '/../includes/JsonStore.php';

/**
 * Read input from JSON body or form POST.
 */
function getResendInput(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        return json_decode($json, true) ?: [];
    }

    return $_POST;
}

/**
 * JSON response helper.
 */
function resendJsonResponse(bool $success, string $message, int $code = 200, array $headers = []): void
{
    http_response_code($code);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

$input = getResendInput();
$csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

// CSRF validation for every POST request (form or JSON).
if (!isset($_SESSION['csrf_token']) || $csrfToken === '' || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    resendJsonResponse(false, 'Invalid security token. Please refresh and try again.', 403);
}

$email = trim($input['email'] ?? $_SESSION['pending_verification_email'] ?? '');

if ($email === '') {
    resendJsonResponse(false, 'Email address required', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    resendJsonResponse(false, 'Invalid email format', 400);
}

// ---------------------------------------------------------------------------
// Server-side per-IP rate limit: max 3 requests per hour.
// Stored as JSON files under data/ratelimit/resend_<md5(ip)>.json with
// atomic flock() read-modify-write via JsonStore.
// ---------------------------------------------------------------------------
$clientIp = ClientIp::get();
$rateLimitFile = __DIR__ . '/../data/ratelimit/resend_' . md5($clientIp) . '.json';
$store = new JsonStore($rateLimitFile);

$window = 3600; // 1 hour
$maxRequests = 3;
$now = time();

$store->mutate(function (array $data) use ($now, $window, $maxRequests, &$limited, &$retryAfter): array {
    // Prune stale timestamps.
    $attempts = array_values(array_filter(
        $data['attempts'] ?? [],
        fn($ts) => is_int($ts) && $ts > ($now - $window)
    ));

    if (count($attempts) >= $maxRequests) {
        $limited = true;
        $oldest = min($attempts);
        $retryAfter = max(1, $oldest + $window - $now);
        return $data;
    }

    $limited = false;
    $retryAfter = 0;
    $attempts[] = $now;
    return ['attempts' => $attempts];
});

if ($limited) {
    resendJsonResponse(false, 'Too many verification email requests. Please try again later.', 429, [
        'Retry-After' => (string) $retryAfter,
    ]);
}

// Anti-enumeration: the response is identical whether the email is unknown,
// already verified, or a verification email was actually sent.
$antiEnumerationMessage = 'If this email is registered, a verification link has been sent.';

try {
    $db = Database::getInstance();

    // Find unverified user
    $stmt = $db->prepare("
        SELECT id, email, email_verified
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Don't reveal if email exists or not.
        resendJsonResponse(true, $antiEnumerationMessage);
    }

    if ($user['email_verified']) {
        // Don't reveal that the email is already verified.
        resendJsonResponse(true, $antiEnumerationMessage);
    }

    // Generate new verification token
    $verificationToken = bin2hex(random_bytes(32));
    $verificationExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $updateStmt = $db->prepare("
        UPDATE users
        SET email_verification_token = ?,
            email_verification_expires = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$verificationToken, $verificationExpires, $user['id']]);

    // Send verification email
    $mailer = new Mailer();
    $emailSent = $mailer->sendVerificationEmail($email, $verificationToken);

    if ($emailSent) {
        resendJsonResponse(true, $antiEnumerationMessage);
    }

    error_log('Resend verification email failed for user id=' . $user['id']);
    resendJsonResponse(false, 'Failed to send email. Please try again.', 500);

} catch (Throwable $e) {
    error_log('Resend verification error: ' . $e->getMessage());
    resendJsonResponse(false, 'An error occurred', 500);
}
