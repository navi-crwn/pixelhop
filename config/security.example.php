<?php
/**
 * PixelHop - Security / trusted-proxy configuration
 * Copy to security.php to override defaults (optional).
 *
 * By default PixelHop trusts Cloudflare's published IP ranges as proxies and
 * reads the real client IP from CF-Connecting-IP / X-Forwarded-For only when
 * the request actually arrived from one of them.
 */

return [
    // Set false if the app is NOT behind a reverse proxy/CDN — then the direct
    // connection address (REMOTE_ADDR) is always used and headers are ignored.
    'trust_proxy_headers' => true,

    // CIDR ranges of proxies you trust to set forwarding headers.
    // Leave unset to use the built-in Cloudflare range list.
    // 'trusted_proxies' => ['10.0.0.0/8', '192.168.0.0/16'],
];
