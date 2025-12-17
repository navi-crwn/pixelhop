<?php
/**
 * PixelHop - OAuth Configuration Example
 * Copy this to oauth.php and update with your credentials
 */

return [
    'google' => [
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: 'your-google-client-id.apps.googleusercontent.com',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: 'your-google-client-secret',
        'redirect_uri' => 'https://your-domain.com/auth/google-callback.php',
        'scopes' => [
            'openid',
            'email',
            'profile',
        ],
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
    ],

    'smtp' => [
        'host' => getenv('MAIL_HOST') ?: 'smtp.example.com',
        'port' => (int) (getenv('MAIL_PORT') ?: 465),
        'username' => getenv('MAIL_USERNAME') ?: 'no-reply@example.com',
        'password' => getenv('MAIL_PASSWORD') ?: 'your-mail-password',
        'from_email' => 'no-reply@example.com',
        'from_name' => 'PixelHop',
        'encryption' => 'ssl',
    ],

    'turnstile' => [
        'site_key' => getenv('TURNSTILE_SITE_KEY') ?: 'your-turnstile-site-key',
        'secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: 'your-turnstile-secret-key',
    ],
];
