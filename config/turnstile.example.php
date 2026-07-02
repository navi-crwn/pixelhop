<?php
/**
 * PixelHop - Cloudflare Turnstile Configuration
 * Copy this file to turnstile.php and fill in your keys.
 *
 * Get your keys from https://dash.cloudflare.com/?to=/:account/turnstile
 *
 * SECURITY: Keep turnstile.php out of version control (it is git-ignored
 * alongside the other config/*.php secrets). If a secret key was ever
 * committed, rotate it in the Cloudflare dashboard before deploying.
 */

return [
    // Public site key (safe to expose in HTML)
    'site_key'   => 'YOUR_SITE_KEY_HERE',

    // Secret key (server-side only, never expose)
    'secret_key' => 'YOUR_SECRET_KEY_HERE',
];
