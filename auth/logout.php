<?php
/**
 * PixelHop - User Logout
 * Destroys session and redirects to home
 */

require_once __DIR__ . '/middleware.php';

// Destroy session
destroyUserSession();

// Check if JSON response requested
$acceptsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

if ($acceptsJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully',
        'redirect' => '/'
    ]);
    exit;
}

// Redirect to home
header('Location: /');
exit;
