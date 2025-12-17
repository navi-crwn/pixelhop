<?php
/**
 * PixelHop - Google OAuth Initiate
 * Redirects user to Google for authentication
 */

session_start();
require_once __DIR__ . '/middleware.php';

// Redirect if already authenticated
if (isAuthenticated()) {
    header('Location: /dashboard.php');
    exit;
}

$config = require __DIR__ . '/../config/oauth.php';
$google = $config['google'];

// Generate state token for CSRF protection
$state = bin2hex(random_bytes(32));
$_SESSION['oauth_state'] = $state;

// Build authorization URL
$params = [
    'client_id' => $google['client_id'],
    'redirect_uri' => $google['redirect_uri'],
    'response_type' => 'code',
    'scope' => implode(' ', $google['scopes']),
    'state' => $state,
    'access_type' => 'offline',
    'prompt' => 'consent',
];

$authUrl = $google['auth_url'] . '?' . http_build_query($params);

header('Location: ' . $authUrl);
exit;
