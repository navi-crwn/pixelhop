<?php
/**
 * PixelHop - Public Health Endpoint
 *
 * Uptime-monitoring endpoint (https://p.hel.ink/health.php). Intentionally
 * fast and lightweight: it never renders HTML, never loads session/auth
 * layers, and never prints paths, credentials, versions, or config values.
 *
 * Output shape (HTTP 200 = ok/degraded, 503 = down):
 * {
 *   "status": "ok|degraded|down",
 *   "checks": {"database":"pass","storage_r2":"pass","storage_contabo":"warn","disk":"pass","python_ai":"pass"},
 *   "disk_free_mb": 89000,
 *   "duration_ms": 123,
 *   "ts": "2026-09-11T11:30:00+00:00"
 * }
 */

declare(strict_types=1);

const HEALTH_DISK_WARN_MB = 3072;  // warn when free disk < 3 GB
const HEALTH_DISK_MIN_MB = 1024;   // fail when free disk < 1 GB

/**
 * Send the final JSON payload and stop.
 */
function health_respond(array $payload, int $httpCode): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Check database connectivity with a lightweight SELECT 1.
 *
 * Returns 'pass' on success and 'fail' on any error. No PDO/exception
 * details ever leave this function.
 */
function health_check_database(): string
{
    // Suppress engine warnings from helper internals (e.g. a missing
    // config/database.php in local checkouts). PDO exceptions are still
    // caught below; no path or DSN text is ever printed by this endpoint.
    set_error_handler(static function (): bool {
        return true;
    });

    try {
        require_once __DIR__ . '/includes/Database.php';
        $db = Database::getInstance();
        $stmt = $db->query('SELECT 1');
        return ($stmt !== false && (int) $stmt->fetchColumn() === 1) ? 'pass' : 'fail';
    } catch (Throwable $e) {
        error_log('health.php: database check failed');
        return 'fail';
    } finally {
        restore_error_handler();
    }
}

/**
 * Sign a minimal SigV4 GET/HEAD request and check the bucket endpoint.
 *
 * A HEAD request against the bucket root is the lightest possible
 * connectivity probe: S3 returns a small XML list result (or 403 for
 * missing ListBucket permission), both of which prove that the endpoint is
 * reachable and the SigV4 credential is accepted. Any HTTP response below
 * 500 counts as "provider reachable"; network/cURL errors and 5xx count as
 * failure. No object is downloaded.
 *
 * @param array<string,mixed> $providerConfig Provider block from config/s3.php.
 * @return array{0:string,1:bool} Check state and reachability boolean.
 */
function health_probe_s3(array $providerConfig): array
{
    $endpoint  = $providerConfig['endpoint'] ?? '';
    $bucket    = $providerConfig['bucket'] ?? '';
    $accessKey = $providerConfig['access_key'] ?? '';
    $secretKey = $providerConfig['secret_key'] ?? '';
    $region    = $providerConfig['region'] ?? 'auto';

    if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
        return ['fail', false];
    }

    $host = parse_url($endpoint, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return ['fail', false];
    }

    $url = rtrim($endpoint, '/') . '/' . $bucket . '/';

    $longDate = gmdate('Ymd\THis\Z');
    $shortDate = gmdate('Ymd');
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $shortDate . '/' . $region . '/s3/aws4_request';
    $payloadHash = hash('sha256', '');

    $canonicalUri = '/' . $bucket . '/';
    $canonicalQueryString = '';

    $headers = [
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $longDate,
    ];
    ksort($headers);

    $canonicalHeaders = '';
    $signedHeaders = [];
    foreach ($headers as $name => $value) {
        $canonicalHeaders .= strtolower($name) . ':' . trim($value) . "\n";
        $signedHeaders[] = strtolower($name);
    }
    $signedHeadersStr = implode(';', $signedHeaders);

    $canonicalRequest = "HEAD\n"
        . $canonicalUri . "\n"
        . $canonicalQueryString . "\n"
        . $canonicalHeaders . "\n"
        . $signedHeadersStr . "\n"
        . $payloadHash;

    $stringToSign = $algorithm . "\n"
        . $longDate . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = $algorithm . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeadersStr . ', Signature=' . $signature;

    if (!function_exists('curl_init')) {
        return ['fail', false];
    }

    $ch = curl_init();
    if ($ch === false) {
        return ['fail', false];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'HEAD',
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $longDate,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode === 0) {
        return ['fail', false];
    }

    if ($httpCode >= 500) {
        return ['fail', false];
    }

    return ['pass', true];
}

/**
 * Check disk free space for the root filesystem.
 *
 * Returns 'pass' (>= 3 GB), 'warn' (>= 1 GB and < 3 GB), or 'fail' (< 1 GB).
 */
function health_check_disk(): array
{
    $freeBytes = @disk_free_space('/');
    if ($freeBytes === false) {
        return ['fail', null];
    }

    $freeMb = (int) floor($freeBytes / 1024 / 1024);

    if ($freeMb < HEALTH_DISK_MIN_MB) {
        return ['fail', $freeMb];
    }

    if ($freeMb < HEALTH_DISK_WARN_MB) {
        return ['warn', $freeMb];
    }

    return ['pass', $freeMb];
}

/**
 * Check that the production Python venv interpreter exists and is executable.
 */
function health_check_python_ai(): string
{
    $python = '/var/www/pichost/python/venv/bin/python3';

    return is_executable($python) ? 'pass' : 'fail';
}

/**
 * Lightweight fixed-window rate limit (30 requests/minute/IP) using JsonStore.
 *
 * The counter file lives in data/ratelimit/health_<md5(ip)>.json. Fail-open:
 * if the store can't be read or written, the request is allowed through so
 * uptime monitoring is never blocked by a counter glitch.
 */
function health_rate_limit_exceeded(): bool
{
    try {
        require_once __DIR__ . '/includes/ClientIp.php';
        require_once __DIR__ . '/includes/JsonStore.php';

        $ip = ClientIp::get();
        $file = __DIR__ . '/data/ratelimit/health_' . md5($ip) . '.json';
        $store = new JsonStore($file);

        $now = time();
        $windowStart = $now - ($now % 60);
        $exceeded = false;

        // JsonStore::mutate() only persists when the mutator returns an
        // array, so mutate the array in place and signal "rate limited"
        // through a by-reference flag.
        $store->mutate(function (array $data) use ($windowStart, &$exceeded): array {
            $currentWindow = (int) ($data['window_start'] ?? 0);
            $count = (int) ($data['count'] ?? 0);

            // New minute: reset the fixed window.
            if ($currentWindow !== $windowStart) {
                $data['window_start'] = $windowStart;
                $data['count'] = 1;
                return $data;
            }

            $count++;
            $data['count'] = $count;
            $exceeded = $count > 30;

            return $data;
        });

        return $exceeded;
    } catch (Throwable $e) {
        // Fail-open: never block monitoring because the counter failed.
        error_log('health.php: rate-limit counter failed, failing open');
        return false;
    }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$healthStart = microtime(true);

if (health_rate_limit_exceeded()) {
    $retryAfter = 60 - (time() % 60);
    if ($retryAfter < 1) {
        $retryAfter = 1;
    }

    http_response_code(429);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Retry-After: ' . $retryAfter);
    echo json_encode([
        'status' => 'down',
        'checks' => [
            'database' => 'fail',
            'storage_r2' => 'fail',
            'storage_contabo' => 'fail',
            'disk' => 'fail',
            'python_ai' => 'fail',
        ],
        'duration_ms' => 0,
        'ts' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$checks = [
    'database' => health_check_database(),
    'storage_r2' => 'fail',
    'storage_contabo' => 'fail',
    'disk' => 'fail',
    'python_ai' => health_check_python_ai(),
];

$diskState = health_check_disk();
$checks['disk'] = $diskState[0];
$diskFreeMb = $diskState[1];

// Load S3 config once and probe both providers.
$storageConfigFile = __DIR__ . '/config/s3.php';
if (is_file($storageConfigFile)) {
    // Suppress engine warnings from the config include (e.g. a malformed
    // local s3.php). Throwables are caught below; no config values are shown.
    set_error_handler(static function (): bool {
        return true;
    });

    try {
        $storageConfig = require $storageConfigFile;
        if (is_array($storageConfig)) {
            $r2Config = is_array($storageConfig['r2'] ?? null) ? $storageConfig['r2'] : [];

            if (!empty($r2Config['enabled'])) {
                $checks['storage_r2'] = health_probe_s3($r2Config)[0];
            } else {
                // R2 is configured but intentionally disabled → not a
                // failure, report as warn so uptime monitors see degraded.
                $checks['storage_r2'] = 'warn';
            }

            $contaboConfig = is_array($storageConfig['s3'] ?? null) ? $storageConfig['s3'] : [];
            $checks['storage_contabo'] = health_probe_s3($contaboConfig)[0];
        } else {
            $checks['storage_r2'] = 'warn';
            $checks['storage_contabo'] = 'warn';
        }
    } catch (Throwable $e) {
        error_log('health.php: S3 config load failed');
        $checks['storage_r2'] = 'warn';
        $checks['storage_contabo'] = 'warn';
    } finally {
        restore_error_handler();
    }
} else {
    // No config in local/CI checkout (config/s3.php is gitignored).
    $checks['storage_r2'] = 'warn';
    $checks['storage_contabo'] = 'warn';
}

// Aggregate status:
// - down: database or either storage provider failed.
// - degraded: any warn, or any non-critical fail (disk/python).
// - ok: everything passes.
$criticalFail = $checks['database'] === 'fail'
    || $checks['storage_r2'] === 'fail'
    || $checks['storage_contabo'] === 'fail';

$status = 'ok';
$httpCode = 200;

if ($criticalFail) {
    $status = 'down';
    $httpCode = 503;
} elseif (in_array('warn', $checks, true) || in_array('fail', $checks, true)) {
    $status = 'degraded';
    $httpCode = 200;
}

$durationMs = (int) round((microtime(true) - $healthStart) * 1000);

health_respond([
    'status' => $status,
    'checks' => $checks,
    'disk_free_mb' => $diskFreeMb,
    'duration_ms' => $durationMs,
    'ts' => gmdate('c'),
], $httpCode);
