<?php
/**
 * PixelHop - Google OAuth Initiate
 * Redirects user to Google for authentication
 */

require_once __DIR__ . '/../includes/bootstrap.php';
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
//
// No 'prompt' parameter: Google only shows the consent/account screen when it
// actually needs to (first authorization, or when scopes change). Returning
// users who have already granted access are signed in without being asked
// again. ('prompt=consent' would force the consent screen on every login.)
// 'access_type=online' since we don't store or use refresh tokens — the
// callback only consumes the access token.
$params = [
    'client_id' => $google['client_id'],
    'redirect_uri' => $google['redirect_uri'],
    'response_type' => 'code',
    'scope' => implode(' ', $google['scopes']),
    'state' => $state,
    'access_type' => 'online',
];

$authUrl = $google['auth_url'] . '?' . http_build_query($params);

header('Location: ' . $authUrl);
exit;
