<?php
/**
 * PixelHop - Google OAuth Callback
 * Handles Google OAuth response
 */

session_start();
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/../includes/Database.php';

$config = require __DIR__ . '/../config/oauth.php';
$google = $config['google'];

// Lightweight debug logger (writes to temp/oauth_debug.log)
function oauthDebug(string $message): void {
    $logFile = __DIR__ . '/../temp/oauth_debug.log';
    $line = date('c') . ' google-callback: ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

// Error handling
function oauthError(string $message): void {
    $_SESSION['auth_error'] = $message;
    header('Location: /login.php');
    exit;
}

// Verify state token
try {
    $state = $_GET['state'] ?? '';
    if (empty($state) || $state !== ($_SESSION['oauth_state'] ?? '')) {
        oauthError('Invalid OAuth state. Please try again.');
    }
    unset($_SESSION['oauth_state']);

    // Check for error from Google
    if (isset($_GET['error'])) {
        oauthError('Google authentication failed: ' . ($_GET['error_description'] ?? $_GET['error']));
    }

    // Get authorization code
    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        oauthError('No authorization code received.');
    }

    oauthDebug('Exchanging code for token');

    // Exchange code for access token
    $tokenData = [
        'code' => $code,
        'client_id' => $google['client_id'],
        'client_secret' => $google['client_secret'],
        'redirect_uri' => $google['redirect_uri'],
        'grant_type' => 'authorization_code',
    ];

    $ch = curl_init($google['token_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($tokenData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);

    if ($error) {
        oauthDebug('Token curl error: ' . $error);
        error_log('Google OAuth token error: ' . $error);
        oauthError('Failed to authenticate with Google.');
    }

    $tokenResult = json_decode($response, true);
    if (!isset($tokenResult['access_token'])) {
        oauthDebug('Token response invalid: ' . $response);
        error_log('Google OAuth token response: ' . $response);
        oauthError('Invalid token response from Google.');
    }

    $accessToken = $tokenResult['access_token'];

    oauthDebug('Fetching userinfo');

    // Get user info
    $ch = curl_init($google['userinfo_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);

    if ($error) {
        oauthDebug('Userinfo curl error: ' . $error);
        error_log('Google userinfo error: ' . $error);
        oauthError('Failed to get user information from Google.');
    }

    $userInfo = json_decode($response, true);
    if (!isset($userInfo['email'])) {
        oauthDebug('Userinfo missing email: ' . $response);
        error_log('Google userinfo response: ' . $response);
        oauthError('Could not get email from Google.');
    }

    $email = $userInfo['email'];
    $googleId = $userInfo['sub'];
    $name = $userInfo['name'] ?? '';
    $picture = $userInfo['picture'] ?? '';

    oauthDebug('Userinfo ok for email=' . $email);

    // Check if user exists
    $existingUser = Database::fetchOne(
        'SELECT id, email, google_id, is_blocked, block_reason FROM users WHERE email = ? OR google_id = ?',
        [$email, $googleId]
    );

    if ($existingUser) {

        if ($existingUser['is_blocked']) {
            oauthError($existingUser['block_reason'] ?: 'Your account has been suspended.');
        }


        if (empty($existingUser['google_id'])) {
            Database::execute(
                'UPDATE users SET google_id = ?, avatar_url = ?, email_verified = 1, email_verified_at = NOW() WHERE id = ?',
                [$googleId, $picture, $existingUser['id']]
            );
        }

        $userId = $existingUser['id'];
        oauthDebug('Existing user id=' . $userId);
    } else {

        $defaultConfig = require __DIR__ . '/../config/s3.php';
        $storageLimit = $defaultConfig['storage']['default_limit'] ?? 262144000;


        $randomPassword = bin2hex(random_bytes(32));
        $passwordHash = password_hash($randomPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ]);

        Database::execute(
            'INSERT INTO users (email, password_hash, google_id, avatar_url, storage_limit, email_verified, email_verified_at, role, account_type, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), ?, ?, NOW())',
            [$email, $passwordHash, $googleId, $picture, $storageLimit, 'user', 'free']
        );

        $userId = Database::lastInsertId();


        $_SESSION['show_welcome'] = true;
        oauthDebug('Created user id=' . $userId);
    }

// Get full user data
$user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);

// Update last login timestamp
Database::execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$userId]);
oauthDebug('Login success id=' . $userId);

// Create session
$_SESSION['user_id'] = $userId;
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['user_account_type'] = $user['account_type'];
$_SESSION['logged_in_at'] = time();

// Regenerate session ID for security
session_regenerate_id(true);

// Redirect
$redirect = $_SESSION['redirect_after_login'] ?? '/dashboard.php';
unset($_SESSION['redirect_after_login']);

// Check if new user - show welcome
if ($_SESSION['show_welcome'] ?? false) {
    $redirect = '/member/welcome.php';
}

oauthDebug('Redirecting to ' . $redirect);

header('Location: ' . $redirect);
exit;

} catch (Throwable $e) {
    oauthDebug('Exception: ' . $e->getMessage());
    oauthDebug($e->getTraceAsString());
    error_log('Google callback exception: ' . $e->getMessage());
    oauthError('Internal server error. Please try again.');
}
