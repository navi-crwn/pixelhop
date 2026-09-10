<?php
/**
 * PixelHop - Security & Storage Maintenance Cron
 * Run every hour: 0 * * * * php /var/www/pichost/cron/security_cleanup.php
 */

// D3-10: CLI boleh jalan tanpa key. Akses HTTP wajib menyertakan cron_key
// yang cocok dengan environment variable CRON_KEY.
if (php_sapi_name() !== 'cli') {
    $expectedKey = getenv('CRON_KEY') ?: '';
    $providedKey = $_GET['cron_key'] ?? '';

    if ($expectedKey === '' || !is_string($providedKey) || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        die('Forbidden');
    }
}

require_once __DIR__ . '/../includes/SecurityFirewall.php';
require_once __DIR__ . '/../includes/R2RateLimiter.php';

$log = function($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
};

$log("Starting security maintenance...");

// Cleanup firewall data
try {
    $firewall = new SecurityFirewall();
    $results = $firewall->cleanup();
    $log("Firewall cleanup: {$results['ip_requests']} requests, {$results['security_events']} events, {$results['expired_blocks']} expired blocks removed.");
} catch (Exception $e) {
    $log("Firewall cleanup error: " . $e->getMessage());
}

// Cleanup R2 rate limiter data
try {
    $rateLimiter = new R2RateLimiter();
    $deleted = $rateLimiter->cleanup();
    $log("R2 rate limiter cleanup: {$deleted} old records removed.");
} catch (Exception $e) {
    $log("R2 rate limiter cleanup error: " . $e->getMessage());
}

$log("Security maintenance complete.");
